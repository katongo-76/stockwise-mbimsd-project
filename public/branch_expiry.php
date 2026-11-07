<?php
require_once __DIR__ . '/../app/bootstrap.php';

$branchId = $_GET['branch'] ?? '';
$user = require_branch_access($branchId);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ?');
$stmt->execute([$branchId]);
$branch = $stmt->fetch();
if (!$branch) { http_response_code(404); die('Branch not found.'); }

// Clerks write off expired batches here; managers stay read-only.
$canWriteOff = $user['role'] === 'employee';
$notice = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canWriteOff) {
        http_response_code(403);
        die('General Managers have read-only access to branch stock.');
    }
    try {
        $result = StockCalculationService::writeOffExpiredBatch($pdo, (int)($_POST['batch_id'] ?? 0), $branchId, $user['id']);
        $notice = "Written off {$result['quantity']} expired unit(s) of {$result['product']['name']}; "
            . "{$result['product']['name']} now at {$result['product']['current_quantity']}."
           ;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = $pdo->prepare(
    'SELECT sb.*, p.name AS product_name, p.category, p.unit_of_measure, p.expiry_alert_days
     FROM stock_batches sb JOIN products p ON p.id = sb.product_id
     WHERE p.branch_id = ? AND sb.expiry_date IS NOT NULL AND sb.quantity_remaining > 0'
);
$stmt->execute([$branchId]);
$batches = $stmt->fetchAll();

foreach ($batches as &$b) {
    $b['status'] = ExpiryService::status($b['expiry_date'], (int)$b['expiry_alert_days']);
    $b['days'] = ExpiryService::daysUntil($b['expiry_date']);
}
unset($b);

$stats = ['fresh' => 0, 'warning' => 0, 'expired' => 0];
foreach ($batches as $b) { $stats[$b['status']]++; }

$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$order = ['expired' => 0, 'warning' => 1, 'fresh' => 2];
$visible = array_values(array_filter($batches, function ($b) use ($filter, $search) {
    if ($filter !== 'all' && $b['status'] !== $filter) return false;
    if ($search !== '') {
        $q = strtolower($search);
        return str_contains(strtolower($b['product_name']), $q)
            || str_contains(strtolower($b['category']), $q)
            || str_contains($b['barcode'], $search);
    }
    return true;
}));
usort($visible, fn($a, $b) => [$order[$a['status']], $a['expiry_date']] <=> [$order[$b['status']], $b['expiry_date']]);

$pageTitle = "Expiry — {$branch['name']}";
require __DIR__ . '/../app/Views/partials/head.php';
$active = 'expiry';
require __DIR__ . '/../app/Views/partials/nav_branch.php';
$base = '?branch=' . urlencode($branchId);
?>
<div class="container-xl py-4">
  <h1 class="h6 text-faint text-uppercase mb-1" style="letter-spacing:.08em">Expiry Dashboard — <?= e($branch['name']) ?></h1>
  <p class="text-faint small mb-4">Tracking <?= count($batches) ?> batches with expiry dates. Each delivery is tracked separately.</p>
  <?php if ($notice): ?><div class="alert alert-success py-2 small"><?= e($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endif; ?>

  <div class="row g-3 mb-4">
    <?php foreach (['fresh' => 'items safe to sell', 'warning' => 'inside the alert window', 'expired' => 'past expiry date'] as $s => $sub): ?>
      <div class="col-md-4">
        <a href="<?= $base ?>&filter=<?= $filter === $s ? 'all' : $s ?>" class="card-stockwise p-3 d-block text-decoration-none text-reset" style="border-top:2px solid <?= ExpiryService::COLORS[$s] ?>;<?= $filter === $s ? 'border-color:' . ExpiryService::COLORS[$s] : '' ?>">
          <div class="stat-label" style="color:<?= ExpiryService::COLORS[$s] ?>"><?= ExpiryService::LABELS[$s] ?></div>
          <div class="stat-value mono"><?= $stats[$s] ?></div>
          <div class="text-faint small"><?= $sub ?></div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card-stockwise">
    <div class="p-3 d-flex flex-wrap align-items-center gap-2 border-bottom" style="border-color:var(--border)!important">
      <h2 class="h6 mb-0 me-2">Batch Detail</h2>
      <?php foreach (['all' => 'All', 'expired' => 'Expired', 'warning' => 'Expiring Soon', 'fresh' => 'Fresh'] as $f => $label): ?>
        <a href="<?= $base ?>&filter=<?= $f ?>&q=<?= urlencode($search) ?>" class="btn btn-sm <?= $filter === $f ? 'btn-outline-light' : 'btn-outline-secondary' ?>"><?= $label ?></a>
      <?php endforeach; ?>
      <form method="get" class="ms-auto">
        <input type="hidden" name="branch" value="<?= e($branchId) ?>">
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <input type="text" name="q" value="<?= e($search) ?>" class="form-control form-control-sm" style="width:240px" placeholder="Search name, category or barcode…">
      </form>
    </div>
    <table class="table table-stockwise table-hover mb-0 align-middle">
      <thead><tr><th class="ps-3">Product</th><th>Category</th><th>Barcode</th><th>Remaining</th><th>Received</th><th>Expiry</th><th>Status</th><th>Days Left</th><?php if ($canWriteOff): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach ($visible as $b): $color = ExpiryService::COLORS[$b['status']];
          $d = $b['days']; $daysLabel = $d < 0 ? abs($d) . 'd ago' : ($d === 0 ? 'Today' : $d . 'd'); ?>
          <tr>
            <td class="ps-3 fw-medium"><?= e($b['product_name']) ?></td>
            <td><span class="dot me-2" style="background:<?= category_color($b['category']) ?>"></span><?= e($b['category']) ?></td>
            <td class="mono text-faint small"><?= e($b['barcode']) ?: '—' ?></td>
            <td class="mono"><?= fmt($b['quantity_remaining']) ?> <span class="text-faint"><?= e($b['unit_of_measure']) ?></span></td>
            <td class="mono text-faint"><?= e($b['received_date']) ?></td>
            <td class="mono" style="color:<?= $color ?>"><?= e($b['expiry_date']) ?></td>
            <td><span class="badge badge-<?= $b['status'] ?>"><?= ExpiryService::LABELS[$b['status']] ?></span></td>
            <td class="mono" style="color:<?= $color ?>"><?= $daysLabel ?></td>
            <?php if ($canWriteOff): ?>
              <td class="text-end pe-3">
                <?php if ($b['status'] === 'expired'): ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('Write off <?= (int)$b['quantity_remaining'] ?> expired unit(s) of <?= e($b['product_name']) ?>?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Write off</button>
                  </form>
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$visible): ?><tr><td colspan="<?= $canWriteOff ? 9 : 8 ?>" class="text-center text-faint py-4">No batches match the current filter.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <div class="p-3 mono small text-faint">Showing <?= count($visible) ?> of <?= count($batches) ?> batches</div>
  </div>
</div>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
