<?php
require_once __DIR__ . '/../app/bootstrap.php';

$user = current_user();
if ($user) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $branchCode = strtoupper(trim($_POST['branch_code'] ?? ''));

    if (!$name) {
        $error = 'Name is required.';
    } elseif (!$email || !str_contains($email, '@')) {
        $error = 'Enter a valid email address.';
    } elseif (!$phone) {
        $error = 'Phone number is required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (!$branchCode) {
        $error = 'Branch code is required.';
    } else {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'That email is already registered.';
        } else {
            $stmt = $pdo->prepare('SELECT id, company_id, name FROM branches WHERE branch_code = ?');
            $stmt->execute([$branchCode]);
            $branch = $stmt->fetch();
            if (!$branch) {
                $error = "That branch code wasn't found. Check it with your General Manager.";
            } else {
                $pdo->prepare(
                    "INSERT INTO users (name, email, phone, password_hash, role, company_id, branch_id) VALUES (?,?,?,?,'employee',?,?)"
                )->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $branch['company_id'], $branch['id']]);
                $userId = (int)$pdo->lastInsertId();

                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                $_SESSION['name'] = $name;
                $_SESSION['email'] = $email;
                $_SESSION['role'] = 'employee';
                $_SESSION['company_id'] = $branch['company_id'];
                $_SESSION['branch_id'] = $branch['id'];

                header('Location: /branch.php?branch=' . urlencode($branch['id']));
                exit;
            }
        }
    }
}

$pageTitle = 'Branch Staff sign up — Stockwise';
require __DIR__ . '/../app/Views/partials/head.php';
?>
<div class="d-flex flex-column align-items-center justify-content-center py-5" style="min-height:100vh">
  <div style="width:100%;max-width:380px">
    <a href="/signup.php" class="text-faint small d-block mb-4">&larr; Back</a>
    <h2 class="fw-semibold mb-1">Branch Staff sign up</h2>
    <p class="text-faint small mb-4">Enter the branch code your General Manager gave you.</p>
    <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <div><label class="form-label">Full Name</label><input type="text" name="name" class="form-control" value="<?= e($_POST['name'] ?? '') ?>"></div>
      <div><label class="form-label">Email</label><input type="text" name="email" class="form-control" value="<?= e($_POST['email'] ?? '') ?>"></div>
      <div><label class="form-label">Phone Number</label><input type="tel" name="phone" class="form-control" value="<?= e($_POST['phone'] ?? '') ?>"></div>
      <div><label class="form-label">Password</label><input type="password" name="password" class="form-control" placeholder="At least 6 characters"></div>
      <div>
        <label class="form-label">Branch Code</label>
        <input type="text" name="branch_code" class="form-control mono text-uppercase" placeholder="e.g. CHF4F82" value="<?= e($_POST['branch_code'] ?? '') ?>">
      </div>
      <button type="submit" class="btn btn-primary mt-1">Join branch</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
