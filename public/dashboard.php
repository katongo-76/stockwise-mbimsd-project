<?php
require_once __DIR__ . '/../app/bootstrap.php';
$user = require_manager();
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM branches WHERE company_id = ? ORDER BY name');
$stmt->execute([$user['company_id']]);
$branches = $stmt->fetchAll();

$branchStats = [];
foreach ($branches as $b) {
    $t = $pdo->prepare('SELECT COUNT(*) AS total, SUM(current_quantity) AS stock,
                               SUM(current_quantity <= low_stock_threshold) AS low
                        FROM products WHERE branch_id = ?');
    $t->execute([$b['id']]);
    $branchStats[$b['id']] = $t->fetch();
}

$pageTitle = 'All Branches — Stockwise';
require __DIR__ . '/../app/Views/partials/head.php';
$active = 'overview';
require __DIR__ . '/../app/Views/partials/nav_manager.php';
?>
<div class="container-xl py-4">
  <h1 class="h6 text-faint text-uppercase mb-1" style="letter-spacing:.08em">All Branches</h1>
  <p class="text-faint small mb-4">Select a branch to view its inventory (read-only).</p>
  <div class="row g-3">
    <?php foreach ($branches as $b): $s = $branchStats[$b['id']]; ?>
      <div class="col-md-6">
        <a href="/branch.php?branch=<?= urlencode($b['id']) ?>" class="card-stockwise p-3 d-block text-decoration-none text-reset h-100">
          <div class="fw-semibold"><?= e($b['name']) ?> <span class="badge bg-transparent border text-faint"><?= e($b['country']) ?></span></div>
          <div class="text-faint small mt-1 mb-3">Branch manager: <?= e($b['manager'] ?: '—') ?></div>
          <div class="d-flex justify-content-between small mono">
            <span><?= fmt((int)($s['stock'] ?? 0)) ?> units</span>
            <span><?= (int)($s['total'] ?? 0) ?> products</span>
            <span style="color:<?= ($s['low'] ?? 0) ? 'var(--red)' : 'var(--text-faint)' ?>"><?= (int)($s['low'] ?? 0) ?> low stock</span>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
