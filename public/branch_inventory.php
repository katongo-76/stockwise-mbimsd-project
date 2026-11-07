<?php
require_once __DIR__ . '/../app/bootstrap.php';

$branchId = $_GET['branch'] ?? '';
$user = require_branch_access($branchId);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ?');
$stmt->execute([$branchId]);
$branch = $stmt->fetch();
if (!$branch) { http_response_code(404); die('Branch not found.'); }

// Whitelisted sort columns — never interpolate user input into SQL.
$sortable = ['name' => 'name', 'category' => 'category', 'stock' => 'current_quantity', 'threshold' => 'low_stock_threshold'];
$sort = $_GET['sort'] ?? 'stock';
$sortCol = $sortable[$sort] ?? 'current_quantity';
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$stmt = $pdo->prepare("SELECT * FROM products WHERE branch_id = ? ORDER BY $sortCol $dir");
$stmt->execute([$branchId]);
$products = $stmt->fetchAll();
$totalStock = array_sum(array_column($products, 'current_quantity'));

$stmt = $pdo->prepare(
    "SELECT product_id, SUM(quantity) AS sold FROM transactions
     WHERE branch_id = ? AND type = 'stock_out' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY product_id"
);
$stmt->execute([$branchId]);
$sold = array_column($stmt->fetchAll(), 'sold', 'product_id');

function sort_link(string $col, string $label, string $current, string $dir, string $branchId): string
{
    $nextDir = ($current === $col && $dir === 'DESC') ? 'asc' : 'desc';
    $arrow = $current === $col ? ($dir === 'ASC' ? ' ↑' : ' ↓') : '';
    return '<a class="text-faint text-decoration-none" href="?branch=' . urlencode($branchId) . "&sort=$col&dir=$nextDir\">" . e($label) . $arrow . '</a>';
}

$pageTitle = "Inventory — {$branch['name']}";
require __DIR__ . '/../app/Views/partials/head.php';
$active = 'inventory';
require __DIR__ . '/../app/Views/partials/nav_branch.php';
?>
<div class="container-xl py-4">
  <h1 class="h6 text-faint text-uppercase mb-1" style="letter-spacing:.08em">Full Inventory — <?= e($branch['name']) ?></h1>
  <p class="text-faint small mb-3">All products · click column headers to sort</p>
  <div class="d-flex gap-4 small text-faint mb-3">
    <span><span class="mono text-light"><?= fmt($totalStock) ?></span> total units</span>
    <span><span class="mono text-light"><?= count($products) ?></span> products</span>
  </div>

  <div class="card-stockwise">
    <table class="table table-stockwise table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th class="ps-3"><?= sort_link('name', 'Product', $sort, $dir, $branchId) ?></th>
          <th><?= sort_link('category', 'Category', $sort, $dir, $branchId) ?></th>
          <th><?= sort_link('stock', 'In Stock', $sort, $dir, $branchId) ?></th>
          <th>Sold (6mo)</th>
          <th><?= sort_link('threshold', 'Reorder Pt.', $sort, $dir, $branchId) ?></th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p):
          $isLow = $p['current_quantity'] <= $p['low_stock_threshold']; ?>
          <tr>
            <td class="ps-3 fw-medium"><?= e($p['name']) ?></td>
            <td><span class="dot me-2" style="background:<?= category_color($p['category']) ?>"></span><?= e($p['category']) ?></td>
            <td class="mono"><?= fmt($p['current_quantity']) ?> <span class="text-faint"><?= e($p['unit_of_measure']) ?></span></td>
            <td class="mono text-dim"><?= fmt((int)($sold[$p['id']] ?? 0)) ?></td>
            <td class="mono text-faint"><?= fmt($p['low_stock_threshold']) ?></td>
            <td><span class="badge <?= $isLow ? 'badge-expired' : 'badge-fresh' ?>"><?= $isLow ? 'Reorder' : 'OK' ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="6" class="text-center text-faint py-4">No products recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
