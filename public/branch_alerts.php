<?php
require_once __DIR__ . '/../app/bootstrap.php';

$branchId = $_GET['branch'] ?? '';
$user = require_branch_access($branchId);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ?');
$stmt->execute([$branchId]);
$branch = $stmt->fetch();
if (!$branch) { http_response_code(404); die('Branch not found.'); }

$stmt = $pdo->prepare('SELECT * FROM products WHERE branch_id = ?');
$stmt->execute([$branchId]);
$products = $stmt->fetchAll();

usort($products, fn($a, $b) =>
    ($a['current_quantity'] / max(1, $a['low_stock_threshold'])) <=> ($b['current_quantity'] / max(1, $b['low_stock_threshold']))
);

$critical = array_filter($products, fn($p) => $p['current_quantity'] / max(1, $p['low_stock_threshold']) < 1);
$low      = array_filter($products, function ($p) {
    $r = $p['current_quantity'] / max(1, $p['low_stock_threshold']);
    return $r >= 1 && $r < 2;
});
$ok       = array_filter($products, fn($p) => $p['current_quantity'] / max(1, $p['low_stock_threshold']) >= 2);

function urgency(array $p): array
{
    $ratio = $p['current_quantity'] / max(1, $p['low_stock_threshold']);
    if ($ratio < 1) return ['label' => 'Critical', 'color' => 'var(--red)', 'bg' => '#FF6B6B22'];
    if ($ratio < 2) return ['label' => 'Low', 'color' => 'var(--orange)', 'bg' => '#F5A62322'];
    return ['label' => 'OK', 'color' => 'var(--green)', 'bg' => '#50E3A422'];
}

$pageTitle = "Low Stock — {$branch['name']}";
require __DIR__ . '/../app/Views/partials/head.php';
$active = 'alerts';
require __DIR__ . '/../app/Views/partials/nav_branch.php';
?>
<div class="container-md py-4" style="max-width:760px">
  <h1 class="h6 text-faint text-uppercase mb-1" style="letter-spacing:.08em">Low Stock Alerts — <?= e($branch['name']) ?></h1>
  <p class="text-faint small mb-4">Products ranked by stock level against their reorder threshold.</p>

  <div class="d-flex gap-3 mb-4">
    <div class="card-stockwise px-3 py-2 d-flex align-items-center gap-2" style="background:#FF6B6B22"><span class="fs-4 fw-semibold mono" style="color:var(--red)"><?= count($critical) ?></span><span class="small">Critical</span></div>
    <div class="card-stockwise px-3 py-2 d-flex align-items-center gap-2" style="background:#F5A62322"><span class="fs-4 fw-semibold mono" style="color:var(--orange)"><?= count($low) ?></span><span class="small">Low</span></div>
    <div class="card-stockwise px-3 py-2 d-flex align-items-center gap-2" style="background:#50E3A422"><span class="fs-4 fw-semibold mono" style="color:var(--green)"><?= count($ok) ?></span><span class="small">OK</span></div>
  </div>

  <div class="d-flex flex-column gap-3">
    <?php foreach ($products as $p): $u = urgency($p); $pct = min(100, round($p['current_quantity'] / max(1, $p['low_stock_threshold'] * 4) * 100)); ?>
      <div class="card-stockwise p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="d-flex align-items-center gap-2"><span class="dot" style="background:<?= category_color($p['category']) ?>"></span><span class="fw-medium"><?= e($p['name']) ?></span></div>
          <div class="d-flex align-items-center gap-2"><span class="badge" style="color:<?= $u['color'] ?>;background:<?= $u['bg'] ?>"><?= $u['label'] ?></span><span class="mono"><?= fmt($p['current_quantity']) ?> <span class="text-faint small"><?= e($p['unit_of_measure']) ?></span></span></div>
        </div>
        <div class="progress mb-2" style="height:6px;background:var(--border)"><div class="progress-bar" style="width:<?= $pct ?>%;background:<?= category_color($p['category']) ?>"></div></div>
        <div class="d-flex gap-4 mono small text-faint">
          <span>reorder at <?= fmt($p['low_stock_threshold']) ?></span>
          <span>need <?= fmt(max(0, $p['low_stock_threshold'] * 2 - $p['current_quantity'])) ?> more to clear warning</span>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$products): ?><p class="text-faint">No products recorded yet.</p><?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
