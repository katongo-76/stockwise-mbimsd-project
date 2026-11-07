"""
The forecasting models compared in Stockwise.

Every model answers the same question: given a product's daily sales up to
and including day t (the "origin"), how many units will sell on each of
the next H days?

  MovingAverage   baseline: every future day = average of the last 7 days
  SeasonalNaive   baseline: every future day = the same weekday last week
  HoltWinters     classical time series: level + damped trend + weekly
                  seasonality, re-estimated per product (written here with
                  numpy/scipy, so every equation is visible)
  RandomForest    machine learning: one model trained on ALL products, using
                  calendar and recent-sales features (scikit-learn). Trained by
                  train_model.py from dataset/training_dataset.csv and saved to
                  model/demand_model.joblib

Local models (the first three) look at one product at a time and need no
training step. The random forest is "global": it learns patterns shared by
all products, scaled to each product's own sales level.
"""
import numpy as np
import pandas as pd
from scipy.optimize import minimize
from sklearn.ensemble import RandomForestRegressor


def is_payday(dates: pd.DatetimeIndex) -> np.ndarray:
    """Month-end salary days: the 25th to the 2nd of the next month."""
    return ((dates.day >= 25) | (dates.day <= 2)).astype(int)


class MovingAverage:
    name = "moving_average"
    label = "7-day moving average (baseline)"

    def fit(self, series_by_product: dict, origin) -> None:
        pass

    def predict(self, history: pd.Series, horizon: int) -> np.ndarray:
        return np.full(horizon, float(history.iloc[-7:].mean()))


class SeasonalNaive:
    name = "seasonal_naive"
    label = "Same weekday last week (baseline)"

    def fit(self, series_by_product: dict, origin) -> None:
        pass

    def predict(self, history: pd.Series, horizon: int) -> np.ndarray:
        last_week = history.iloc[-7:].to_numpy(dtype=float)
        return np.array([last_week[h % 7] for h in range(horizon)])


class HoltWinters:
    """
    Additive Holt-Winters with a damped trend and weekly seasonality (m = 7).

    For each day t, with sales y_t:
        level     l_t = α (y_t − s_{t−m}) + (1 − α)(l_{t−1} + φ b_{t−1})
        trend     b_t = β (l_t − l_{t−1}) + (1 − β) φ b_{t−1}
        season    s_t = γ (y_t − l_{t−1} − φ b_{t−1}) + (1 − γ) s_{t−m}
    Forecast h days ahead:
        ŷ_{t+h} = l_t + (φ + φ² + … + φ^h) b_t + s_{t+h−m}  (season of that weekday)

    α, β, γ, φ are chosen to minimise the squared one-day-ahead errors over
    the last `window` days. Forecasts below 0 are set to 0.
    """
    name = "holt_winters"
    label = "Holt-Winters (damped trend, weekly season)"
    m = 7

    def __init__(self, window: int = 364):
        self.window = window

    def fit(self, series_by_product: dict, origin) -> None:
        pass

    def _run(self, y, alpha, beta, gamma, phi):
        m = self.m
        level = y[:m].mean()
        trend = (y[m:2 * m].mean() - y[:m].mean()) / m if len(y) >= 2 * m else 0.0
        season = list(y[:m] - level)
        sse = 0.0
        for t in range(m, len(y)):
            s_old = season[t - m]
            pred = level + phi * trend + s_old
            err = y[t] - pred
            sse += err * err
            new_level = alpha * (y[t] - s_old) + (1 - alpha) * (level + phi * trend)
            season.append(gamma * (y[t] - level - phi * trend) + (1 - gamma) * s_old)
            trend = beta * (new_level - level) + (1 - beta) * phi * trend
            level = new_level
        return sse, level, trend, season

    def predict(self, history: pd.Series, horizon: int) -> np.ndarray:
        y = history.iloc[-self.window:].to_numpy(dtype=float)
        if len(y) < 2 * self.m:
            return MovingAverage().predict(history, horizon)
        bounds = [(0.01, 0.9), (0.0, 0.3), (0.0, 0.9), (0.8, 0.98)]
        result = minimize(lambda p: self._run(y, *p)[0], x0=[0.2, 0.02, 0.1, 0.9],
                          bounds=bounds, method="L-BFGS-B", options={"maxiter": 60})
        alpha, beta, gamma, phi = result.x
        _, level, trend, season = self._run(y, alpha, beta, gamma, phi)
        n = len(y)
        out = []
        damp = 0.0
        for h in range(1, horizon + 1):
            damp += phi ** h
            out.append(level + damp * trend + season[n - self.m + (h - 1) % self.m])
        self.params = {"alpha": alpha, "beta": beta, "gamma": gamma, "phi": phi}
        return np.maximum(0.0, np.array(out))


