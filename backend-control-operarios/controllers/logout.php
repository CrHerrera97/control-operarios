<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $parametrosCookie = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $parametrosCookie['path'],
        $parametrosCookie['domain'],
        (bool) $parametrosCookie['secure'],
        (bool) $parametrosCookie['httponly']
    );
}

session_destroy();

echo json_encode(['exito' => true], JSON_UNESCAPED_UNICODE);