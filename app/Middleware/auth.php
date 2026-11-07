<?php
require_once __DIR__ . '/../Config/session.php';
require_once __DIR__ . '/../Config/database.php';

start_app_session();

/** The logged-in user's session data, or null. Never trust anything else for RBAC. */
function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'         => $_SESSION['user_id'],
        'name'       => $_SESSION['name'],
        'email'      => $_SESSION['email'],
        'role'       => $_SESSION['role'],
        'company_id' => $_SESSION['company_id'],
        'branch_id'  => $_SESSION['branch_id'],
    ];
}

/** Call at the top of every protected page. Redirects to login if not authenticated. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: /login.php');
        exit;
    }
    return $user;
}

/** Call at the top of manager-only pages. */
function require_manager(): array
{
    $user = require_login();
    if ($user['role'] !== 'manager') {
        header('Location: /branch.php?branch=' . urlencode($user['branch_id'] ?? ''));
        exit;
    }
    return $user;
}

/**
 * Call at the top of any page scoped to one branch (?branch=slug).
 * Enforces that a Clerk can only touch their own branch, and that a
 * Manager can only touch a branch under their own company — on every
 * request, server-side (addresses broken-access-control risk, NFR + lit review [21]).
 */
function require_branch_access(string $branchId): array
{
    $user = require_login();

    if ($user['role'] === 'employee' && $user['branch_id'] !== $branchId) {
        header('Location: /branch.php?branch=' . urlencode($user['branch_id']));
        exit;
    }

    if ($user['role'] === 'manager') {
        $stmt = db()->prepare('SELECT company_id FROM branches WHERE id = ?');
        $stmt->execute([$branchId]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['company_id'] !== (int)$user['company_id']) {
            http_response_code(404);
            echo 'Branch not found.';
            exit;
        }
    }

    return $user;
}

function logout_user(): void
{
    $_SESSION = [];
    session_destroy();
}
