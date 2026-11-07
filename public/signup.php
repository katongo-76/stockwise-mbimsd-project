<?php
require_once __DIR__ . '/../app/bootstrap.php';
$user = current_user();
if ($user) {
    header('Location: ' . ($user['role'] === 'manager' ? '/dashboard.php' : '/branch.php?branch=' . urlencode($user['branch_id'])));
    exit;
}
$pageTitle = 'Create an account — Stockwise';
require __DIR__ . '/../app/Views/partials/head.php';
?>
<div class="d-flex flex-column align-items-center justify-content-center" style="min-height:100vh">
  <div style="width:100%;max-width:420px">
    <a href="/login.php" class="text-faint small d-block mb-4">&larr; Back to sign in</a>
    <h2 class="fw-semibold mb-1">Create an account</h2>
    <p class="text-faint small mb-4">Are you setting up a company, or joining an existing one?</p>

    <div class="d-flex flex-column gap-3">
      <a href="/signup_manager.php" class="card-stockwise p-3 text-decoration-none">
        <div class="fw-semibold" style="color:#4F8EF7">I'm a General Manager</div>
        <div class="text-faint small mt-1">Set up your company and create branches. You'll get a unique code for each branch to share with your team.</div>
      </a>
      <a href="/signup_employee.php" class="card-stockwise p-3 text-decoration-none">
        <div class="fw-semibold" style="color:#50E3A4">I'm Branch Staff</div>
        <div class="text-faint small mt-1">Join your branch using the branch code your General Manager gave you.</div>
      </a>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
