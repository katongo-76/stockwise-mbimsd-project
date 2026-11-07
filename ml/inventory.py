"""
Turning a demand forecast into stock decisions.

Only the forecast is machine learning. These are standard inventory
calculations applied to it, kept as small pure functions so each one can
be tested and explained on its own.
"""
import math
from datetime import date, timedelta

import numpy as np


def extend_forecast(daily: np.ndarray, days: int) -> np.ndarray:
    """
    Forecasts cover 14 days; expiry dates can be further away. Beyond the
    forecast, repeat its last 7 days so the weekly pattern carries on.
    """
    daily = np.asarray(daily, dtype=float)
    if days <= len(daily):
        return daily[:days]
    week = daily[-7:] if len(daily) >= 7 else np.full(7, daily.mean() if len(daily) else 0.0)
    extra = np.resize(week, days - len(daily))
    return np.concatenate([daily, extra])


def safety_stock(demand_std: float, lead_time: int, z: float) -> int:
    """
    Extra stock kept for days when demand runs above the forecast:
        safety stock = z × (std of daily demand) × √(lead time)
    z = 1.65 gives about a 95% chance of not running out while an order is on its way.
    """
    return int(math.ceil(z * demand_std * math.sqrt(lead_time)))


def reorder_point(avg_daily: float, lead_time: int, safety: int) -> int:
    """
    When stock falls to this level, order now:
        reorder point = average daily demand × lead time + safety stock
    Example: 3/day, 5-day lead time, safety 6 -> 21
    """
    return int(math.ceil(avg_daily * lead_time + safety))


def suggested_order(on_hand: float, avg_daily: float, lead_time: int, cover_days: int,
                    safety: int, rop: int, runs_out_early: bool = False) -> int:
    """
    Nothing to order while stock is above the reorder point, unless the stock is
    expected to run out within the lead time anyway (for example because a batch
    expires before it sells). Otherwise order enough to get through the lead time
    plus `cover_days`, with the safety stock on top, minus what is already on hand.
    `on_hand` should be the stock expected to sell (stock that will expire unsold excluded).
    Example: 18 on hand, 3/day, 5 + 14 days, safety 6 -> 3×19 + 6 − 18 = 45
    """
    if on_hand > rop and not runs_out_early:
        return 0
    return max(0, int(math.ceil(avg_daily * (lead_time + cover_days) + safety - on_hand)))


def stock_status(avg_daily: float, days_left, on_hand: float, rop: int, lead_time: int) -> str:
    """
    no_demand: nothing is forecast to sell
    critical:  stock runs out before a new order could arrive (within the lead time)
    reorder:   at or below the reorder point: order now
    ok:        enough stock for now
    """
    if avg_daily < 0.05:
        return "no_demand"
    if days_left is not None and days_left <= lead_time:
        return "critical"
    if on_hand <= rop:
        return "reorder"
    return "ok"


def simulate_stock(batches, unbatched: float, daily: np.ndarray, today: date, max_days: int = 365):
    """
    Replay the forecast day by day against the stock actually on the shelf.

    `batches` is a list of (batch_id, quantity_remaining, expiry_date or None);
    `unbatched` is any quantity not held in a batch (treated as never expiring).
    Stockwise sells the soonest-expiring batch first, so each day's forecast
    demand is taken from the soonest-expiring batch that is still in date.
    Batches already past their expiry date cannot be sold.

    Returns (days_until_stockout, risk):
      days_until_stockout  decimal days until the shelf is empty (2.5 = halfway
                           through the third day), None if nothing is forecast
                           to sell, max_days if stock outlasts the simulation
      risk                 {batch_id: (expected_sold, at_risk)} for every batch
                           expiring within max_days; at_risk = what will still be
                           left when it expires

    Example: 24 units, no expiry, forecast 3/day -> stockout in 8.0 days.
    Example: 42 units expiring in 4 days, forecast 8/day -> about 40 sell
             (days 0 to 4), about 2 at risk, and the shelf is empty after that.
    """
    risk = {}
    live = []
    for bid, qty, exp in batches:
        if exp is not None and exp < today:
            risk[bid] = (0, int(qty))                          # already expired
        else:
            live.append([bid, float(qty), exp, 0.0])            # id, left, expiry, sold
    if unbatched > 0:
        live.append([None, float(unbatched), None, 0.0])
    live.sort(key=lambda b: (b[2] is None, b[2] or date.max))

    demand = extend_forecast(daily, max_days)
    days_left = None if demand.sum() <= 0 else float(max_days)
    if sum(b[1] for b in live) <= 0:
        days_left = 0.0

    if days_left:
        for day in range(max_days):
            need = demand[day]
            wanted = need
            current = today + timedelta(days=day)
            for b in live:
                if need <= 0:
                    break
                if b[1] <= 0 or (b[2] is not None and b[2] < current):
                    continue
                take = min(need, b[1])
                b[1] -= take
                b[3] += take
                need -= take
            if need > 1e-9:                                    # demand not met: shelf is empty
                days_left = round(day + (wanted - need) / wanted, 1)
                break

    horizon_end = today + timedelta(days=max_days)
    for bid, left, exp, sold in live:
        if bid is not None and exp is not None and exp < horizon_end:   # beyond the replay: no verdict
            qty = int(round(left + sold))
            sold_units = min(qty, int(round(sold)))
            risk[bid] = (sold_units, qty - sold_units)
    return days_left, risk


def expired_quantity(batches, today: date) -> int:
    """Units sitting in batches that are already past their expiry date."""
    return int(sum(q for _, q, exp in batches if exp is not None and exp < today))
