# Stockwise forecasting: model comparison

Generated 2026-11-04 12:35. Test period: the last 182 days, forecasting 14 days ahead from a new origin every 7 days (25 origins x 16 products = 5,600 daily forecasts per model). Average actual sales in the test period: 6.40 units per product per day.

**Result:** Holt-Winters (damped trend, weekly season) had the lowest error, MAE 3.32 units/day, 7% lower than the 7-day moving-average baseline (MAE 3.58).

A perfect model that knew the true average demand would still have MAE 3.16, because day-to-day sales are partly random. So the most any model could gain over the baseline is 0.41 units/day; Holt-Winters gains 0.26, closing **62% of that gap**. On the 14-day total, which drives stockout and reorder decisions, its error is **33% lower** than the baseline (15.7 vs 23.3 units; best possible 13.4).

| Model | MAE (units/day) | vs baseline | RMSE | Bias | MAE days 1-7 | MAE days 8-14 | Error of 14-day total |
|---|---|---|---|---|---|---|---|
| Holt-Winters (damped trend, weekly season) | 3.32 | -7% | 4.85 | -0.03 | 3.31 | 3.33 | 15.7 |
| Random forest (global, calendar + recent-sales features) | 3.34 | -7% | 4.87 | +0.07 | 3.31 | 3.37 | 15.6 |
| 7-day moving average (baseline) | 3.58 | +0% | 5.23 | +0.00 | 3.56 | 3.59 | 23.3 |
| Same weekday last week (baseline) | 4.50 | +26% | 6.64 | +0.00 | 4.49 | 4.51 | 23.3 |
| *Perfect model (true average known, synthetic data only)* | *3.16* | *-12%* | | | | | *13.4* |

- **MAE**: average size of the daily error, in units. Lower is better.
- **vs baseline**: change in MAE compared with the 7-day moving average (negative = better).
- **Bias**: average of (forecast - actual); positive means the model over-forecasts.
- **Error of 14-day total**: how far off the total for the next 14 days was. This is what drives the days-until-stockout and reorder figures.
- **Perfect model**: forecasts the exact average the generator used. Its remaining error is pure day-to-day randomness that no model can predict, so it marks the floor: a model's real gain is how much of the gap between the baseline and this floor it closes.

## MAE by category

| Category | Holt-Winters | Random forest | 7-day moving average | Same weekday last week |
|---|---|---|---|---|
| Apparel | 3.95 | 3.88 | 4.34 | 5.21 |
| Electronics | 2.97 | 2.92 | 3.13 | 4.03 |
| Food & Beverage | 2.99 | 3.00 | 3.29 | 4.04 |
| Health | 5.51 | 5.87 | 5.88 | 7.75 |
| Sporting Goods | 1.71 | 1.70 | 1.78 | 2.33 |
| Tools | 1.69 | 1.69 | 1.80 | 2.27 |

## Note on the data

The sales history is synthetic (ml/generate_history.py): built with a trend, a weekly pattern, a month-end payday effect, a December bump and random noise. Because these patterns were planted on purpose, the comparison shows whether each model can recover them. Results on real shop data will differ and should be re-checked once real history exists.
