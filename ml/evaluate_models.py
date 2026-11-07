"""
Model comparison (backtest).

Answers: "is the machine-learning model actually better than a simple
average?" It replays the last TEST_DAYS days as if they were the future:

  every 7 days in the test period (an "origin"):
      give each model the sales history up to that day only
      ask it for the next 14 days
      compare with what really sold

The random forest is re-trained every 28 days using only data before the
origin (the same way the nightly job would have behaved). Nothing from
after an origin is ever used to forecast it.

Writes to ml/results/:
  comparison.csv      one row per model: MAE, RMSE, bias, 14-day-total error
  by_category.csv     MAE per model per category
  summary.md          the results in words and tables, ready for the report
  best_model.txt      the model with the lowest MAE (the one train_model.py trains for the system)
  mae_by_model.png    chart of MAE per model
  forecast_example.png  actual sales vs forecasts for one product

Usage:
    python evaluate_models.py              # 182-day test period
    python evaluate_models.py --test-days 91
"""
import argparse
import time
from datetime import datetime

import numpy as np
import pandas as pd

import charts
import db
from config import HORIZON_DAYS, RESULTS_DIR
from models import ALL_MODELS, LOOKBACK, RandomForest

RETRAIN_EVERY = 28
TRUTH_FILE = RESULTS_DIR / "true_demand.csv"


def oracle_error(df: pd.DataFrame):
    """
    The error a perfect model would still make: forecast = the true expected
    demand the generator used (known only because the data is synthetic).
    What is left is pure random noise, which no model can predict.
    Returns (daily MAE, 14-day-total error) or None if the file is missing or stale.
    """
    if not TRUTH_FILE.is_file():
        return None
    truth = pd.read_csv(TRUTH_FILE, parse_dates=["date"])
    base = df[df["model"] == "moving_average"].copy()
    base["date"] = base["origin"] + pd.to_timedelta(base["h"], unit="D")
    m = base.merge(truth, on=["product_id", "date"], how="inner")
    if len(m) < 0.95 * len(base):
        return None
    totals = m.groupby(["product_id", "origin"])[["actual", "expected"]].sum()
    return (m["expected"] - m["actual"]).abs().mean(), (totals["expected"] - totals["actual"]).abs().mean()


def backtest(series: dict, products: pd.DataFrame, test_days: int, horizon: int) -> pd.DataFrame:
    end = max(s.index[-1] for s in series.values() if len(s))
    first_origin = end - pd.Timedelta(days=test_days)
    origins = pd.date_range(first_origin, end - pd.Timedelta(days=horizon), freq="7D")
    category = dict(zip(products["id"], products["category"]))
    name = dict(zip(products["id"], products["name"]))

    records = []
    for key, cls in ALL_MODELS.items():
        model = cls()
        trained_at = None
        started = time.time()
        for origin in origins:
            if isinstance(model, RandomForest) and (trained_at is None or (origin - trained_at).days >= RETRAIN_EVERY):
                model.fit(series, origin)
                trained_at = origin
            for pid, y in series.items():
                history = y.loc[:origin]
                if len(history) < LOOKBACK:
                    continue
                actual = y.loc[origin + pd.Timedelta(days=1): origin + pd.Timedelta(days=horizon)].to_numpy()
                forecast = model.predict(history, horizon)
                for h, (a, f) in enumerate(zip(actual, forecast), start=1):
                    records.append((key, pid, name[pid], category[pid], origin, h, a, f))
        print(f"  {key:<16} {len(origins)} origins x {len(series)} products  ({time.time() - started:.1f}s)")

    return pd.DataFrame(records, columns=["model", "product_id", "product", "category", "origin", "h", "actual", "forecast"])


def summarise(df: pd.DataFrame) -> pd.DataFrame:
    df = df.assign(err=df["forecast"] - df["actual"])
    totals = df.groupby(["model", "product_id", "origin"])[["actual", "forecast"]].sum()
    total_err = (totals["forecast"] - totals["actual"]).abs().groupby("model").mean()
    out = df.groupby("model").agg(
        mae=("err", lambda e: e.abs().mean()),
        rmse=("err", lambda e: np.sqrt((e ** 2).mean())),
        bias=("err", "mean"),
        mae_days_1_7=("err", lambda e: e[df.loc[e.index, "h"] <= 7].abs().mean()),
        mae_days_8_14=("err", lambda e: e[df.loc[e.index, "h"] > 7].abs().mean()),
    )
    out["mae_14_day_total"] = total_err
    base = out.loc["moving_average", "mae"]
    out["vs_baseline_pct"] = (out["mae"] - base) / base * 100
    out["label"] = [ALL_MODELS[k].label for k in out.index]
    return out.sort_values("mae")


