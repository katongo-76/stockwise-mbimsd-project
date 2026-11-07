<?php
require_once __DIR__ . '/../app/bootstrap.php';

$branchId = $_GET['branch'] ?? '';
$user = require_branch_access($branchId);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ?');
$stmt->execute([$branchId]);
$branch = $stmt->fetch();
if (!$branch) {
    http_response_code(404);
    die('Branch not found.');
}

$stmt = $pdo->prepare('SELECT * FROM products WHERE branch_id = ? ORDER BY category, name');
$stmt->execute([$branchId]);
$products = $stmt->fetchAll();

$totalStock = array_sum(array_column($products, 'current_quantity'));
$lowStockItems = array_filter($products, fn($p) => $p['current_quantity'] <= $p['low_stock_threshold']);

$byCategory = [];
foreach ($products as $p) {
    $byCategory[$p['category']] = ($byCategory[$p['category']] ?? 0) + (int)$p['current_quantity'];
}


$pageTitle = "Overview — {$branch['name']}";
require __DIR__ . '/../app/Views/partials/head.php';
$active = 'overview';
require __DIR__ . '/../app/Views/partials/nav_branch.php';
?>
<div class="container-xl py-4">
  <h1 class="h6 text-faint text-uppercase mb-3" style="letter-spacing:.08em">Overview — <?= e($branch['name']) ?></h1>

  <div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card-stockwise stat-card p-3"><div class="stat-label">Total Stock</div><div class="stat-value"><?= fmt($totalStock) ?></div><div class="text-faint mono small">units on hand</div></div></div>
    <div class="col-md-3"><div class="card-stockwise stat-card p-3" style="border-top-color:var(--green)"><div class="stat-label">Products</div><div class="stat-value"><?= count($products) ?></div><div class="text-faint mono small">active SKUs</div></div></div>
    <div class="col-md-3"><div class="card-stockwise stat-card p-3" style="border-top-color:#A78BFA"><div class="stat-label">Categories</div><div class="stat-value"><?= count($byCategory) ?></div><div class="text-faint mono small">product lines</div></div></div>
    <div class="col-md-3"><div class="card-stockwise stat-card p-3" style="border-top-color:var(--red)"><div class="stat-label">Low Stock</div><div class="stat-value"><?= count($lowStockItems) ?></div><div class="text-faint mono small">at/below threshold</div></div></div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-12">
      <div class="card-stockwise p-3" style="height:280px">
        <h2 class="h6 mb-3">Stock by Category</h2>
        <div style="height:210px"><canvas id="barChart"></canvas></div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-4">
      <a href="/branch_inventory.php?branch=<?= urlencode($branchId) ?>" class="card-stockwise p-3 d-block text-decoration-none text-reset h-100">
        <div class="fw-semibold mb-2">Full Inventory</div>
        <p class="text-faint small">Complete breakdown of all products with stock counts and reorder status.</p>
        <div class="pt-2 border-top" style="border-color:#50E3A440!important"><span class="fs-5 fw-semibold" style="color:var(--green)"><?= fmt($totalStock) ?></span><span class="text-faint small ms-2">total units</span></div>
      </a>
    </div>
  </div>
</div>

<script>
new Chart(document.getElementById('barChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_keys($byCategory)) ?>,
    datasets: [{ data: <?= json_encode(array_values($byCategory)) ?>, backgroundColor: <?= json_encode(array_map('category_color', array_keys($byCategory))) ?>, borderRadius: 3 }]
  },
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
    scales: { x: { ticks: { color: '#6b7280' }, grid: { display: false } }, y: { ticks: { color: '#6b7280' }, grid: { color: '#1e2235' } } } }
});
</script>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
