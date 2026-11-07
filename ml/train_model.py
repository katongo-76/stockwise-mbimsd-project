"""
Step 2 of the machine learning pipeline: TRAIN THE MODEL.

    generate_history.py  ->  build_dataset.py  ->  train_model.py  ->  forecast_job.py
    (sales history)          (dataset files)       (model file)        (nightly forecasts)

The usual machine learning recipe:

  1. LOAD the dataset           ml/dataset/training_dataset.csv
  2. SPLIT it by date           training set = older rows
                                test set     = the most recent TEST_DAYS days,
                                               which the model never sees while training
  3. TRAIN a random forest      on the training set (14 features -> sales ratio)
  4. TEST it                    predict the test set and compare with what really sold,
                                against a simple 7-day-average baseline
  5. SAVE the model             retrain on ALL rows (the newest sales matter most)
                                and save it to ml/model/demand_model.joblib

Files written to ml/model/:
  demand_model.joblib      the trained model (loaded by forecast_job.py every night)
  model_info.json          when it was trained, on how much data, and its test scores
  test_predictions.csv     every test row: actual sales, model prediction, baseline
  feature_importance.csv   how much the model relied on each feature
  feature_importance.png   the same as a chart

Retrain when there is a month or so of new sales (or schedule train_model.bat monthly).

Usage (from the ml folder):
    py train_model.py
    py train_model.py --test-days 91
"""
import argparse
import json
import time
from datetime import datetime

import numpy as np
import pandas as pd

from config import ML_DIR
from models import FEATURES, RandomForest

DATASET = ML_DIR / "dataset" / "training_dataset.csv"
MODEL_DIR = ML_DIR / "model"
MODEL_FILE = MODEL_DIR / "demand_model.joblib"


def scores(df: pd.DataFrame, col: str) -> dict:
    """Daily MAE and the error of the 14-day total, for one prediction column."""
    mae = float((df[col] - df["actual"]).abs().mean())
    totals = df.groupby(["product_id", "origin_date"])[[col, "actual"]].sum()
    total_err = float((totals[col] - totals["actual"]).abs().mean())
    return {"mae_units_per_day": round(mae, 3), "error_14_day_total": round(total_err, 2)}