def write_summary(path, comp, by_cat, df, test_days, horizon, avg_sales, floor):
    best = comp.index[0]
    base = comp.loc["moving_average"]
    lines = [
        "# Stockwise forecasting: model comparison",
        "",
        f"Generated {datetime.now():%Y-%m-%d %H:%M}. Test period: the last {test_days} days, "
        f"forecasting {horizon} days ahead from a new origin every 7 days "
        f"({df['origin'].nunique()} origins x {df['product_id'].nunique()} products = "
        f"{len(df) // df['model'].nunique():,} daily forecasts per model). "
        f"Average actual sales in the test period: {avg_sales:.2f} units per product per day.",
        "",
        f"**Result:** {ALL_MODELS[best].label} had the lowest error, MAE {comp.loc[best, 'mae']:.2f} units/day, "
        f"{-comp.loc[best, 'vs_baseline_pct']:.0f}% lower than the 7-day moving-average baseline "
        f"(MAE {base['mae']:.2f}).",
        "",
    ]
    if floor:
        closed = (base["mae"] - comp.loc[best, "mae"]) / (base["mae"] - floor[0]) * 100
        total_cut = (1 - comp.loc[best, "mae_14_day_total"] / base["mae_14_day_total"]) * 100
        lines += [
            f"A perfect model that knew the true average demand would still have MAE {floor[0]:.2f}, because "
            f"day-to-day sales are partly random. So the most any model could gain over the baseline is "
            f"{base['mae'] - floor[0]:.2f} units/day; {ALL_MODELS[best].label.split(' (')[0]} gains "
            f"{base['mae'] - comp.loc[best, 'mae']:.2f}, closing **{closed:.0f}% of that gap**. "
            f"On the 14-day total, which drives stockout and reorder decisions, its error is "
            f"**{total_cut:.0f}% lower** than the baseline ({comp.loc[best, 'mae_14_day_total']:.1f} vs "
            f"{base['mae_14_day_total']:.1f} units; best possible {floor[1]:.1f}).",
            "",
        ]
    lines += [
        "| Model | MAE (units/day) | vs baseline | RMSE | Bias | MAE days 1-7 | MAE days 8-14 | Error of 14-day total |",
        "|---|---|---|---|---|---|---|---|",
    ]
    for key, r in comp.iterrows():
        lines.append(f"| {r['label']} | {r['mae']:.2f} | {r['vs_baseline_pct']:+.0f}% | {r['rmse']:.2f} | "
                     f"{r['bias']:+.2f} | {r['mae_days_1_7']:.2f} | {r['mae_days_8_14']:.2f} | {r['mae_14_day_total']:.1f} |")
    if floor:
        lines += [f"| *Perfect model (true average known, synthetic data only)* | *{floor[0]:.2f}* | "
                  f"*{(floor[0] - base['mae']) / base['mae'] * 100:+.0f}%* | | | | | *{floor[1]:.1f}* |"]
    lines += [
        "",
        "- **MAE**: average size of the daily error, in units. Lower is better.",
        "- **vs baseline**: change in MAE compared with the 7-day moving average (negative = better).",
        "- **Bias**: average of (forecast - actual); positive means the model over-forecasts.",
        "- **Error of 14-day total**: how far off the total for the next 14 days was. "
        "This is what drives the days-until-stockout and reorder figures.",
        "- **Perfect model**: forecasts the exact average the generator used. Its remaining error is pure "
        "day-to-day randomness that no model can predict, so it marks the floor: a model's real gain is how "
        "much of the gap between the baseline and this floor it closes.",
        "",
        "## MAE by category",
        "",
        "| Category | " + " | ".join(ALL_MODELS[k].label.split(" (")[0] for k in by_cat.columns) + " |",
        "|---|" + "---|" * len(by_cat.columns),
    ]
    for cat, r in by_cat.iterrows():
        lines.append(f"| {cat} | " + " | ".join(f"{v:.2f}" for v in r) + " |")
    lines += [
        "",
        "## Note on the data",
        "",
        "The sales history is synthetic (ml/generate_history.py): built with a trend, a weekly pattern, "
        "a month-end payday effect, a December bump and random noise. Because these patterns were planted "
        "on purpose, the comparison shows whether each model can recover them. Results on real shop data "
        "will differ and should be re-checked once real history exists.",
    ]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--test-days", type=int, default=182)
    ap.add_argument("--end-date", help="last day of sales to use, YYYY-MM-DD (default: yesterday)")
    args = ap.parse_args()

    conn = db.connect()
    products = db.load_products(conn)
    end = datetime.strptime(args.end_date, "%Y-%m-%d").date() if args.end_date else db.yesterday()
    series = db.load_daily_sales(conn, products["id"].tolist(), end)
    series = {pid: s for pid, s in series.items() if len(s) >= LOOKBACK + args.test_days}
    if not series:
        raise SystemExit("Not enough sales history. Run generate_history.py first.")

    print(f"Backtesting {len(ALL_MODELS)} models on {len(series)} products:")
    df = backtest(series, products, args.test_days, HORIZON_DAYS)
    comp = summarise(df)
    by_cat = (df.assign(ae=(df["forecast"] - df["actual"]).abs())
                .pivot_table(index="category", columns="model", values="ae", aggfunc="mean")[comp.index])

    RESULTS_DIR.mkdir(exist_ok=True)
    comp.round(3).to_csv(RESULTS_DIR / "comparison.csv")
    by_cat.round(3).to_csv(RESULTS_DIR / "by_category.csv")
    (RESULTS_DIR / "best_model.txt").write_text(comp.index[0] + "\n", encoding="utf-8")
    avg_sales = df[df["model"] == comp.index[0]]["actual"].mean()
    floor = oracle_error(df)
    write_summary(RESULTS_DIR / "summary.md", comp, by_cat, df, args.test_days, HORIZON_DAYS, avg_sales, floor)
    charts.mae_chart(comp, RESULTS_DIR / "mae_by_model.png", floor)
    charts.example_chart(series, df, RESULTS_DIR / "forecast_example.png")

    print("\n" + comp[["mae", "vs_baseline_pct", "rmse", "bias", "mae_14_day_total"]].round(2).to_string())
    if floor:
        print(f"Perfect-model floor: MAE {floor[0]:.2f}, 14-day total error {floor[1]:.1f}")
    print(f"\nBest model: {comp.index[0]}. Results written to {RESULTS_DIR}")


if __name__ == "__main__":
    main()