# ───────────────────────── Random forest ─────────────────────────

LOOKBACK = 90   # days of history needed to build one set of features

# The clues the random forest gets for each (product, origin, h) row.
# Sales-based features are divided by the product's 28-day average ("ratio")
# so fast and slow sellers look alike to the model.
#   h              how many days ahead (1..14)
#   dow, is_weekend, day_of_month, is_payday, month, is_december
#                  calendar facts about the day being forecast
#   mean7_ratio, mean14_ratio   recent level compared with the 28-day level
#   trend_ratio    28-day average / 90-day average (>1 means sales are growing)
#   last_ratio     sales on the origin day
#   same_dow_ratio average of the last 4 sales on the forecast day's weekday
#   std28_ratio    how jumpy sales have been lately
#   payday_ratio   this product's sales on payday days / other days (last 90 days)
FEATURES = [
    "h", "dow", "is_weekend", "day_of_month", "is_payday", "month", "is_december",
    "mean7_ratio", "mean14_ratio", "trend_ratio", "last_ratio", "same_dow_ratio", "std28_ratio",
    "payday_ratio",
]


def _origin_stats(y: pd.Series) -> pd.DataFrame:
    """
    For every day t, statistics that only use sales up to and including t
    (so nothing from the future leaks into the features).
    """
    mean28 = y.rolling(28, min_periods=28).mean()
    scale = mean28.clip(lower=0.5)                     # the product's current sales level
    same_dow = (y + y.shift(7) + y.shift(14) + y.shift(21)) / 4   # mean of the last 4 same weekdays
    pay = pd.Series(is_payday(y.index), index=y.index)
    pay_mean = (y * pay).rolling(LOOKBACK).sum() / pay.rolling(LOOKBACK).sum()
    nonpay_mean = (y * (1 - pay)).rolling(LOOKBACK).sum() / (1 - pay).rolling(LOOKBACK).sum()
    return pd.DataFrame({
        "scale": scale,
        "mean7_ratio": y.rolling(7).mean() / scale,
        "mean14_ratio": y.rolling(14).mean() / scale,
        "trend_ratio": mean28 / y.rolling(LOOKBACK).mean().clip(lower=0.5),   # >1 = sales growing
        "last_ratio": y / scale,
        "same_dow": same_dow,
        "std28_ratio": y.rolling(28).std() / scale,
        "payday_ratio": pay_mean / nonpay_mean.clip(lower=0.1),
    })


def _rows_for_origins(y: pd.Series, stats: pd.DataFrame, origin_pos: np.ndarray, horizon: int) -> pd.DataFrame:
    """Feature rows for each (origin, h) pair. origin_pos are integer positions in y."""
    frames = []
    for h in range(1, horizon + 1):
        target_dates = y.index[0] + pd.to_timedelta(origin_pos + h, unit="D")
        same_pos = origin_pos - ((-h) % 7)        # last day on or before the origin with the target's weekday
        st = stats.iloc[origin_pos]
        frames.append(pd.DataFrame({
            "origin_pos": origin_pos,
            "h": h,
            "dow": target_dates.dayofweek,
            "is_weekend": (target_dates.dayofweek >= 5).astype(int),
            "day_of_month": target_dates.day,
            "is_payday": is_payday(target_dates),
            "month": target_dates.month,
            "is_december": (target_dates.month == 12).astype(int),
            "mean7_ratio": st["mean7_ratio"].to_numpy(),
            "mean14_ratio": st["mean14_ratio"].to_numpy(),
            "trend_ratio": st["trend_ratio"].to_numpy(),
            "last_ratio": st["last_ratio"].to_numpy(),
            "same_dow_ratio": stats["same_dow"].to_numpy()[same_pos] / st["scale"].to_numpy(),
            "std28_ratio": st["std28_ratio"].to_numpy(),
            "payday_ratio": st["payday_ratio"].to_numpy(),
            "scale": st["scale"].to_numpy(),
        }))
    return pd.concat(frames, ignore_index=True)


