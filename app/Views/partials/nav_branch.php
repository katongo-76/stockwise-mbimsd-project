<?php
/** @var array $user  @var string $active  @var array $branch */
$base = '/branch.php?branch=' . urlencode($branch['id']);
$isManager = $user['role'] === 'manager';
?>
<nav class="navbar navbar-expand-lg navbar-stockwise px-4">
  <a class="navbar-brand" href="<?= $base ?>">Stockwise <span class="text-faint fw-normal">| <?= e($branch['name']) ?></span></a>
  <div class="navbar-nav flex-row gap-3 ms-4 flex-wrap">
    <a class="nav-link <?= $active === 'overview' ? 'active' : '' ?>" href="<?= $base ?>">Overview</a>
    <?php if ($isManager): ?>
      <a class="nav-link <?= $active === 'trends' ? 'active' : '' ?>" href="/branch_trends.php?branch=<?= urlencode($branch['id']) ?>">Trends</a>
    <?php else: ?>
      <a class="nav-link <?= $active === 'stock' ? 'active' : '' ?>" href="/branch_stock.php?branch=<?= urlencode($branch['id']) ?>">Stock Management</a>
    <?php endif; ?>
    <a class="nav-link <?= $active === 'forecast' ? 'active' : '' ?>" href="/branch_forecast.php?branch=<?= urlencode($branch['id']) ?>">Forecast</a>
    <a class="nav-link <?= $active === 'expiry' ? 'active' : '' ?>" href="/branch_expiry.php?branch=<?= urlencode($branch['id']) ?>">Expiry</a>
    <a class="nav-link <?= $active === 'alerts' ? 'active' : '' ?>" href="/branch_alerts.php?branch=<?= urlencode($branch['id']) ?>">Low Stock</a>
    <a class="nav-link <?= $active === 'inventory' ? 'active' : '' ?>" href="/branch_inventory.php?branch=<?= urlencode($branch['id']) ?>">Full Inventory</a>
  </div>
  <div class="ms-auto d-flex align-items-center gap-3">
    <?php if ($isManager): ?><a href="/dashboard.php" class="text-faint small">&larr; All branches</a><?php endif; ?>
    <span class="mono text-faint small"><?= e($user['name']) ?></span>
    <a href="/logout.php" class="text-faint small">Sign out</a>
  </div>
</nav>
