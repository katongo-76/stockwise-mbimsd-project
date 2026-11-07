"""Charts for the evaluation results (PNG, for the report and presentation)."""
import matplotlib

matplotlib.use("Agg")
import matplotlib.dates as mdates  # noqa: E402
import matplotlib.pyplot as plt  # noqa: E402
import pandas as pd  # noqa: E402

from models import ALL_MODELS  # noqa: E402

SURFACE = "#fcfcfb"
INK = "#0b0b0b"
INK_2 = "#52514e"
GRID = "#e4e3df"
MUTED = "#c3c2b7"
SERIES = {"random_forest": "#2a78d6", "moving_average": "#eb6834", "holt_winters": "#1baf7a",
          "seasonal_naive": "#6250d6"}
SHORT = {"moving_average": "Moving average", "seasonal_naive": "Same weekday last week",
         "holt_winters": "Holt-Winters", "random_forest": "Random forest"}


def _style(ax):
    ax.set_facecolor(SURFACE)
    for side in ("top", "right", "left"):
        ax.spines[side].set_visible(False)
    ax.spines["bottom"].set_color(MUTED)
    ax.tick_params(colors=INK_2, labelsize=9, length=0)


def mae_chart(comp: pd.DataFrame, path, floor=None):
    order = comp.sort_values("mae", ascending=False)          # best ends up on top
    best = comp.index[0]
    fig, ax = plt.subplots(figsize=(8, 3.2), dpi=150, facecolor=SURFACE)
    _style(ax)
    colors = [SERIES["random_forest"] if k == best else MUTED for k in order.index]
    ax.barh([SHORT[k] for k in order.index], order["mae"], color=colors, height=0.55)
    for i, (k, r) in enumerate(order.iterrows()):
        note = "baseline" if k == "moving_average" else f"{r['vs_baseline_pct']:+.0f}% vs baseline"
        ax.text(r["mae"] + order["mae"].max() * 0.01, i, f"{r['mae']:.2f}  ({note})",
                va="center", fontsize=9, color=INK)
    ax.set_xlim(0, order["mae"].max() * 1.35)
    if floor:
        ax.axvline(floor[0], color=INK_2, linewidth=1, linestyle=(0, (4, 3)))
        ax.text(floor[0], len(order) - 0.45, f"best possible {floor[0]:.2f}", ha="center", va="bottom",
                fontsize=8.5, color=INK_2)
    ax.xaxis.grid(True, color=GRID, linewidth=0.8)
    ax.set_axisbelow(True)
    ax.tick_params(axis="y", labelsize=10, colors=INK)
    ax.set_xlabel("Mean absolute error (units per product per day, lower is better)", color=INK_2, fontsize=9)
    gain = -comp.loc[best, "vs_baseline_pct"]
    title = f"{SHORT[best]} cuts daily forecast error by {gain:.0f}% vs the moving average"
    if floor:
        base = comp.loc["moving_average", "mae"]
        closed = (base - comp.loc[best, "mae"]) / (base - floor[0]) * 100
        title = f"{SHORT[best]} closes {closed:.0f}% of the gap between the baseline and the best possible"
    fig.suptitle(title,
                 x=0.02, ha="left", fontsize=12, fontweight="bold", color=INK)
    fig.tight_layout()
    fig.savefig(path, facecolor=SURFACE)
    plt.close(fig)


def example_chart(series: dict, df: pd.DataFrame, path, prefer=("Running Shorts", "Men's Polo Shirt", "Almond Milk 6-Pack")):
    names = df.drop_duplicates("product_id").set_index("product")["product_id"]
    product = next((p for p in prefer if p in names.index), names.index[0])
    pid = names[product]
    sub = df[df["product_id"] == pid]
    origins = sorted(sub["origin"].unique())[-6::2]           # three non-overlapping 14-day forecasts
    start = origins[0] - pd.Timedelta(days=13)
    end = origins[-1] + pd.Timedelta(days=14)
    actual = series[pid].loc[start:end]

    fig, ax = plt.subplots(figsize=(9, 3.8), dpi=150, facecolor=SURFACE)
    _style(ax)
    ax.yaxis.grid(True, color=GRID, linewidth=0.8)
    ax.set_axisbelow(True)
    ax.plot(actual.index, actual.values, color=INK_2, linewidth=1.2, marker="o", markersize=2.5, label="Actual sales")
    for key in ("moving_average", "holt_winters", "random_forest"):
        for j, o in enumerate(origins):
            part = sub[(sub["model"] == key) & (sub["origin"] == o)]
            dates = [o + pd.Timedelta(days=int(h)) for h in part["h"]]
            ax.plot(dates, part["forecast"], color=SERIES[key], linewidth=2,
                    label=SHORT[key] if j == 0 else None)
    for o in origins:
        ax.axvline(o, color=MUTED, linewidth=0.8, linestyle=(0, (3, 3)))
    ax.set_ylabel("Units sold per day", color=INK_2, fontsize=9)
    ax.legend(loc="upper left", ncol=4, frameon=False, fontsize=9, labelcolor=INK)
    ax.set_ylim(0, max(actual.max(), sub["forecast"].max()) * 1.25)
    fig.suptitle(f"{product}: three 14-day forecasts against what actually sold",
                 x=0.02, ha="left", fontsize=12, fontweight="bold", color=INK)
    ax.set_title("Dashed lines mark the forecast origin: each model saw only the sales to the left of it",
                 loc="left", fontsize=9, color=INK_2)
    ax.xaxis.set_major_locator(mdates.WeekdayLocator(byweekday=mdates.MO))
    ax.xaxis.set_major_formatter(mdates.DateFormatter("%d %b"))
    fig.tight_layout()
    fig.savefig(path, facecolor=SURFACE)
    plt.close(fig)
