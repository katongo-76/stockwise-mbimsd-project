<?php /** @var array $user  @var string $active */ ?>
<nav class="navbar navbar-expand-lg navbar-stockwise px-4">
  <a class="navbar-brand" href="/dashboard.php">Stockwise <span class="text-faint fw-normal">| General Manager</span></a>
  <div class="navbar-nav flex-row gap-3 ms-4">
    <a class="nav-link <?= $active === 'overview' ? 'active' : '' ?>" href="/dashboard.php">Overview</a>
  </div>
  <div class="ms-auto d-flex align-items-center gap-3">
    <span class="mono text-faint small"><?= e($user['name']) ?> &middot; <?= e($user['email']) ?></span>
    <a href="/logout.php" class="text-faint small">Sign out</a>
  </div>
</nav>
