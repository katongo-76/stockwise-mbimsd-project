<?php
require_once __DIR__ . '/../app/bootstrap.php';
$user = require_manager();
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM branches WHERE company_id = ? ORDER BY name');
$stmt->execute([$user['company_id']]);
$branches = $stmt->fetchAll();

$branchStats = [];
foreach ($branches as $b) {
    $p = $pdo->prepare('SELECT category, SUM(current_quantity) AS stock FROM products WHERE branch_id = ? GROUP BY category');
    $p->execute([$b['id']]);
    $byCategory = $p->fetchAll();

    $t = $pdo->prepare(
        'SELECT COUNT(*) AS total, SUM(current_quantity) AS stock,
                SUM(current_quantity <= low_stock_threshold AND current_quantity > 0) AS low,
                SUM(current_quantity <= 0) AS out_of_stock
         FROM products WHERE branch_id = ?'
    );
    $t->execute([$b['id']]);
    $totals = $t->fetch();

    // Batches still holding stock, by expiry status (same rule as the Expiry page).
    $x = $pdo->prepare(
        'SELECT sb.expiry_date, p.expiry_alert_days FROM stock_batches sb JOIN products p ON p.id = sb.product_id
         WHERE p.branch_id = ? AND sb.quantity_remaining > 0 AND sb.expiry_date IS NOT NULL'
    );
    $x->execute([$b['id']]);
    $expiring = 0; $expired = 0;
    foreach ($x->fetchAll() as $row) {
        $st = ExpiryService::status($row['expiry_date'], (int)$row['expiry_alert_days']);
        if ($st === 'warning') $expiring++;
        if ($st === 'expired') $expired++;
    }

    $branchStats[$b['id']] = [
        'byCategory'  => $byCategory,
        'totalStock'  => (int)($totals['stock'] ?? 0),
        'lowCount'    => (int)($totals['low'] ?? 0),
        'outCount'    => (int)($totals['out_of_stock'] ?? 0),
        'expiringCount' => $expiring,
        'expiredCount'  => $expired,
        'productCount' => (int)($totals['total'] ?? 0),
    ];
}
$forecast = ForecastService::available($pdo) ? ForecastService::companySummary($pdo, (int)$user['company_id']) : [];
$maxStock = max([1, ...array_values(array_map(fn($s) => $s['totalStock'], $branchStats))]);

$pageTitle = 'All Branches — Stockwise';
require __DIR__ . '/../app/Views/partials/head.php';
$active = 'overview';
require __DIR__ . '/../app/Views/partials/nav_manager.php';
?>
<div class="container-xl py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h1 class="h6 text-faint text-uppercase mb-1" style="letter-spacing:.08em">All Branches</h1>
      <p class="text-faint small mb-0">Select a branch to view its full inventory dashboard.</p>
    </div>
    <a href="/branches.php" class="btn btn-outline-secondary btn-sm">Manage branches &rarr;</a>
  </div>

  <?php if (!$branches): ?>
    <div class="card-stockwise p-5 text-center">
      <p class="text-faint mb-3">No branches yet.</p>
      <a href="/branches.php" class="text-decoration-none">Add your first branch &rarr;</a>
    </div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($branches as $b): $s = $branchStats[$b['id']]; $pct = round($s['totalStock'] / $maxStock * 100); ?>
        <div class="col-md-6">
          <a href="/branch.php?branch=<?= urlencode($b['id']) ?>" class="card-stockwise p-3 d-block text-decoration-none text-reset h-100">
            <div class="d-flex justify-content-between mb-3">
              <div>
                <div class="d-flex align-items-center gap-2">
                  <span class="fw-semibold"><?= e($b['name']) ?></span>
                  <span class="badge bg-transparent border text-faint"><?= e($b['country']) ?></span>
                </div>
                <div class="text-faint small mt-1">Branch manager: <?= e($b['manager'] ?: '—') ?></div>
              </div>
              <?php if ($s['outCount'] + $s['expiredCount'] > 0): ?>
                <span class="badge-expired badge rounded-pill align-self-start">Needs attention</span>
              <?php elseif ($s['lowCount'] + $s['expiringCount'] > 0): ?>
                <span class="badge-warning badge rounded-pill align-self-start">Watch</span>
              <?php else: ?>
                <span class="badge-fresh badge rounded-pill align-self-start">Healthy</span>
              <?php endif; ?>
            </div>
            <div class="row g-2 text-center mb-3">
              <?php foreach ([
                  ['Out of stock', $s['outCount'], ExpiryService::COLORS['expired']],
                  ['Low stock', $s['lowCount'], ExpiryService::COLORS['warning']],
                  ['Expiring soon', $s['expiringCount'], ExpiryService::COLORS['warning']],
                  ['Expired', $s['expiredCount'], ExpiryService::COLORS['expired']],
              ] as [$label, $n, $c]): ?>
                <div class="col-3">
                  <div class="mono fw-semibold" style="color:<?= $n ? $c : 'var(--text-faint)' ?>"><?= $n ?></div>
                  <div class="text-faint" style="font-size:.72rem"><?= $label ?></div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between small mb-1">
              <span class="text-faint">Total stock</span>
              <span class="mono"><?= fmt($s['totalStock']) ?> units</span>
            </div>
            <div class="progress" style="height:6px;background:var(--border)">
              <div class="progress-bar" style="width:<?= $pct ?>%;background:var(--blue)"></div>
            </div>
            <?php if (isset($forecast[$b['id']])): $f = $forecast[$b['id']]; ?>
              <div class="d-flex flex-wrap gap-3 small mt-3 mono">
                <span style="color:<?= $f['critical'] ? 'var(--red)' : 'var(--text-faint)' ?>"><?= $f['critical'] ?> run out before delivery</span>
                <span style="color:<?= $f['critical'] + $f['reorder'] ? 'var(--orange)' : 'var(--text-faint)' ?>"><?= $f['critical'] + $f['reorder'] ?> need ordering</span>
                <span style="color:<?= $f['at_risk'] ? '#A78BFA' : 'var(--text-faint)' ?>"><?= fmt($f['at_risk']) ?> units may expire unsold</span>
              </div>
            <?php endif; ?>
            <div class="d-flex justify-content-between small mt-3 pt-2 border-top" style="border-color:var(--border)!important">
              <span class="text-faint mono"><?= $s['productCount'] ?> products</span>
              <span style="color:var(--blue)">View dashboard &rarr;</span>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
