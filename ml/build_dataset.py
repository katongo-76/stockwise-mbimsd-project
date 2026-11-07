"""
Step 1 of the machine learning pipeline: BUILD THE DATASET.

    generate_history.py  ->  build_dataset.py  ->  train_model.py  ->  forecast_job.py
    (sales history)          (dataset files)       (model file)        (nightly forecasts)

Reads the sales history from the database (the transactions table) and
writes the dataset as CSV files you can open in Excel:

    ml/dataset/daily_sales.csv       raw data: units sold per product per day
    ml/dataset/training_dataset.csv  the training table: one row per
                                     (product, forecast day, days ahead) with
                                     the 14 input features and the answer
    ml/dataset/products.csv          the products, with branch and category
    ml/dataset/README.txt            what every column means

train_model.py then trains the model from training_dataset.csv.

Run (from the ml folder, with MySQL running):
    py build_dataset.py
"""
from datetime import datetime
from pathlib import Path

import numpy as np
import pandas as pd

import db
from config import HORIZON_DAYS, ML_DIR
from models import FEATURES, training_rows

OUT = ML_DIR / "dataset"

README = """Stockwise forecasting dataset
=============================

All sales in this dataset come from the transactions table. Rows marked
source='synthetic' were created by generate_history.py (about 18 months of
realistic sales with a trend, weekly pattern, payday effect, December effect
and random noise). Real sales recorded in the app are included too.

daily_sales.csv
  product_id, product, branch, category   which product
  date                                    the day
  units_sold                              units sold that day (0 if none)

training_dataset.csv  (exactly what the random forest learns from)
  One row = "standing on origin_date, predict sales h days later".
  product_id, product        which product
  origin_date                the last day the model is allowed to see
  target_date                the day being predicted (origin_date + h)
  h                          days ahead, 1 to 14
  dow                        weekday of target_date (0 = Monday ... 6 = Sunday)
  is_weekend                 1 if Saturday or Sunday
  day_of_month               1 to 31
  is_payday                  1 if between the 25th and the 2nd of the next month
  month                      1 to 12
  is_december                1 if December
  mean7_ratio                last 7 days' average sales / 28-day average
  mean14_ratio               last 14 days' average / 28-day average
  trend_ratio                28-day average / 90-day average (above 1 = growing)
  last_ratio                 sales on origin_date / 28-day average
  same_dow_ratio             average of the last 4 sales on the target's weekday / 28-day average
  std28_ratio                how jumpy sales were in the last 28 days / 28-day average
  payday_ratio               this product's payday sales / non-payday sales (last 90 days)
  scale                      the product's 28-day average (units/day) at origin_date
  target_ratio               THE ANSWER the model learns: actual sales on target_date / scale
  target_units               the same answer in units (target_ratio x scale)

The model is given the 14 feature columns (h ... payday_ratio) and learns to
predict target_ratio. Its prediction is multiplied by scale to get units.
Every feature uses only sales up to origin_date, never after it.
"""


def main():
    import argparse
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--end-date", help="last day of sales to include, YYYY-MM-DD (default: yesterday)")
    args = ap.parse_args()
    OUT.mkdir(exist_ok=True)
    conn = db.connect()
    products = db.load_products(conn)
    end = datetime.strptime(args.end_date, "%Y-%m-%d").date() if args.end_date else db.yesterday()
    series = db.load_daily_sales(conn, products["id"].tolist(), end)
    conn.close()

    info = products.set_index("id")
    name_col = "name"
    branch_col = "branch_id" if "branch_id" in info.columns else None

    products.to_csv(OUT / "products.csv", index=False)

    daily = []
    training = []
    for pid, y in series.items():
        if y.empty:
            continue
        meta = info.loc[pid]
        daily.append(pd.DataFrame({
            "product_id": pid,
            "product": meta[name_col],
            "branch": meta[branch_col] if branch_col else "",
            "category": meta.get("category", ""),
            "date": y.index.date,
            "units_sold": y.values.astype(int),
        }))

        frame = training_rows(y, HORIZON_DAYS)
        if frame.empty:
            continue
        frame.insert(0, "product", meta[name_col])
        frame.insert(0, "product_id", pid)
        frame["target_units"] = frame["target_units"].astype(int)
        training.append(frame)

    daily = pd.concat(daily, ignore_index=True)
    daily.to_csv(OUT / "daily_sales.csv", index=False)
    training = pd.concat(training, ignore_index=True).round(4)
    training.sort_values(["product_id", "origin_date", "h"]).to_csv(OUT / "training_dataset.csv", index=False)
    (OUT / "README.txt").write_text(README, encoding="utf-8")

    print(f"daily_sales.csv       {len(daily):>7,} rows  ({daily['product_id'].nunique()} products, "
          f"{daily['date'].min()} to {daily['date'].max()})")
    print(f"training_dataset.csv  {len(training):>7,} rows  x {len(FEATURES)} features")
    print(f"Written to {OUT}")
    print("Next step: py train_model.py")


if __name__ == "__main__":
    main()
