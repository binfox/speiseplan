<?php

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    $configFile = APP_ROOT . '/config/config.example.php';
}

$config = require $configFile;

if (!empty($config['force_https']) && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if ($host !== '') {
        header('Location: https://' . $host . $uri, true, 302);
        exit;
    }
}

session_name($config['session_name'] ?? 'speiseplan_session');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 60,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();

require_once __DIR__ . '/helpers.php';

send_security_headers();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/recipes.php';
require_once __DIR__ . '/planner.php';
require_once __DIR__ . '/views.php';
