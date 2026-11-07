<?php

/**
 * Reads the results of the nightly ML forecast (ml/forecast_job.py).
 * PHP never runs the model; it only displays what the job wrote to the
 * forecasts, forecast_daily and forecast_batch_risk tables.
 */
class ForecastService
{
    public const STATUS = [
        'critical'  => ['label' => 'Runs out before delivery', 'short' => 'Critical', 'color' => 'var(--red)',    'bg' => '#FF6B6B22'],
        'reorder'   => ['label' => 'Reorder now',              'short' => 'Reorder',  'color' => 'var(--orange)', 'bg' => '#F5A62322'],
        'ok'        => ['label' => 'Enough stock',             'short' => 'OK',       'color' => 'var(--green)',  'bg' => '#50E3A422'],
        'no_demand' => ['label' => 'No sales forecast',        'short' => 'No demand','color' => 'var(--text-faint)', 'bg' => 'var(--border)'],
    ];

    public const MODEL_LABELS = [
        'random_forest'  => 'Random forest',
        'holt_winters'   => 'Holt-Winters',
        'moving_average' => 'Moving average',
        'seasonal_naive' => 'Same weekday last week',
        'no_history'     => 'No sales history',
    ];

    /** True when the ML tables exist (ml_migration.sql has been run). */
    public static function available(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM forecasts LIMIT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** Per-product forecast rows for one branch, most urgent first. */
    public static function forBranch(PDO $pdo, string $branchId): array
    {
        $stmt = $pdo->prepare(
            "SELECT f.*, p.name, p.category, p.unit_of_measure, p.current_quantity
             FROM forecasts f JOIN products p ON p.id = f.product_id
             WHERE f.branch_id = ?
             ORDER BY FIELD(f.status, 'critical', 'reorder', 'ok', 'no_demand'),
                      f.days_until_stockout IS NULL, f.days_until_stockout, p.name"
        );
        $stmt->execute([$branchId]);
        return $stmt->fetchAll();
    }

    /** Batches with stock forecast to expire unsold, largest risk first. */
    public static function expiryRisk(PDO $pdo, string $branchId): array
    {
        $stmt = $pdo->prepare(
            "SELECT r.*, p.name, p.unit_of_measure, sb.barcode
             FROM forecast_batch_risk r
             JOIN products p ON p.id = r.product_id
             JOIN stock_batches sb ON sb.id = r.batch_id
             WHERE r.branch_id = ? AND r.at_risk_quantity > 0
             ORDER BY r.expiry_date, r.at_risk_quantity DESC"
        );
        $stmt->execute([$branchId]);
        return $stmt->fetchAll();
    }

    /**
     * Headline figures per branch for the manager dashboard:
     * [branch_id => ['critical' => n, 'reorder' => n, 'at_risk' => units]]
     */
    public static function companySummary(PDO $pdo, int $companyId): array
    {
        $out = [];
        $stmt = $pdo->prepare(
            "SELECT f.branch_id, SUM(f.status = 'critical') AS critical, SUM(f.status = 'reorder') AS reorder
             FROM forecasts f JOIN branches b ON b.id = f.branch_id
             WHERE b.company_id = ? GROUP BY f.branch_id"
        );
        $stmt->execute([$companyId]);
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['branch_id']] = ['critical' => (int)$r['critical'], 'reorder' => (int)$r['reorder'], 'at_risk' => 0];
        }
        $stmt = $pdo->prepare(
            "SELECT r.branch_id, SUM(r.at_risk_quantity) AS units
             FROM forecast_batch_risk r JOIN branches b ON b.id = r.branch_id
             WHERE b.company_id = ? GROUP BY r.branch_id"
        );
        $stmt->execute([$companyId]);
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['branch_id']] ??= ['critical' => 0, 'reorder' => 0, 'at_risk' => 0];
            $out[$r['branch_id']]['at_risk'] = (int)$r['units'];
        }
        return $out;
    }

    /**
     * The 28 days of actual daily sales leading up to the forecast, + the 14-day forecast, for one product's chart.
     *
     * The sales window ends on the last day the branch recorded any sales (at most yesterday), not on the
     * calendar day before today. If a few days pass between the last recorded sales and the forecast run
     * (for example sales history generated, then the demo a few days later), those unrecorded days would
     * otherwise be drawn as a flat line at zero, which is not real data.
     */
    public static function chartSeries(PDO $pdo, int $productId): array
    {
        $stmt = $pdo->prepare(
            "SELECT DATE(MAX(t.created_at)) FROM transactions t
             JOIN products p ON p.id = t.product_id
             WHERE p.branch_id = (SELECT branch_id FROM products WHERE id = ?)
               AND t.type = 'stock_out' AND t.created_at < CURDATE()"
        );
        $stmt->execute([$productId]);
        $last = $stmt->fetchColumn() ?: date('Y-m-d', strtotime('-1 day'));
        $start = date('Y-m-d', strtotime("$last -27 day"));

        $stmt = $pdo->prepare(
            "SELECT DATE(created_at) AS d, SUM(quantity) AS q FROM transactions
             WHERE product_id = ? AND type = 'stock_out'
               AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
             GROUP BY DATE(created_at)"
        );
        $stmt->execute([$productId, $start, $last]);
        $sold = array_column($stmt->fetchAll(), 'q', 'd');

        $labels = [];
        $actual = [];
        for ($i = 27; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("$last -$i day"));
            $labels[] = $d;
            $actual[] = (float)($sold[$d] ?? 0);
        }

        $stmt = $pdo->prepare('SELECT forecast_date, quantity FROM forecast_daily WHERE product_id = ? ORDER BY forecast_date');
        $stmt->execute([$productId]);
        $forecast = array_fill(0, count($labels), null);
        $forecast[count($labels) - 1] = end($actual);   // join the two lines
        foreach ($stmt->fetchAll() as $r) {
            $labels[] = $r['forecast_date'];
            $actual[] = null;
            $forecast[] = (float)$r['quantity'];
        }
        return ['labels' => $labels, 'actual' => $actual, 'forecast' => $forecast];
    }
}
