<?php
require_once __DIR__ . '/env.php';

/**
 * Returns a shared PDO connection. All queries throughout the app go
 * through this — always with prepared statements (NFR-III).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $host = env('DB_HOST', 'localhost');
        $port = env('DB_PORT', '3306');
        $name = env('DB_NAME', 'stockwise_php');
        $user = env('DB_USER', 'root');
        $pass = env('DB_PASSWORD', '');

        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
