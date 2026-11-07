"""Database access for the ML scripts (PyMySQL, parameterised queries only)."""
from datetime import date, timedelta

import pandas as pd
import pymysql

from config import DB


def connect():
    return pymysql.connect(
        host=DB["host"], port=DB["port"], user=DB["user"], password=DB["password"],
        database=DB["database"], charset="utf8mb4", autocommit=False,
        cursorclass=pymysql.cursors.DictCursor,
    )


def load_products(conn) -> pd.DataFrame:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT p.id, p.branch_id, p.name, p.category, p.unit_of_measure, p.current_quantity, "
            "       p.low_stock_threshold, b.company_id "
            "FROM products p JOIN branches b ON b.id = p.branch_id ORDER BY p.id"
        )
        return pd.DataFrame(cur.fetchall())


def load_batches(conn) -> pd.DataFrame:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, product_id, quantity_remaining, expiry_date FROM stock_batches "
            "WHERE quantity_remaining > 0 ORDER BY product_id, (expiry_date IS NULL), expiry_date, id"
        )
        rows = cur.fetchall()
    return pd.DataFrame(rows, columns=["id", "product_id", "quantity_remaining", "expiry_date"])


def load_daily_sales(conn, product_ids, end: date) -> dict:
    """
    Daily units sold per product, from stock_out transactions up to and
    including `end`. Days with no sales are filled with 0, from the
    product's first transaction of any kind up to `end`.

    Returns {product_id: pandas Series indexed by date}.
    """
    with conn.cursor() as cur:
        cur.execute(
            "SELECT product_id, DATE(created_at) AS d, "
            "       SUM(CASE WHEN type = 'stock_out' THEN quantity ELSE 0 END) AS sold "
            "FROM transactions WHERE DATE(created_at) <= %s "
            "GROUP BY product_id, DATE(created_at)",
            (end,),
        )
        rows = cur.fetchall()

    df = pd.DataFrame(rows, columns=["product_id", "d", "sold"])
    series = {}
    for pid in product_ids:
        sub = df[df["product_id"] == pid]
        if sub.empty:
            series[pid] = pd.Series(dtype=float)
            continue
        idx = pd.date_range(pd.Timestamp(sub["d"].min()), pd.Timestamp(end), freq="D")
        s = pd.Series(sub["sold"].astype(float).values, index=pd.to_datetime(sub["d"]))
        series[pid] = s.reindex(idx, fill_value=0.0)
    return series


def yesterday() -> date:
    return date.today() - timedelta(days=1)
