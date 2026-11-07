# Stockwise ML — demand forecasting

Stockwise learns how fast each product sells and predicts the next 14 days of sales per product, per branch.
It turns that forecast into four things the shop can act on:

1. **Days until stockout.** When the shelf runs empty, counting expired stock as unsellable.
2. **Reorder point.** The stock level at which to place an order.
3. **Suggested order quantity.**
4. **Expiry-risk flags.** How much of a batch will still be on the shelf when it expires.

## The machine learning pipeline

Stockwise follows the standard machine learning workflow: **data → dataset → train and test → saved model → predictions**.

```
 1. generate_history.py   sales history in MySQL (transactions table)
            │
 2. build_dataset.py      ──► ml/dataset/training_dataset.csv   (the dataset: features + answers)
            │
 3. train_model.py        ──► split by date → train → test → ml/model/demand_model.joblib  (the model)
            │
 4. forecast_job.py       loads the model file every night ──► forecasts, forecast_daily,
                                                               forecast_batch_risk tables
            │
 5. PHP pages             Forecast tab and manager dashboard read those tables
```

- **Training** (steps 2–3) is done once and repeated monthly, or whenever there is a month or so of new sales: double-click `train_model.bat`.
- **Predicting** (step 4) runs every night and takes a few seconds. It only loads the saved model; it never trains.
- PHP never runs Python. The two share only the database.

`evaluate_models.py` is the separate **research comparison** (backtest) used to choose the random forest over three other methods.

## Files

| File | What it does |
|---|---|
| `generate_history.py` | Writes about 18 months of realistic **synthetic** sales into `transactions` (marked `source='synthetic'`). Includes trend, a weekly pattern, month-end payday, December and noise. |
| `build_dataset.py` | **Builds the dataset** from the database: `dataset/daily_sales.csv` (units sold per product per day) and `dataset/training_dataset.csv` (one row per product, day and days ahead, with the 14 features and the answer). Column meanings are in `dataset/README.txt`. |
| `train_model.py` | **Trains and tests the model.** Splits the dataset by date (the last 91 days are the test set), trains the random forest, scores it against a 7-day-average baseline, then retrains on all rows and saves `model/demand_model.joblib`. |
| `forecast_job.py` | The **nightly job**. Loads the saved model, forecasts every product and writes the forecast tables. |
| `models.py` | The 4 models: moving average, same weekday last week, Holt-Winters, random forest, plus how a dataset row is built (`training_rows`). |
| `inventory.py` | Stock maths on top of the forecast: stockout replay, safety stock, reorder point, order quantity, expiry risk. |
| `evaluate_models.py` | The **backtest** (research comparison). Replays the last 6 months and compares the 4 models against actual sales. Writes `results/`. |
| `charts.py` | The PNG charts in `results/`. |
| `config.py`, `db.py` | Reads the app's `.env`; database queries. |
| `tests/test_ml.py` | 16 unit tests for the maths, the models and saving/loading the model file. |
| `train_model.bat` | Double-click to rebuild the dataset and retrain the model on Windows. |
| `run_forecast.bat` | Double-click (or schedule nightly) to run the forecast on Windows. |
| `dataset/` | The dataset files (CSV). |
| `model/` | The trained model and its report: `demand_model.joblib`, `model_info.json` (when trained, on what, test scores), `test_predictions.csv` (actual vs predicted for every test row), `feature_importance.csv` / `.png`. |
| `results/` | Backtest output: `summary.md`, `comparison.csv`, `by_category.csv`, `robustness.csv` (7-history check), two charts, `best_model.txt`. |

## Setup on your PC (XAMPP, Windows)

Do the main Stockwise setup first (see the main README). Then:

1. **Install Python 3.11 or newer** from python.org. Tick *"Add python.exe to PATH"* during install.
2. **Install the libraries.** Open a Command Prompt in the `stockwise-php` folder and run:
   ```
   py -m pip install -r ml\requirements.txt
   ```
   **No internet on the project PC?** On any internet-connected Windows PC with the *same* Python version, download the libraries into a folder:
   ```
   py -m pip download -r ml\requirements.txt -d ml\wheels
   ```
   Copy the `ml\wheels` folder to the project PC (USB stick), then install from it with no internet:
   ```
   py -m pip install --no-index --find-links ml\wheels -r ml\requirements.txt
   ```
   Once installed, all the ML scripts run fully offline.
3. **Add the forecast tables to the database.**
   - New install: importing `database/schema.sql` already includes them.
   - Existing database: in phpMyAdmin, select `stockwise_php` → **Import** → `database/ml_migration.sql` (once only).
