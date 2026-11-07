Stockwise forecasting dataset
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
