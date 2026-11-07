<?php
require_once __DIR__ . '/../app/bootstrap.php';
$user = require_manager();
$pdo = db();

$tab = $_GET['tab'] ?? 'add';
if (!in_array($tab, ['add', 'edit', 'delete'], true)) {
    $tab = 'add';
}
$notice = null;
$error = null;
$newBranchCode = null;

function company_branch(PDO $pdo, string $id, int $companyId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $country = strtoupper(trim($_POST['country'] ?? ''));
        $manager = trim($_POST['manager'] ?? '');

        if (!$name || !$city || !$country || !$manager) {
            $error = 'Please fill in all required fields.';
        } else {
            $branchId = slugify($name);
            $check = $pdo->prepare('SELECT 1 FROM branches WHERE id = ?');
            $check->execute([$branchId]);
            if ($check->fetch()) {
                $branchId .= '-' . time();
            }
            $code = generate_branch_code($pdo);
            $pdo->prepare('INSERT INTO branches (id, company_id, branch_code, name, city, country, manager) VALUES (?,?,?,?,?,?,?)')
                ->execute([$branchId, $user['company_id'], $code, $name, $city, $country, $manager]);
            $notice = "Branch created — join code: $code";
            $newBranchCode = $code;
        }
    }

    if ($action === 'update') {
        $branchId = $_POST['branch_id'] ?? '';
        $branch = company_branch($pdo, $branchId, $user['company_id']);
        if (!$branch) {
            $error = 'Branch not found.';
        } else {
            $pdo->prepare('UPDATE branches SET name=?, city=?, country=?, manager=? WHERE id=?')
                ->execute([trim($_POST['name'] ?? ''), trim($_POST['city'] ?? ''), strtoupper(trim($_POST['country'] ?? '')), trim($_POST['manager'] ?? ''), $branchId]);
            $notice = 'Branch updated.';
            $tab = 'edit';
        }
    }

    if ($action === 'delete') {
        $branchId = $_POST['branch_id'] ?? '';
        $branch = company_branch($pdo, $branchId, $user['company_id']);
        if ($branch) {
            $pdo->prepare('DELETE FROM branches WHERE id = ?')->execute([$branchId]);
            $notice = 'Branch deleted.';
        }
        $tab = 'delete';
    }
}

$stmt = $pdo->prepare('SELECT * FROM branches WHERE company_id = ? ORDER BY name');
$stmt->execute([$user['company_id']]);
$branches = $stmt->fetchAll();

$editingBranch = null;
if ($tab === 'edit' && !empty($_GET['branch'])) {
    $editingBranch = company_branch($pdo, $_GET['branch'], $user['company_id']);
}
$deletingBranch = null;
if ($tab === 'delete' && !empty($_GET['branch'])) {
    $deletingBranch = company_branch($pdo, $_GET['branch'], $user['company_id']);
}

$pageTitle = 'Branch Management — Stockwise';
require __DIR__ . '/../app/Views/partials/head.php';
$active = 'branches';
require __DIR__ . '/../app/Views/partials/nav_manager.php';
?>
<div class="container-md py-4" style="max-width:720px">
  <h1 class="h6 text-faint text-uppercase mb-1" style="letter-spacing:.08em">Branch Management</h1>
  <p class="text-faint small mb-4"><?= count($branches) ?> active branch<?= count($branches) === 1 ? '' : 'es' ?> under your account</p>

  <div class="d-flex gap-2 mb-4">
    <a href="?tab=add" class="btn btn-sm <?= $tab === 'add' ? 'btn-outline-light' : 'btn-outline-secondary' ?>">Add Branch</a>
    <a href="?tab=edit" class="btn btn-sm <?= $tab === 'edit' ? 'btn-outline-light' : 'btn-outline-secondary' ?>">Edit Branch</a>
    <a href="?tab=delete" class="btn btn-sm <?= $tab === 'delete' ? 'btn-outline-light' : 'btn-outline-secondary' ?>">Delete Branch</a>
  </div>

  <?php if ($notice): ?><div class="alert alert-success py-2 small"><?= e($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endif; ?>

  <?php if ($tab === 'add'): ?>
    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div><label class="form-label">Branch Name *</label><input type="text" name="name" class="form-control"></div>
      <div class="row g-3">
        <div class="col-6"><label class="form-label">City *</label><input type="text" name="city" class="form-control"></div>
        <div class="col-6"><label class="form-label">Country Code *</label><input type="text" name="country" maxlength="3" class="form-control"></div>
      </div>
      <div><label class="form-label">Branch Manager *</label><input type="text" name="manager" class="form-control"></div>
      <button type="submit" class="btn btn-success mt-1">Create Branch</button>
    </form>

  <?php elseif ($tab === 'edit'): ?>
    <form method="get" class="mb-4" style="max-width:320px">
      <input type="hidden" name="tab" value="edit">
      <select name="branch" class="form-select" onchange="this.form.submit()">
        <option value="">— Select a branch —</option>
        <?php foreach ($branches as $b): ?>
          <option value="<?= e($b['id']) ?>" <?= $editingBranch && $editingBranch['id'] === $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?> (<?= e($b['country']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if ($editingBranch): ?>
      <div class="card-stockwise p-3 d-flex flex-row justify-content-between align-items-center mb-3">
        <span class="small text-faint">Branch Staff join code</span>
        <span class="mono" style="color:#4F8EF7"><?= e($editingBranch['branch_code']) ?></span>
      </div>
      <form method="post" class="d-flex flex-column gap-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="branch_id" value="<?= e($editingBranch['id']) ?>">
        <div><label class="form-label">Branch Name</label><input type="text" name="name" class="form-control" value="<?= e($editingBranch['name']) ?>"></div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label">City</label><input type="text" name="city" class="form-control" value="<?= e($editingBranch['city']) ?>"></div>
          <div class="col-6"><label class="form-label">Country Code</label><input type="text" name="country" maxlength="3" class="form-control" value="<?= e($editingBranch['country']) ?>"></div>
        </div>
        <div><label class="form-label">Branch Manager</label><input type="text" name="manager" class="form-control" value="<?= e($editingBranch['manager']) ?>"></div>
        <button type="submit" class="btn btn-primary mt-1" style="align-self:flex-start">Save Changes</button>
      </form>
    <?php endif; ?>

  <?php elseif ($tab === 'delete'): ?>
    <form method="get" class="mb-4" style="max-width:320px">
      <input type="hidden" name="tab" value="delete">
      <select name="branch" class="form-select" onchange="this.form.submit()">
        <option value="">— Select a branch —</option>
        <?php foreach ($branches as $b): ?>
          <option value="<?= e($b['id']) ?>" <?= $deletingBranch && $deletingBranch['id'] === $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?> (<?= e($b['country']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if ($deletingBranch): ?>
      <div class="d-flex flex-column gap-2">
        <form method="post" onsubmit="return confirm('Permanently delete <?= e($deletingBranch['name']) ?>? This cannot be undone.');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="branch_id" value="<?= e($deletingBranch['id']) ?>">
          <button type="submit" class="card-stockwise p-3 w-100 text-start border-0" style="border-left:3px solid var(--red)!important">
            <div class="fw-semibold" style="color:var(--red)">Delete Entire Branch</div>
            <div class="text-faint small mt-1">Permanently remove <?= e($deletingBranch['name']) ?> and all of its inventory records.</div>
          </button>
        </form>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
