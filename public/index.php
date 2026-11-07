<?php
require_once __DIR__ . '/../app/bootstrap.php';

$user = current_user();
if ($user) {
    header('Location: ' . ($user['role'] === 'manager' ? '/dashboard.php' : '/branch.php?branch=' . urlencode($user['branch_id'])));
    exit;
}

$pageTitle = 'Stockwise';
require __DIR__ . '/../app/Views/partials/head.php';
?>
<div class="d-flex flex-column align-items-center justify-content-center text-center" style="min-height:100vh">
  <div class="d-flex" style="width:40px;height:40px;gap:3px;flex-wrap:wrap">
    <div style="width:18px;height:18px;background:#4F8EF7;border-radius:2px"></div>
    <div style="width:18px;height:18px;background:#F5A623;border-radius:2px"></div>
    <div style="width:18px;height:18px;background:#50E3A4;border-radius:2px"></div>
    <div style="width:18px;height:18px;background:#FF6B6B;border-radius:2px"></div>
  </div>
  <h1 class="mt-4 fw-semibold">Stockwise</h1>
  <p class="text-faint mb-4" style="max-width:320px">Inventory management and analytics for your multi-branch team.</p>
  <a href="/login.php" class="btn btn-primary px-4">Get Started</a>
</div>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
