"""
Synthetic sales-history generator.

Stockwise has no real sales history yet, and a forecasting model needs
months of it. This script writes realistic past stock-out transactions
(plus regular restocks) for every product, so the models have something
to learn from. Every row it writes is marked source='synthetic'.

The demand for each product is built from patterns we choose on purpose,
so the evaluation can check whether a model actually recovers them:

    expected sales on a day = base level
                              × trend          (slow growth or decline over time)
                              × weekly pattern (e.g. busier Saturdays)
                              × payday effect  (month-end salary days)
                              × December bump  (festive season)
    actual sales            = a random count around that expectation (noise)

Current stock levels and batches are NOT changed: the history describes
how the shop got to where it is today.

Usage:
    python generate_history.py               # 540 days (about 18 months), seed 42
    python generate_history.py --days 730    # about 24 months
    python generate_history.py --remove      # delete the synthetic rows only
    python generate_history.py --end-date 2026-11-01   # history ending on a fixed day (reproduces a past run exactly)
"""
import argparse
from datetime import datetime, timedelta

import numpy as np
import pandas as pd

import db
from config import RESULTS_DIR

TRUTH_FILE = RESULTS_DIR / "true_demand.csv"

# Multipliers for Monday..Sunday per category (normalised to average 1 below).
WEEKLY = {
    "Food & Beverage": [0.90, 0.85, 0.90, 0.95, 1.15, 1.45, 1.10],
    "Apparel":         [0.80, 0.80, 0.85, 0.90, 1.10, 1.60, 1.25],
    "Electronics":     [0.95, 0.90, 0.95, 1.00, 1.10, 1.25, 0.95],
    "Health":          [1.10, 1.05, 1.00, 1.00, 1.00, 0.95, 0.80],
    "Tools":           [1.00, 1.00, 1.00, 1.00, 1.05, 1.30, 0.70],
    "Sporting Goods":  [0.85, 0.90, 0.95, 0.95, 1.05, 1.40, 1.20],
}
# Extra demand on payday days (25th of the month to the 2nd of the next).
PAYDAY = {"Food & Beverage": 1.30, "Apparel": 1.30, "Electronics": 1.40, "Health": 1.10,
          "Tools": 1.20, "Sporting Goods": 1.25}
# December (festive season) multiplier.
DECEMBER = {"Food & Beverage": 1.15, "Apparel": 1.35, "Electronics": 1.35, "Health": 1.00,
            "Tools": 1.10, "Sporting Goods": 1.30}
# Noise: smaller = more random day-to-day variation (gamma-Poisson shape).
NOISE_SHAPE = 4.0


def is_payday(d) -> bool:
    return d.day >= 25 or d.day <= 2


def expected_demand(dates, base, growth_per_year, category):
    """The 'true' average demand per day, before noise."""
    weekly = np.array(WEEKLY.get(category, [1] * 7), dtype=float)
    weekly = weekly / weekly.mean()
    n = len(dates)
    years_before_end = (np.arange(n) - (n - 1)) / 365.0          # 0 on the last day, negative before
    trend = np.maximum(0.2, 1 + growth_per_year * years_before_end)
    week = np.array([weekly[d.weekday()] for d in dates])
    payday = np.array([PAYDAY.get(category, 1.2) if is_payday(d) else 1.0 for d in dates])
    december = np.array([DECEMBER.get(category, 1.0) if d.month == 12 else 1.0 for d in dates])
    return base * trend * week * payday * december


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--days", type=int, default=540)
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--remove", action="store_true", help="only delete existing synthetic rows")
    ap.add_argument("--end-date", help="last day of the history, YYYY-MM-DD (default: yesterday)")
    args = ap.parse_args()

    rng = np.random.default_rng(args.seed)
    conn = db.connect()
    with conn.cursor() as cur:
        cur.execute("DELETE FROM transactions WHERE source = 'synthetic'")
        removed = cur.rowcount
    conn.commit()
    print(f"Removed {removed} existing synthetic transaction(s).")
    if args.remove:
        TRUTH_FILE.unlink(missing_ok=True)
        return

    end = datetime.strptime(args.end_date, "%Y-%m-%d").date() if args.end_date else db.yesterday()
    dates = [end - timedelta(days=args.days - 1 - i) for i in range(args.days)]
    products = db.load_products(conn)

    with conn.cursor() as cur:
        cur.execute("SELECT branch_id, MIN(id) AS uid FROM users WHERE role = 'employee' GROUP BY branch_id")
        clerk = {r["branch_id"]: r["uid"] for r in cur.fetchall()}

    rows = []
    truth = []    # the true expected demand, saved so the evaluation can show the best possible error
    print(f"\nGenerating {args.days} days ({dates[0]} to {dates[-1]}) for {len(products)} products:")
    for _, p in products.iterrows():
        base = p["low_stock_threshold"] * rng.uniform(0.18, 0.40)   # today's typical daily demand
        growth = rng.uniform(-0.10, 0.30)                          # change per year
        mu = expected_demand(dates, base, growth, p["category"])
        sold = rng.poisson(mu * rng.gamma(NOISE_SHAPE, 1 / NOISE_SHAPE, size=len(mu)))
        who = clerk.get(p["branch_id"])
        truth += [(int(p["id"]), d.isoformat(), round(float(m), 4)) for d, m in zip(dates, mu)]

        for d, qty in zip(dates, sold):
            # Split a day's sales into 1-4 separate stock-outs at random shop hours.
            parts = rng.integers(1, min(4, qty) + 1) if qty > 0 else 0
            if parts:
                cuts = np.sort(rng.choice(np.arange(1, qty), size=parts - 1, replace=False)) if parts > 1 else []
                for chunk in np.diff(np.concatenate([[0], cuts, [qty]])):
                    t = datetime.combine(d, datetime.min.time()) + timedelta(minutes=int(rng.integers(8 * 60, 19 * 60)))
                    rows.append((int(p["id"]), p["branch_id"], "stock_out", int(chunk), who, t))

        # A restock roughly every two weeks, sized to the next two weeks' demand.
        i = 0
        while i < len(dates):
            qty = int(round(mu[i:i + 14].sum() * 1.1))
            if qty > 0:
                t = datetime.combine(dates[i], datetime.min.time()) + timedelta(hours=7, minutes=30)
                rows.append((int(p["id"]), p["branch_id"], "stock_in", qty, who, t))
            i += 14 + int(rng.integers(0, 4))

        print(f"  {p['name'][:28]:<28} {p['branch_id']:<9} now ~{base:5.1f}/day, "
              f"trend {growth:+.0%}/yr, total sold {int(sold.sum())}")

    with conn.cursor() as cur:
        cur.executemany(
            "INSERT INTO transactions (product_id, branch_id, batch_id, type, quantity, performed_by, source, created_at) "
            "VALUES (%s, %s, NULL, %s, %s, %s, 'synthetic', %s)",
            rows,
        )
    conn.commit()
    print(f"\nInserted {len(rows)} synthetic transactions.")

    RESULTS_DIR.mkdir(exist_ok=True)
    pd.DataFrame(truth, columns=["product_id", "date", "expected"]).to_csv(TRUTH_FILE, index=False)
    print(f"Saved the true expected demand to {TRUTH_FILE.name} (used only by evaluate_models.py).")


if __name__ == "__main__":
    main()