def training_rows(y: pd.Series, horizon: int) -> pd.DataFrame:
    """
    Turn one product's daily sales into training examples.

    One row = "standing on origin_date, predict sales h days later":
    the 14 FEATURES (clues), the product's 28-day average `scale`, and the
    answer `target_ratio` = actual sales on target_date / scale.
    Used by build_dataset.py (to write the dataset file) and by
    RandomForest.fit, so both always produce exactly the same rows.
    """
    if len(y) < LOOKBACK + horizon + 1:
        return pd.DataFrame(columns=["origin_date", "target_date"] + FEATURES + ["scale", "target_ratio", "target_units"])
    stats = _origin_stats(y)
    origins = np.arange(LOOKBACK, len(y) - horizon)
    rows = _rows_for_origins(y, stats, origins, horizon)
    target_units = y.to_numpy()[rows["origin_pos"] + rows["h"]]
    out = rows[FEATURES + ["scale"]].copy()
    out.insert(0, "target_date", (y.index[0] + pd.to_timedelta(rows["origin_pos"] + rows["h"], unit="D")).dt.date)
    out.insert(0, "origin_date", (y.index[0] + pd.to_timedelta(rows["origin_pos"], unit="D")).dt.date)
    out["target_ratio"] = target_units / rows["scale"]
    out["target_units"] = target_units
    return out


def make_regressor(n_estimators: int = 200, seed: int = 42) -> RandomForestRegressor:
    """The random forest settings used everywhere (training script, backtest, tests)."""
    return RandomForestRegressor(n_estimators=n_estimators, min_samples_leaf=20, max_features=0.5,
                                 n_jobs=-1, random_state=seed)


class RandomForest:
    """
    One random forest for all products ("global model"), predicting each of
    the next H days directly. The target is sales divided by the product's
    28-day average, so a slow and a fast seller can share one model; the
    prediction is multiplied back by that average.

    In the running system the forest is trained once by train_model.py from
    the dataset file and saved to ml/model/demand_model.joblib; the nightly
    job loads that file with RandomForest.load() and only predicts.
    """
    name = "random_forest"
    label = "Random forest (global, calendar + recent-sales features)"

    def __init__(self, horizon: int = 14, n_estimators: int = 200, seed: int = 42):
        self.horizon = horizon
        self.model = make_regressor(n_estimators, seed)
        self.trained = False
        self.info = {}

    def fit(self, series_by_product: dict, origin) -> None:
        """Train on every product's history up to and including `origin` (used by the backtest)."""
        frames = [training_rows(y.loc[:origin], self.horizon) for y in series_by_product.values()]
        frames = [f for f in frames if len(f)]
        if not frames:
            self.trained = False
            return
        data = pd.concat(frames, ignore_index=True)
        self.fit_rows(data[FEATURES], data["target_ratio"])

    def fit_rows(self, X: pd.DataFrame, y) -> None:
        """Train directly on dataset rows (used by train_model.py)."""
        self.model.fit(X[FEATURES], np.asarray(y, dtype=float))
        self.trained = True

    def predict_rows(self, X: pd.DataFrame) -> np.ndarray:
        """Predicted units for dataset rows: predicted ratio x the row's scale."""
        return np.maximum(0.0, self.model.predict(X[FEATURES]) * X["scale"].to_numpy())

    def save(self, path, info: dict) -> None:
        import joblib
        self.info = dict(info)
        joblib.dump({"model": self.model, "features": FEATURES, "horizon": self.horizon, "info": self.info},
                     path, compress=3)

    @classmethod
    def load(cls, path) -> "RandomForest":
        import joblib
        saved = joblib.load(path)
        if list(saved["features"]) != FEATURES:
            raise ValueError("The saved model was trained with different features; run train_model.py again.")
        rf = cls(horizon=saved["horizon"])
        rf.model, rf.info, rf.trained = saved["model"], saved.get("info", {}), True
        return rf

    def can_predict(self, history: pd.Series) -> bool:
        return self.trained and len(history) >= LOOKBACK

    def predict(self, history: pd.Series, horizon: int) -> np.ndarray:
        if not self.can_predict(history):
            return MovingAverage().predict(history, horizon)
        stats = _origin_stats(history)
        rows = _rows_for_origins(history, stats, np.array([len(history) - 1]), horizon)
        pred = self.model.predict(rows[FEATURES]) * rows["scale"].to_numpy()
        return np.maximum(0.0, pred)


ALL_MODELS = {
    "moving_average": MovingAverage,
    "seasonal_naive": SeasonalNaive,
    "holt_winters": HoltWinters,
    "random_forest": RandomForest,
}
