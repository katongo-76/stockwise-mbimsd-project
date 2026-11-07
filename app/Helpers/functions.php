<?php

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Invalid or expired form submission. Please go back and try again.');
    }
}

function flash(string $key, ?string $message = null)
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $value;
}

function slugify(string $text): string
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text));
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'branch-' . time();
}

function generate_branch_code(PDO $pdo, int $length = 7): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    for ($i = 0; $i < 20; $i++) {
        $code = '';
        for ($j = 0; $j < $length; $j++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM branches WHERE branch_code = ?');
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }
    throw new RuntimeException('Could not generate a unique branch code');
}

function fmt(int|float $n): string
{
    return number_format($n);
}

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

const CATEGORY_COLORS = [
    'Electronics'      => '#4F8EF7',
    'Apparel'          => '#F5A623',
    'Food & Beverage'  => '#50E3A4',
    'Tools'            => '#FF6B6B',
    'Furniture'        => '#A78BFA',
    'Books'            => '#22D3EE',
    'Sporting Goods'   => '#FB923C',
    'Health'           => '#F472B6',
];

function category_color(string $category): string
{
    return CATEGORY_COLORS[$category] ?? '#6b7280';
}
