<?php
/**
 * Daily alert sweep. Schedule with cron, e.g. every day at 07:00:
 *   0 7 * * * /usr/bin/php /var/www/stockwise/cron/check_alerts.php
 *
 * For each company: finds batches inside their product's expiry-alert
 * window (and already-expired batches) plus products at/below their
 * low-stock threshold, emails one digest per branch to that branch's
 * clerks, then one consolidated summary to the company's managers.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../app/Config/env.php';
require_once __DIR__ . '/../app/Config/database.php';
require_once __DIR__ . '/../app/Services/ExpiryService.php';
require_once __DIR__ . '/../app/Services/NotificationService.php';

$pdo = db();
$companies = $pdo->query('SELECT id, name FROM companies')->fetchAll();

foreach ($companies as $company) {
    $stmt = $pdo->prepare('SELECT id, name FROM branches WHERE company_id = ?');
    $stmt->execute([$company['id']]);
    $branches = $stmt->fetchAll();

    $summaryLines = [];

    foreach ($branches as $branch) {
        $lines = [];

        $stmt = $pdo->prepare(
            'SELECT sb.*, p.name AS product_name, p.unit_of_measure, p.expiry_alert_days
             FROM stock_batches sb JOIN products p ON p.id = sb.product_id
             WHERE p.branch_id = ? AND sb.expiry_date IS NOT NULL AND sb.quantity_remaining > 0'
        );
        $stmt->execute([$branch['id']]);
        $expiring = 0;
        foreach ($stmt->fetchAll() as $b) {
            $status = ExpiryService::status($b['expiry_date'], (int)$b['expiry_alert_days']);
            if ($status === 'warning' || $status === 'expired') {
                $expiring++;
                $label = $status === 'expired' ? 'EXPIRED' : 'expires';
                $lines[] = "- {$b['product_name']}: {$b['quantity_remaining']} {$b['unit_of_measure']} {$label} {$b['expiry_date']}";
            }
        }

        $stmt = $pdo->prepare('SELECT * FROM products WHERE branch_id = ? AND current_quantity <= low_stock_threshold');
        $stmt->execute([$branch['id']]);
        $lowStock = $stmt->fetchAll();
        foreach ($lowStock as $p) {
            $lines[] = "- LOW STOCK {$p['name']}: {$p['current_quantity']} {$p['unit_of_measure']} (threshold {$p['low_stock_threshold']})";
        }

        if ($lines) {
            NotificationService::dispatchBranchDigest($pdo, $branch['id'], implode("\n", $lines));
            $summaryLines[] = "{$branch['name']}: {$expiring} batch(es) expiring/expired, " . count($lowStock) . ' low-stock product(s)';
        } else {
            $summaryLines[] = "{$branch['name']}: all clear";
        }
    }

    if ($summaryLines) {
        NotificationService::dispatchManagerSummary($pdo, (int)$company['id'], implode("\n", $summaryLines));
    }
}

echo "Alert sweep complete for " . count($companies) . " company(ies).\n";
