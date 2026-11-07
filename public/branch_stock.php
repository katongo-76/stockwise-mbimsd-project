<?php
require_once __DIR__ . '/../app/bootstrap.php';

$branchId = $_GET['branch'] ?? '';
$user = require_branch_access($branchId);

// Per the proposal, the manager view is read-only oversight — operational
// stock changes belong to Inventory Clerks only. Enforced server-side.
if ($user['role'] !== 'employee') {
    header('Location: /branch.php?branch=' . urlencode($branchId));
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ?');
$stmt->execute([$branchId]);
$branch = $stmt->fetch();
if (!$branch) { http_response_code(404); die('Branch not found.'); }

const CATEGORIES = ['Electronics', 'Apparel', 'Food & Beverage', 'Tools', 'Furniture', 'Books', 'Sporting Goods', 'Health'];

$tab = $_GET['tab'] ?? 'in';
if (!in_array($tab, ['in', 'out', 'products'], true)) $tab = 'in';
$notice = null;
$error = null;

function branch_product(PDO $pdo, int $productId, string $branchId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND branch_id = ?');
    $stmt->execute([$productId, $branchId]);
    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_product') {
            $name = trim($_POST['name'] ?? '');
            $category = in_array($_POST['category'] ?? '', CATEGORIES, true) ? $_POST['category'] : CATEGORIES[0];
            if (!$name) throw new InvalidArgumentException('Product name is required.');
            $pdo->prepare('INSERT INTO products (branch_id, name, category, unit_of_measure, low_stock_threshold, expiry_alert_days) VALUES (?,?,?,?,?,?)')
                ->execute([$branchId, $name, $category, trim($_POST['unit'] ?? 'unit') ?: 'unit', max(0, (int)($_POST['low_stock_threshold'] ?? 10)), max(1, (int)($_POST['expiry_alert_days'] ?? 14))]);
            $notice = "Product added. Record a stock-in to add quantity.";
            $tab = 'products';
        }

        if ($action === 'update_product') {
            $product = branch_product($pdo, (int)$_POST['product_id'], $branchId);
            if (!$product) throw new RuntimeException('Product not found.');
            $category = in_array($_POST['category'] ?? '', CATEGORIES, true) ? $_POST['category'] : $product['category'];
            $pdo->prepare('UPDATE products SET name=?, category=?, unit_of_measure=?, low_stock_threshold=?, expiry_alert_days=? WHERE id=?')
                ->execute([trim($_POST['name'] ?? $product['name']), $category, trim($_POST['unit'] ?? 'unit') ?: 'unit', max(0, (int)$_POST['low_stock_threshold']), max(1, (int)$_POST['expiry_alert_days']), $product['id']]);
            $notice = 'Product updated.';
            $tab = 'products';
        }

        if ($action === 'delete_product') {
            $product = branch_product($pdo, (int)$_POST['product_id'], $branchId);
            if (!$product) throw new RuntimeException('Product not found.');
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$product['id']]);
            $notice = 'Product removed.';
            $tab = 'products';
        }

        if ($action === 'stock_in') {
            $product = branch_product($pdo, (int)$_POST['product_id'], $branchId);
            if (!$product) throw new RuntimeException('Select a product.');
            $result = StockCalculationService::recordStockIn(
                $pdo, $product['id'], $branchId, (int)$_POST['quantity'],
                $_POST['expiry_date'] ?: null,
                $_POST['received_date'] ?: date('Y-m-d'),
                trim($_POST['barcode'] ?? ''),
                $user['id']
            );
            $notice = "Stock-in recorded. {$result['product']['name']} now at {$result['product']['current_quantity']}.";
            $tab = 'in';
        }

        if ($action === 'stock_out') {
            $product = branch_product($pdo, (int)$_POST['product_id'], $branchId);
            if (!$product) throw new RuntimeException('Select a product.');
            $result = StockCalculationService::recordStockOut($pdo, $product['id'], $branchId, (int)$_POST['quantity'], $user['id']);
            $notice = "Stock-out recorded. {$result['product']['name']} now at {$result['product']['current_quantity']}."
                . ($result['alertSent'] ? ' ⚠ Now at or below its threshold: low-stock alert sent.'
                   : ($result['lowStock'] ? ' Still at or below its low-stock threshold.' : ''));
            $tab = 'out';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = $pdo->prepare('SELECT * FROM products WHERE branch_id = ? ORDER BY name');
$stmt->execute([$branchId]);
$products = $stmt->fetchAll();

$editing = null;
if ($tab === 'products' && !empty($_GET['edit'])) {
    $editing = branch_product($pdo, (int)$_GET['edit'], $branchId);
}

function product_options(array $products): string
{
    $html = '<option value="">— Select a product —</option>';
    foreach ($products as $p) {
        $html .= '<option value="' . (int)$p['id'] . '">' . e($p['name']) . ' (' . (int)$p['current_quantity'] . ' ' . e($p['unit_of_measure']) . ')</option>';
    }
    return $html;
}

$pageTitle = "Stock Management — {$branch['name']}";
require __DIR__ . '/../app/Views/partials/head.php';
$active = 'stock';
require __DIR__ . '/../app/Views/partials/nav_branch.php';
$base = '?branch=' . urlencode($branchId);
?>
<div class="container-md py-4" style="max-width:760px">
  <h1 class="h6 text-faint text-uppercase mb-1" style="letter-spacing:.08em">Stock Management</h1>
  <p class="text-faint small mb-4"><?= count($products) ?> products · record deliveries, sales, and manage your product list</p>

  <div class="d-flex gap-2 mb-4">
    <a href="<?= $base ?>&tab=in" class="btn btn-sm <?= $tab === 'in' ? 'btn-outline-success' : 'btn-outline-secondary' ?>">Stock In</a>
    <a href="<?= $base ?>&tab=out" class="btn btn-sm <?= $tab === 'out' ? 'btn-outline-danger' : 'btn-outline-secondary' ?>">Stock Out</a>
    <a href="<?= $base ?>&tab=products" class="btn btn-sm <?= $tab === 'products' ? 'btn-outline-primary' : 'btn-outline-secondary' ?>">Manage Products</a>
  </div>

  <?php if ($notice): ?><div class="alert alert-success py-2 small"><?= e($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endif; ?>

  <?php if ($tab === 'in'): ?>
    <p class="text-faint small">Each delivery creates its own batch with its own expiry date.</p>
    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="stock_in">
      <div><label class="form-label">Product</label><select name="product_id" class="form-select" required><?= product_options($products) ?></select></div>
      <div class="row g-3">
        <div class="col-6"><label class="form-label">Quantity Received</label><input type="number" name="quantity" min="1" value="1" class="form-control" required></div>
        <div class="col-6"><label class="form-label">Barcode</label><input type="text" name="barcode" class="form-control mono"></div>
      </div>
      <div class="row g-3">
        <div class="col-6"><label class="form-label">Received Date</label><input type="date" name="received_date" value="<?= date('Y-m-d') ?>" class="form-control"></div>
        <div class="col-6"><label class="form-label">Expiry Date (optional)</label><input type="date" name="expiry_date" class="form-control"></div>
      </div>
      <button type="submit" class="btn btn-success" style="align-self:flex-start">Record Stock-In</button>
    </form>

  <?php elseif ($tab === 'out'): ?>
    <p class="text-faint small">Stock is deducted from the batch expiring soonest first (FEFO).</p>
    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="stock_out">
      <div><label class="form-label">Product</label><select name="product_id" class="form-select" required><?= product_options($products) ?></select></div>
      <div><label class="form-label">Quantity to Deduct</label><input type="number" name="quantity" min="1" value="1" class="form-control" required></div>
      <button type="submit" class="btn btn-danger" style="align-self:flex-start">Record Stock-Out</button>
    </form>

  <?php else: ?>
    <?php if ($editing): ?>
      <h2 class="h6 mb-3">Edit product</h2>
      <form method="post" class="d-flex flex-column gap-3 mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_product">
        <input type="hidden" name="product_id" value="<?= (int)$editing['id'] ?>">
        <div><label class="form-label">Name</label><input type="text" name="name" class="form-control" value="<?= e($editing['name']) ?>"></div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label">Category</label>
            <select name="category" class="form-select"><?php foreach (CATEGORIES as $c): ?><option <?= $c === $editing['category'] ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label">Unit</label><input type="text" name="unit" class="form-control" value="<?= e($editing['unit_of_measure']) ?>"></div>
        </div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label">Low-stock threshold</label><input type="number" name="low_stock_threshold" min="0" class="form-control" value="<?= (int)$editing['low_stock_threshold'] ?>"></div>
          <div class="col-6"><label class="form-label">Expiry alert (days before)</label><input type="number" name="expiry_alert_days" min="1" class="form-control" value="<?= (int)$editing['expiry_alert_days'] ?>"></div>
        </div>
        <div class="d-flex gap-2"><button class="btn btn-primary" type="submit">Save changes</button><a class="btn btn-outline-secondary" href="<?= $base ?>&tab=products">Cancel</a></div>
      </form>
    <?php else: ?>
      <h2 class="h6 mb-3">Add a product</h2>
      <form method="post" class="d-flex flex-column gap-3 mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_product">
        <div><label class="form-label">Name</label><input type="text" name="name" class="form-control" required></div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label">Category</label>
            <select name="category" class="form-select"><?php foreach (CATEGORIES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label">Unit</label><input type="text" name="unit" class="form-control" value="unit"></div>
        </div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label">Low-stock threshold</label><input type="number" name="low_stock_threshold" min="0" class="form-control" value="10"></div>
          <div class="col-6"><label class="form-label">Expiry alert (days before)</label><input type="number" name="expiry_alert_days" min="1" class="form-control" value="14"></div>
        </div>
        <button class="btn btn-success" type="submit" style="align-self:flex-start">Add product</button>
      </form>
    <?php endif; ?>

    <div class="card-stockwise">
      <table class="table table-stockwise mb-0 align-middle">
        <thead><tr><th class="ps-3">Product</th><th>Qty</th><th>Reorder</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($products as $p): ?>
            <tr>
              <td class="ps-3"><span class="dot me-2" style="background:<?= category_color($p['category']) ?>"></span><?= e($p['name']) ?></td>
              <td class="mono"><?= fmt($p['current_quantity']) ?></td>
              <td class="mono text-faint"><?= fmt($p['low_stock_threshold']) ?></td>
              <td class="text-end pe-3">
                <a class="btn btn-sm btn-outline-secondary" href="<?= $base ?>&tab=products&edit=<?= (int)$p['id'] ?>">Edit</a>
                <form method="post" class="d-inline" onsubmit="return confirm('Remove <?= e($p['name']) ?> and all its batches?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_product">
                  <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$products): ?><tr><td colspan="4" class="text-center text-faint py-4">No products yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