def importance_chart(imp: pd.DataFrame, path) -> None:
    try:
        import matplotlib
        matplotlib.use("Agg")
        import matplotlib.pyplot as plt
    except ImportError:
        return
    imp = imp.sort_values("importance")
    fig, ax = plt.subplots(figsize=(8, 5.5), dpi=130)
    ax.barh(imp["feature"], imp["importance"], color="#2b7bd6")
    ax.set_xlabel("Share of the model's decisions (importance)")
    ax.set_title("What the random forest relies on", loc="left", fontweight="bold")
    for side in ("top", "right"):
        ax.spines[side].set_visible(False)
    fig.tight_layout()
    fig.savefig(path)
    plt.close(fig)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--test-days", type=int, default=91, help="days at the end held back for testing (default 91)")
    args = ap.parse_args()
    started = time.time()

    # 1. LOAD
    if not DATASET.is_file():
        raise SystemExit("No dataset found. Run  py build_dataset.py  first.")
    data = pd.read_csv(DATASET, parse_dates=["origin_date", "target_date"])
    print(f"1. Loaded {len(data):,} rows from {DATASET.name} "
          f"({data['product_id'].nunique()} products, {data['origin_date'].min():%d %b %Y} to {data['target_date'].max():%d %b %Y})")

    # 2. SPLIT by date: train on the past, test on the most recent period.
    cutoff = data["target_date"].max() - pd.Timedelta(days=args.test_days - 1)
    train = data[data["target_date"] < cutoff]
    test = data[data["origin_date"] >= cutoff - pd.Timedelta(days=1)]
    print(f"2. Split at {cutoff:%d %b %Y}: {len(train):,} training rows (before), {len(test):,} test rows (after). "
          f"{len(data) - len(train) - len(test):,} rows that straddle the split are left out, "
          "so no test-period sales reach the training set.")

    # 3. TRAIN
    rf = RandomForest()
    rf.fit_rows(train[FEATURES], train["target_ratio"])
    print(f"3. Trained a random forest of {rf.model.n_estimators} trees on {len(train):,} rows x {len(FEATURES)} features.")

    # 4. TEST against a 7-day moving average (mean7_ratio x scale = average of the last 7 days)
    result = test[["product_id", "product", "origin_date", "target_date", "h"]].copy()
    result["actual"] = test["target_units"].to_numpy()
    result["predicted"] = rf.predict_rows(test).round(2)
    result["baseline_7day_avg"] = (test["mean7_ratio"] * test["scale"]).round(2).to_numpy()
    model_s, base_s = scores(result, "predicted"), scores(result, "baseline_7day_avg")
    better = 100 * (1 - model_s["mae_units_per_day"] / base_s["mae_units_per_day"])
    better_total = 100 * (1 - model_s["error_14_day_total"] / base_s["error_14_day_total"])
    print(f"4. Test on the last {args.test_days} days ({len(result):,} predictions it had never seen):")
    print(f"     random forest       MAE {model_s['mae_units_per_day']:.2f} units/day   14-day total error {model_s['error_14_day_total']:.1f}")
    print(f"     7-day average       MAE {base_s['mae_units_per_day']:.2f} units/day   14-day total error {base_s['error_14_day_total']:.1f}")
    print(f"     -> {better:.0f}% lower daily error, {better_total:.0f}% lower 14-day total error than the baseline")

    MODEL_DIR.mkdir(exist_ok=True)
    result.to_csv(MODEL_DIR / "test_predictions.csv", index=False, date_format="%Y-%m-%d")
    imp = pd.DataFrame({"feature": FEATURES, "importance": rf.model.feature_importances_.round(4)}) \
        .sort_values("importance", ascending=False)
    imp.to_csv(MODEL_DIR / "feature_importance.csv", index=False)
    importance_chart(imp, MODEL_DIR / "feature_importance.png")

    # 5. SAVE: retrain on everything, then save the model file and its description.
    final = RandomForest()
    final.fit_rows(data[FEATURES], data["target_ratio"])
    info = {
        "model": "RandomForestRegressor (scikit-learn), global model for all products",
        "trained_at": datetime.now().strftime("%Y-%m-%d %H:%M"),
        "dataset": str(DATASET.relative_to(ML_DIR.parent)),
        "data_from": f"{data['origin_date'].min():%Y-%m-%d}",
        "data_to": f"{data['target_date'].max():%Y-%m-%d}",
        "rows": int(len(data)),
        "products": int(data["product_id"].nunique()),
        "features": FEATURES,
        "target": "target_ratio = units sold on target_date / product's 28-day average (scale)",
        "settings": {"n_estimators": final.model.n_estimators, "min_samples_leaf": final.model.min_samples_leaf,
                     "max_features": final.model.max_features, "random_state": final.model.random_state},
        "test": {
            "test_days": args.test_days, "split_date": f"{cutoff:%Y-%m-%d}",
            "train_rows": int(len(train)), "test_rows": int(len(test)),
            "random_forest": model_s, "baseline_7_day_average": base_s,
            "improvement_daily_pct": round(better, 1), "improvement_14_day_total_pct": round(better_total, 1),
        },
    }
    final.save(MODEL_FILE, info)
    (MODEL_DIR / "model_info.json").write_text(json.dumps(info, indent=2), encoding="utf-8")
    size = MODEL_FILE.stat().st_size / 1e6
    print(f"5. Retrained on all {len(data):,} rows and saved {MODEL_FILE.relative_to(ML_DIR.parent)} ({size:.1f} MB).")
    print(f"Done in {time.time() - started:.0f}s. Next step: py forecast_job.py")


if __name__ == "__main__":
    main()
