"""
Unit tests for the forecasting maths. No database needed.

Run from the ml folder:
    python -m unittest discover -s tests -v
"""
import sys
import unittest
from datetime import date, timedelta
from pathlib import Path

import numpy as np
import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import inventory  # noqa: E402
from models import HoltWinters, MovingAverage, RandomForest, SeasonalNaive  # noqa: E402

TODAY = date(2026, 10, 19)


class StockSimulationTests(unittest.TestCase):
    def test_constant_demand_runs_out_on_schedule(self):
        days, _ = inventory.simulate_stock([], 24, np.full(14, 3.0), TODAY)
        self.assertEqual(days, 8.0)

    def test_partial_day(self):
        days, _ = inventory.simulate_stock([], 10, np.full(14, 4.0), TODAY)
        self.assertEqual(days, 2.5)          # 4 + 4 on days 0-1, then 2 of the 4 on day 2

    def test_beyond_forecast_uses_repeated_week(self):
        days, _ = inventory.simulate_stock([], 100, np.full(14, 2.0), TODAY)
        self.assertEqual(days, 50.0)

    def test_no_demand(self):
        days, _ = inventory.simulate_stock([], 10, np.zeros(14), TODAY)
        self.assertIsNone(days)

    def test_empty_shelf(self):
        days, _ = inventory.simulate_stock([], 0, np.full(14, 3.0), TODAY)
        self.assertEqual(days, 0.0)

    def test_batch_expiring_before_it_sells(self):
        exp = TODAY + timedelta(days=4)                    # sellable on days 0..4 = 5 days
        days, risk = inventory.simulate_stock([(1, 42, exp)], 0, np.full(14, 6.0), TODAY)
        self.assertEqual(risk[1], (30, 12))
        self.assertEqual(days, 5.0)                        # nothing left to sell after expiry

    def test_soonest_expiry_sold_first(self):
        batches = [(1, 50, TODAY + timedelta(days=100)), (2, 10, TODAY + timedelta(days=3))]
        _, risk = inventory.simulate_stock(batches, 0, np.full(14, 5.0), TODAY)
        self.assertEqual(risk[2], (10, 0))                 # the short-dated batch goes first
        self.assertEqual(risk[1], (50, 0))

    def test_already_expired_batch_cannot_sell(self):
        batches = [(1, 40, TODAY - timedelta(days=3)), (2, 20, None)]
        days, risk = inventory.simulate_stock(batches, 0, np.full(14, 5.0), TODAY)
        self.assertEqual(risk[1], (0, 40))
        self.assertEqual(days, 4.0)                        # only the 20 good units count
        self.assertEqual(inventory.expired_quantity(batches, TODAY), 40)


    def test_expiry_beyond_replay_gets_no_verdict(self):
        batches = [(1, 10, TODAY + timedelta(days=800)), (2, 10, TODAY + timedelta(days=30))]
        _, risk = inventory.simulate_stock(batches, 0, np.zeros(14), TODAY)
        self.assertNotIn(1, risk)
        self.assertEqual(risk[2], (0, 10))


class ReorderTests(unittest.TestCase):
    def test_textbook_examples(self):
        self.assertEqual(inventory.reorder_point(3, 5, 6), 21)
        self.assertEqual(inventory.safety_stock(2.0, 4, 1.65), 7)             # 1.65 x 2 x 2 = 6.6 -> 7
        self.assertEqual(inventory.suggested_order(18, 3, 5, 14, 6, 21), 45)  # 3 x 19 + 6 - 18
        self.assertEqual(inventory.suggested_order(30, 3, 5, 14, 6, 21), 0)   # above reorder point
        self.assertEqual(inventory.suggested_order(30, 3, 5, 14, 6, 21, runs_out_early=True), 33)  # stock expires before it sells: order anyway

    def test_status(self):
        self.assertEqual(inventory.stock_status(0.0, None, 10, 0, 5), "no_demand")
        self.assertEqual(inventory.stock_status(3, 4.0, 12, 21, 5), "critical")
        self.assertEqual(inventory.stock_status(3, 7.0, 21, 21, 5), "reorder")
        self.assertEqual(inventory.stock_status(3, 20.0, 60, 21, 5), "ok")


def weekly_series(days=400, noise=0.0, seed=1):
    idx = pd.date_range("2025-06-01", periods=days, freq="D")
    pattern = np.array([4, 4, 5, 5, 7, 12, 9], dtype=float)
    y = pattern[idx.dayofweek]
    if noise:
        y = y + np.random.default_rng(seed).normal(0, noise, days)
    return pd.Series(np.maximum(0, y), index=idx)


class ModelTests(unittest.TestCase):
    def test_baselines(self):
        y = weekly_series()
        self.assertAlmostEqual(MovingAverage().predict(y, 3)[0], 46 / 7)
        sn = SeasonalNaive().predict(y, 7)
        self.assertListEqual(list(sn), list(y.iloc[-7:]))

    def test_holt_winters_learns_a_clean_weekly_pattern(self):
        y = weekly_series()
        f = HoltWinters().predict(y, 7)
        expected = y.iloc[-7:].to_numpy()                 # the same weekdays repeat
        self.assertLess(np.abs(f - expected).max(), 0.5)

    def test_random_forest_beats_average_on_weekly_pattern(self):
        series = {i: weekly_series(noise=1.0, seed=i) * (i + 1) for i in range(4)}
        cut = series[0].index[-15]
        rf = RandomForest(n_estimators=60)
        rf.fit({k: s.loc[:cut] for k, s in series.items()}, cut)
        y = series[2]
        actual = y.loc[cut + pd.Timedelta(days=1):].iloc[:14].to_numpy()
        rf_err = np.abs(rf.predict(y.loc[:cut], 14) - actual).mean()
        ma_err = np.abs(MovingAverage().predict(y.loc[:cut], 14) - actual).mean()
        self.assertLess(rf_err, ma_err)

    def test_saved_model_file_gives_identical_forecasts(self):
        import tempfile, os
        series = {i: weekly_series(noise=1.0, seed=i) * (i + 1) for i in range(3)}
        cut = series[0].index[-1]
        rf = RandomForest(n_estimators=20)
        rf.fit(series, cut)
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, "m.joblib")
            rf.save(path, {"note": "test"})
            loaded = RandomForest.load(path)
        np.testing.assert_allclose(loaded.predict(series[1], 14), rf.predict(series[1], 14))
        self.assertEqual(loaded.info["note"], "test")

    def test_forecasts_never_negative(self):
        y = pd.Series(np.zeros(120), index=pd.date_range("2026-01-01", periods=120, freq="D"))
        self.assertTrue((HoltWinters().predict(y, 14) >= 0).all())


if __name__ == "__main__":
    unittest.main()
