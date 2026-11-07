"""
Nightly forecast job.

Step 3 of the machine learning pipeline: USE THE MODEL.

    generate_history.py  ->  build_dataset.py  ->  train_model.py  ->  forecast_job.py
    (sales history)          (dataset files)       (model file)        (nightly forecasts)

Run once a day (Windows Task Scheduler on the XAMPP PC, or cron on a server),
ideally after closing time. It does NOT train anything; it:

  1. loads the trained model from ml/model/demand_model.joblib (made by train_model.py)
  2. reads every product's daily sales history from `transactions`
  3. forecasts each product's sales for the next 14 days
  4. works out days until stockout, safety stock, reorder point,
     suggested order quantity and a status
  5. replays soonest-expiry-first selling to find stock likely to expire unsold
  6. replaces the contents of `forecasts`, `forecast_daily` and
     `forecast_batch_risk` in one database transaction

The PHP app only reads those tables; it never runs Python.

Usage:
    python forecast_job.py                       # model from .env (FORECAST_MODEL, default auto)
    python forecast_job.py --model holt_winters  # override for this run
"""
import argparse
import logging
import time
from datetime import date, datetime, timedelta

import numpy as np

import db
import inventory
from config import (COVER_DAYS, HORIZON_DAYS, LEAD_TIME_DAYS, MIN_HISTORY_DAYS, ML_DIR, MODEL,
                    SERVICE_Z)
from models import ALL_MODELS, MovingAverage, RandomForest

LOG_DIR = ML_DIR / "logs"
MODEL_FILE = ML_DIR / "model" / "demand_model.joblib"


def load_model(requested: str):
    """
    'auto' or 'random_forest': load the trained model file made by train_model.py.
    If it is missing, fall back to Holt-Winters (which needs no training file)
    and say so in the log. 'holt_winters' / 'moving_average' / 'seasonal_naive'
    can also be chosen directly in .env (FORECAST_MODEL).
    """
    if requested not in ("auto", *ALL_MODELS):
        raise SystemExit(f"Unknown model '{requested}'. Choose from: auto, {', '.join(ALL_MODELS)}")
    if requested in ("auto", "random_forest"):
        if MODEL_FILE.is_file():
            try:
                rf = RandomForest.load(MODEL_FILE)
            except Exception as e:      # e.g. made with a different scikit-learn version
                logging.info(f"Could not load {MODEL_FILE.name} ({e}). Run train_model.bat to retrain it on this PC. "
                             "Using Holt-Winters until then.")
                return "holt_winters", ALL_MODELS["holt_winters"]()
            i = rf.info
            logging.info(f"Loaded model {MODEL_FILE.name}: trained {i.get('trained_at', '?')} on {i.get('rows', '?'):,} rows "
                         f"(sales up to {i.get('data_to', '?')}); test MAE "
                         f"{i.get('test', {}).get('random_forest', {}).get('mae_units_per_day', '?')} units/day.")
            trained = i.get("data_to")
            if trained and (date.today() - date.fromisoformat(trained)).days > 45:
                logging.info("  Note: the model is more than 45 days old. Run train_model.bat to retrain it on recent sales.")
            return "random_forest", rf
        logging.info(f"No trained model at {MODEL_FILE}. Run build_dataset.py and train_model.py "
                     "(or train_model.bat). Using Holt-Winters until then.")
        return "holt_winters", ALL_MODELS["holt_winters"]()
    return requested, ALL_MODELS[requested]()


