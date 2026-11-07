<?php
require_once __DIR__ . '/env.php';

const SESSION_IDLE_TIMEOUT_SECONDS = 1800; // 30 minutes (NFR-IV)

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => env('SESSION_SECURE_COOKIE', '0') === '1',
    ]);
    session_start();

    // Automatic expiry after a configurable period of inactivity (NFR-IV).
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT_SECONDS) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}
