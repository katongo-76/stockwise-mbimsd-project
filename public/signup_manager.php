<?php
require_once __DIR__ . '/../app/bootstrap.php';

$user = current_user();
if ($user) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;
$createdBranches = null;
$companyName = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $companyName = trim($_POST['company_name'] ?? '');
    $numberOfBranches = max(1, min(50, (int)($_POST['number_of_branches'] ?? 1)));

    if (!$name) {
        $error = 'Name is required.';
    } elseif (!$email || !str_contains($email, '@')) {
        $error = 'Enter a valid email address.';
    } elseif (!$phone) {
        $error = 'Phone number is required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (!$companyName) {
        $error = 'Company name is required.';
    } else {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'That email is already registered.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO companies (name) VALUES (?)')->execute([$companyName]);
                $companyId = (int)$pdo->lastInsertId();

                $pdo->prepare(
                    "INSERT INTO users (name, email, phone, password_hash, role, company_id, branch_id) VALUES (?,?,?,?,'manager',?,NULL)"
                )->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $companyId]);
                $userId = (int)$pdo->lastInsertId();

                $createdBranches = [];
                for ($i = 1; $i <= $numberOfBranches; $i++) {
                    $branchName = "Branch $i";
                    $baseId = slugify("$companyName-$branchName");
                    $branchId = $baseId;
                    $suffix = 1;
                    $check = $pdo->prepare('SELECT 1 FROM branches WHERE id = ?');
                    while (true) {
                        $check->execute([$branchId]);
                        if (!$check->fetch()) {
                            break;
                        }
                        $suffix++;
                        $branchId = "$baseId-$suffix";
                    }
                    $code = generate_branch_code($pdo);
                    $pdo->prepare(
                        "INSERT INTO branches (id, company_id, branch_code, name, city, country, manager) VALUES (?,?,?,?,'','',?)"
                    )->execute([$branchId, $companyId, $code, $branchName, $name]);

                    $createdBranches[] = ['id' => $branchId, 'name' => $branchName, 'code' => $code];
                }

                $pdo->commit();

                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                $_SESSION['name'] = $name;
                $_SESSION['email'] = $email;
                $_SESSION['role'] = 'manager';
                $_SESSION['company_id'] = $companyId;
                $_SESSION['branch_id'] = null;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Something went wrong creating your account. Please try again.';
                $createdBranches = null;
            }
        }
    }
}

$pageTitle = 'General Manager sign up — Stockwise';
require __DIR__ . '/../app/Views/partials/head.php';
?>
<div class="d-flex flex-column align-items-center justify-content-center py-5" style="min-height:100vh">
  <div style="width:100%;max-width:440px">

  <?php if ($createdBranches): ?>
    <h2 class="fw-semibold mb-2" style="color:#50E3A4">&check; <?= e($companyName) ?> is set up</h2>
    <p class="text-faint small mb-4">Share each branch code below with the Branch Staff who work there. You can rename branches and set up products any time from Branch Management.</p>
    <div class="d-flex flex-column gap-2 mb-4">
      <?php foreach ($createdBranches as $b): ?>
        <div class="card-stockwise p-3 d-flex flex-row justify-content-between align-items-center">
          <span><?= e($b['name']) ?></span>
          <span class="mono" style="color:#4F8EF7"><?= e($b['code']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <a href="/dashboard.php" class="btn btn-success w-100">Go to dashboard</a>

  <?php else: ?>
    <a href="/signup.php" class="text-faint small d-block mb-4">&larr; Back</a>
    <h2 class="fw-semibold mb-1">General Manager sign up</h2>
    <p class="text-faint small mb-4">Set up your company. We'll generate a unique join code for each branch.</p>
    <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-6"><label class="form-label">Full Name</label><input type="text" name="name" class="form-control" value="<?= e($_POST['name'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label">Phone Number</label><input type="tel" name="phone" class="form-control" value="<?= e($_POST['phone'] ?? '') ?>"></div>
      </div>
      <div><label class="form-label">Email</label><input type="text" name="email" class="form-control" value="<?= e($_POST['email'] ?? '') ?>"></div>
      <div><label class="form-label">Password</label><input type="password" name="password" class="form-control" placeholder="At least 6 characters"></div>
      <div><label class="form-label">Company Name</label><input type="text" name="company_name" class="form-control" value="<?= e($_POST['company_name'] ?? '') ?>"></div>
      <div>
        <label class="form-label">Number of Branches</label>
        <input type="number" name="number_of_branches" min="1" max="50" value="<?= e((string)($_POST['number_of_branches'] ?? 1)) ?>" class="form-control">
        <div class="form-text text-faint">You can rename and configure each branch afterwards.</div>
      </div>
      <button type="submit" class="btn btn-primary mt-1">Create company account</button>
    </form>
  <?php endif; ?>

  </div>
</div>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