def setup_logging():
    LOG_DIR.mkdir(exist_ok=True)
    logging.basicConfig(
        level=logging.INFO, format="%(asctime)s %(message)s", datefmt="%Y-%m-%d %H:%M:%S",
        handlers=[logging.FileHandler(LOG_DIR / "forecast_job.log", encoding="utf-8"), logging.StreamHandler()],
    )


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--model", default=MODEL)
    args = ap.parse_args()
    setup_logging()
    started = time.time()

    today = date.today()
    origin = today - timedelta(days=1)           # last complete day of sales
    generated_at = datetime.now().replace(microsecond=0)

    conn = db.connect()
    products = db.load_products(conn)
    if products.empty:
        logging.info("No products, nothing to forecast.")
        return
    batches = db.load_batches(conn)
    series = db.load_daily_sales(conn, products["id"].tolist(), origin)

    model_key, model = load_model(args.model)
    fallback = MovingAverage()
    logging.info(f"Forecasting {len(products)} products with {model_key}; sales history up to {origin}.")

    summary_rows, daily_rows, risk_rows = [], [], []
    for _, p in products.iterrows():
        pid = int(p["id"])
        history = series.get(pid)
        n_days = 0 if history is None else len(history)

        if n_days == 0:
            used, daily = "no_history", np.zeros(HORIZON_DAYS)
        elif n_days < MIN_HISTORY_DAYS or (hasattr(model, "can_predict") and not model.can_predict(history)):
            used, daily = "moving_average", fallback.predict(history, HORIZON_DAYS)
        else:
            used, daily = model_key, model.predict(history, HORIZON_DAYS)

        demand_std = float(history.iloc[-28:].std()) if n_days >= 2 else 0.0
        demand_std = 0.0 if np.isnan(demand_std) else demand_std
        avg = float(daily.mean())

        own = batches[batches["product_id"] == pid]
        batch_list = [(int(b.id), int(b.quantity_remaining), b.expiry_date) for b in own.itertuples()]
        sellable = max(0, int(p["current_quantity"]) - inventory.expired_quantity(batch_list, today))
        unbatched = max(0, int(p["current_quantity"]) - int(sum(q for _, q, _ in batch_list)))

        # One replay of the forecast against the shelf gives both answers, so a batch that
        # expires before it sells also brings the stockout date forward.
        days_left, risk = inventory.simulate_stock(batch_list, unbatched, daily, today)
        safety = inventory.safety_stock(demand_std, LEAD_TIME_DAYS, SERVICE_Z)
        rop = inventory.reorder_point(avg, LEAD_TIME_DAYS, safety)
        # Units that will expire before they sell do not count towards covering demand.
        will_sell = max(0, sellable - sum(at_risk for _, at_risk in risk.values()))
        runs_out_early = days_left is not None and days_left <= LEAD_TIME_DAYS
        order = inventory.suggested_order(will_sell, avg, LEAD_TIME_DAYS, COVER_DAYS, safety, rop, runs_out_early)
        status = inventory.stock_status(avg, days_left, sellable, rop, LEAD_TIME_DAYS)
        if status == "no_demand":
            order = 0
        stockout_date = (today + timedelta(days=int(days_left))) if days_left is not None and days_left < 365 else None

        summary_rows.append((pid, p["branch_id"], generated_at, used, n_days, round(avg, 2), round(float(daily.sum()), 2),
                             round(demand_std, 2), sellable, days_left, stockout_date, safety, rop, order, status))
        for h, qty in enumerate(daily):
            daily_rows.append((pid, today + timedelta(days=h), round(float(qty), 2)))
        if status == "no_demand":
            # No forecast to judge by: only report batches that have already expired.
            risk = {bid: r for bid, r in risk.items() if r[0] == 0 and any(
                b[0] == bid and b[2] is not None and b[2] < today for b in batch_list)}
        for bid, (sold, at_risk) in risk.items():
            b = own[own["id"] == bid].iloc[0]
            risk_rows.append((bid, pid, p["branch_id"], b["expiry_date"], int(b["quantity_remaining"]), sold, at_risk,
                              generated_at))

        logging.info(f"  {p['name'][:26]:<26} {used:<15} avg {avg:6.2f}/day  on hand {sellable:>4}  "
                     f"days left {'-' if days_left is None else days_left:>5}  reorder at {rop:>4}  "
                     f"order {order:>4}  {status}")

    with conn.cursor() as cur:
        cur.execute("DELETE FROM forecast_batch_risk")
        cur.execute("DELETE FROM forecast_daily")
        cur.execute("DELETE FROM forecasts")
        cur.executemany(
            "INSERT INTO forecasts (product_id, branch_id, generated_at, model, history_days, avg_daily_demand, "
            "forecast_14d, demand_std, sellable_quantity, days_until_stockout, stockout_date, safety_stock, "
            "reorder_point, suggested_order_qty, status) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
            summary_rows)
        cur.executemany("INSERT INTO forecast_daily (product_id, forecast_date, quantity) VALUES (%s,%s,%s)", daily_rows)
        if risk_rows:
            cur.executemany(
                "INSERT INTO forecast_batch_risk (batch_id, product_id, branch_id, expiry_date, quantity_remaining, "
                "expected_sold, at_risk_quantity, generated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)", risk_rows)
    conn.commit()

    counts = {s: sum(1 for r in summary_rows if r[-1] == s) for s in ("critical", "reorder", "ok", "no_demand")}
    at_risk = sum(r[6] for r in risk_rows)
    logging.info(f"Done in {time.time() - started:.1f}s: {counts['critical']} critical, {counts['reorder']} to reorder, "
                 f"{counts['ok']} ok, {counts['no_demand']} no demand; {at_risk} unit(s) at risk of expiring unsold.")


if __name__ == "__main__":
    try:
        main()
    except Exception:
        logging.exception("Forecast job failed; the previous forecasts were left unchanged.")
        raise
