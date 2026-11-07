<?php
require_once __DIR__ . '/../app/bootstrap.php';

$branchId = $_GET['branch'] ?? '';
$user = require_branch_access($branchId);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ?');
$stmt->execute([$branchId]);
$branch = $stmt->fetch();
if (!$branch) { http_response_code(404); die('Branch not found.'); }

// Real data from the audit log: units received vs. units sold, per category, per month.
$stmt = $pdo->prepare(
    "SELECT DATE_FORMAT(t.created_at, '%Y-%m') AS ym, p.category,
            SUM(CASE WHEN t.type = 'stock_in'  THEN t.quantity ELSE 0 END) AS received,
            SUM(CASE WHEN t.type = 'stock_out' THEN t.quantity ELSE 0 END) AS sold
     FROM transactions t JOIN products p ON p.id = t.product_id
     WHERE t.branch_id = ? AND t.created_at >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 5 MONTH)
     GROUP BY ym, p.category ORDER BY ym"
);
$stmt->execute([$branchId]);
$rows = $stmt->fetchAll();

// Build the last 6 month labels, oldest first.
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[] = date('Y-m', strtotime("first day of -$i month"));
}
$categories = array_values(array_unique(array_column($rows, 'category')));
$series = [];
foreach ($categories as $cat) {
    $data = [];
    foreach ($months as $m) {
        $net = 0;
        foreach ($rows as $r) {
            if ($r['ym'] === $m && $r['category'] === $cat) {
                $net = (int)$r['received'] - (int)$r['sold'];
            }
        }
        $data[] = $net;
    }
    $series[] = ['label' => $cat, 'data' => $data, 'borderColor' => category_color($cat), 'backgroundColor' => category_color($cat) . '30'];
}

$pageTitle = "Trends — {$branch['name']}";
require __DIR__ . '/../app/Views/partials/head.php';
$active = 'trends';
require __DIR__ . '/../app/Views/partials/nav_branch.php';
?>
<div class="container-xl py-4">
  <h1 class="h6 text-faint text-uppercase mb-1" style="letter-spacing:.08em">Stock Trends — <?= e($branch['name']) ?></h1>
  <p class="text-faint small mb-4">Net stock movement per category (received minus sold), last 6 months. Click a legend item to hide or show it.</p>

  <?php if (!$rows): ?>
    <div class="card-stockwise p-5 text-center text-faint">No stock transactions recorded in the last 6 months yet. Trends appear as Branch Staff record stock-ins and stock-outs.</div>
  <?php else: ?>
    <div class="card-stockwise p-3" style="height:420px"><canvas id="trendChart"></canvas></div>
  <?php endif; ?>
</div>
<?php if ($rows): ?>
<script>
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_map(fn($m) => date('M Y', strtotime($m . '-01')), $months)) ?>,
    datasets: <?= json_encode(array_map(fn($s) => $s + ['fill' => true, 'tension' => 0.35, 'borderWidth' => 2, 'pointRadius' => 3], $series)) ?>
  },
  options: { responsive: true, maintainAspectRatio: false,
    plugins: { legend: { labels: { color: '#9ca3af' } } },
    scales: { x: { ticks: { color: '#6b7280' }, grid: { display: false } }, y: { ticks: { color: '#6b7280' }, grid: { color: '#1e2235' } } } }
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
