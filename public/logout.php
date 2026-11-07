<?php
require_once __DIR__ . '/../app/bootstrap.php';
logout_user();
header('Location: /login.php');
exit;
