"""
Settings for the ML scripts.

Reads the SAME .env file the PHP app uses (one level up from ml/), so the
database credentials live in one place. Forecast settings have defaults and
can be overridden in .env.
"""
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent      # the stockwise-php folder
ML_DIR = Path(__file__).resolve().parent
RESULTS_DIR = ML_DIR / "results"


def _load_env(path: Path) -> dict:
    values = {}
    if not path.is_file():
        return values
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip("\"'")
    return values


_ENV = _load_env(ROOT / ".env")


def env(key: str, default: str = "") -> str:
    """Real environment variables win over .env, like the PHP loader."""
    return os.environ.get(key, _ENV.get(key, default))


DB = {
    "host": env("DB_HOST", "localhost"),
    "port": int(env("DB_PORT", "3306")),
    "database": env("DB_NAME", "stockwise_php"),
    "user": env("DB_USER", "root"),
    "password": env("DB_PASSWORD", ""),
}

# How many days ahead to forecast.
HORIZON_DAYS = int(env("FORECAST_HORIZON_DAYS", "14"))
# Days a supplier takes to deliver after an order is placed.
LEAD_TIME_DAYS = int(env("FORECAST_LEAD_TIME_DAYS", "5"))
# Days of demand an order should cover once it arrives.
COVER_DAYS = int(env("FORECAST_COVER_DAYS", "14"))
# Safety-stock service level as a z-score: 1.65 ≈ 95% chance of not running out during lead time.
SERVICE_Z = float(env("FORECAST_SERVICE_Z", "1.65"))
# Which model the nightly job uses: auto / random_forest = the trained model file ml/model/demand_model.joblib
# (made by train_model.py); or holt_winters / moving_average, which need no training file.
MODEL = env("FORECAST_MODEL", "auto")
# Products with fewer days of sales history than this fall back to the moving average.
MIN_HISTORY_DAYS = int(env("FORECAST_MIN_HISTORY_DAYS", "90"))