4. **Generate the sales history** (XAMPP's MySQL must be running):
   ```
   cd ml
   py generate_history.py
   ```
5. **Build the dataset and train the model** (about 1 minute):
   ```
   py build_dataset.py
   py train_model.py
   ```
   or double-click `train_model.bat`. This creates `ml/dataset/` and `ml/model/demand_model.joblib`.
6. **Run the forecast:**
   ```
   py forecast_job.py
   ```
   Then open any branch → **Forecast** tab. The manager dashboard cards now show forecast figures too.
7. **Run the model comparison** (optional, about 3 minutes; produces `ml/results/`):
   ```
   py evaluate_models.py
   ```
8. **Check the maths** (optional):
   ```
   py -m unittest discover -s tests -v
   ```

### Run it every night (optional)

Open Windows **Task Scheduler** → *Create Basic Task* → name it "Stockwise forecast" → **Daily** at, say, 22:00. Then:

- **Action:** *Start a program*
- **Program:** `C:\xampp\htdocs\stockwise-php\ml\run_forecast.bat` (use your actual path)
- **Start in:** the `ml` folder

Retrain monthly the same way, with a **Monthly** task pointing at `train_model.bat`.

XAMPP's MySQL must be running at that time. Each run is logged to `ml/logs/forecast_job.log`. If a forecast is more than a day old, the Forecast page shows a warning.

For a demo you can simply double-click `run_forecast.bat` before presenting.

### Settings (in `.env`, all optional)

| Setting | Default | Meaning |
|---|---|---|
| `FORECAST_MODEL` | `auto` | `auto` uses the trained model file `model/demand_model.joblib` (Holt-Winters if the file is missing). You can force `holt_winters` or `moving_average`, which need no training file. |
| `FORECAST_LEAD_TIME_DAYS` | `5` | Days a supplier takes to deliver. |
| `FORECAST_COVER_DAYS` | `14` | Days of demand each order should cover. |
| `FORECAST_SERVICE_Z` | `1.65` | Safety-stock level: 1.65 ≈ 95% chance of not running out during the lead time. |
| `FORECAST_MIN_HISTORY_DAYS` | `90` | Products with less history than this use the moving average. The random forest's features look back 90 days, so lower values have no effect on it. |

### Removing the synthetic data

```
py generate_history.py --remove
```

This deletes only rows marked `source='synthetic'`. Real transactions recorded in the app are never touched.

## Results (synthetic data)

**Model comparison** (`evaluate_models.py`: last 182 days, 5,600 daily forecasts per model). One six-month window can favour either of the two close models, so the comparison was repeated on seven generated histories (`generate_history.py --seed N`, then `evaluate_models.py`). The results are in `results/robustness.csv`:

| Across 7 histories | Random forest | Holt-Winters | 7-day moving average |
|---|---|---|---|
| Average MAE (units/day) | **3.44** | 3.46 | 3.75 |
| Lowest daily MAE in | **5 of 7** | 2 of 7 | 0 of 7 |
| Average error of the 14-day total | **14.8** | 15.6 | 24.6 |
| Lowest 14-day total error in | **7 of 7** | 0 of 7 | 0 of 7 |

- Per day, the random forest and Holt-Winters are practically tied, both about 8% better than the baseline. On the default demo history (seed 42), Holt-Winters is 0.6% lower per day.
- **On the 14-day total**, which drives the days-until-stockout and order figures, the random forest was best in every history: 40% below the baseline. So the system uses the random forest, with Holt-Winters as the fallback.
- **Why the daily gain looks small.** Daily sales are partly random. On the demo history even a perfect model, which knows the true average, has an MAE of 3.16 against the baseline's 3.58.

**The trained model** (`train_model.py`, test set = the last 91 days, 17,472 rows):

| | MAE (units/day) | Error of 14-day total |
|---|---|---|
| **Random forest** | **3.26** | **14.5** |
| 7-day moving average | 3.51 | 23.0 |

The exact numbers change whenever the history is regenerated, because it is dated relative to the day it is generated.

## Design decisions worth explaining

- **Train once, predict nightly.** The model is trained from a saved dataset file and stored as a model file, the standard machine learning workflow. Training takes about a minute and is repeated monthly; the nightly forecast just loads the file and takes a few seconds. The dataset, the model and its test scores can all be opened and inspected.
- **Split by date, not at random.** The test set is the most recent 91 days, and rows whose 14-day window straddles the split are dropped. A random split would let the model train on days after the ones it is tested on, which is data leakage for time series.
- **Global random forest.** One model is trained on all products. Sales are divided by each product's 28-day average, so a slow and a fast seller share one pattern. This works with little history per product.
- **Holt-Winters written by hand** (`models.py`, about 60 lines) instead of a library. Every equation is visible and the code needs one fewer dependency.
- **No data leakage.** Every feature uses only sales up to the forecast origin. The backtest splits by time and retrains the forest every 28 days using only past data.
- **One replay for stockout and expiry.** The forecast is replayed day by day against the real batches, selling the soonest-expiring batch first. So a batch that expires before it sells also brings the stockout date forward.
- **Cold start.** A product with less than 90 days (about three months) of history falls back to the moving average. With no sales at all, it shows "No sales forecast".

## Limitations

1. The history is synthetic. Results on real shop data will differ, and the backtest should be re-run once real history exists.
2. Lead time and safety-stock level are single settings, not per product or per supplier.
3. When a product is out of stock, recorded sales are 0 even if customers wanted it ("censored demand"), so the forecast can under-estimate.
4. Events not in the data (a price change, a supplier shortage, a holiday the generator doesn't know) aren't predicted.
5. Forecasts refresh once a day. Stock movements during the day show up in the next run.

## What was tested, and on what

- All of the following ran end to end:
  - the generator
  - the backtest
  - the nightly job (normal, short-history and no-history products)
  - a database upgrade from the previous schema using `ml_migration.sql`
  - every page for both roles: HTTP 200, no PHP warnings, no JavaScript errors
  - the 16 unit tests
  - building the dataset, training and saving the model, and the nightly job loading it
- The database was Dolt, a MySQL 8-compatible server. Run it once on your XAMPP (MariaDB) setup before presenting.
- Library versions used: numpy 2.5, pandas 3.0, scipy 1.18, scikit-learn 1.9, matplotlib 3.11, PyMySQL 2.2, Python 3.13.
