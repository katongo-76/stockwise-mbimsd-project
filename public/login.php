<?php
require_once __DIR__ . '/../app/bootstrap.php';

$user = current_user();
if ($user) {
    header('Location: ' . ($user['role'] === 'manager' ? '/dashboard.php' : '/branch.php?branch=' . urlencode($user['branch_id'])));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !str_contains($email, '@')) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            $error = 'Invalid email or password.';
        } else {
            session_regenerate_id(true); // NFR-IV: regenerate session on login
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['name'] = $row['name'];
            $_SESSION['email'] = $row['email'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['company_id'] = $row['company_id'];
            $_SESSION['branch_id'] = $row['branch_id'];

            header('Location: ' . ($row['role'] === 'manager' ? '/dashboard.php' : '/branch.php?branch=' . urlencode($row['branch_id'])));
            exit;
        }
    }
}

$pageTitle = 'Sign in — Stockwise';
require __DIR__ . '/../app/Views/partials/head.php';
?>
<div class="d-flex flex-column align-items-center justify-content-center" style="min-height:100vh">
  <div style="width:100%;max-width:380px">
    <a href="/index.php" class="text-faint small d-block mb-4">&larr; Back</a>
    <h2 class="fw-semibold mb-1">Sign in</h2>
    <p class="text-faint small mb-4">Enter your credentials to access the dashboard.</p>

    <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endif; ?>

    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <div>
        <label class="form-label">Email</label>
        <input type="text" name="email" class="form-control" placeholder="you@company.com" value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div>
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" placeholder="••••••••">
      </div>
      <button type="submit" class="btn btn-primary mt-1">Sign in</button>
    </form>

    <p class="text-dim small text-center mt-4">New here? <a href="/signup.php">Create an account</a></p>
    <div class="text-faint small mt-3">
      <div class="mb-1">Demo accounts</div>
      <div>General Manager — manager@stockwise.com / manager123</div>
      <div>Branch Staff — chifubu@ · ndeke@ · masala@ · kansenshi@stockwise.com / staff123</div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
