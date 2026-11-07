<?php
declare(strict_types=1);

require_once __DIR__ . '/Config/env.php';

error_reporting(E_ALL);
ini_set('display_errors', env('APP_DEBUG', '0') === '1' ? '1' : '0');

require_once __DIR__ . '/Config/database.php';
require_once __DIR__ . '/Middleware/auth.php'; // also starts the session
require_once __DIR__ . '/Helpers/functions.php';
