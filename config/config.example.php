<?php

return [
    'app_name' => 'Familien-Speiseplan',
    'base_path' => '',
    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=speiseplan;charset=utf8mb4',
        'user' => 'speiseplan',
        'password' => 'change-me',
    ],
    'session_name' => 'speiseplan_session',
    'force_https' => false,
    'upload_dir' => dirname(__DIR__) . '/storage/uploads/recipes',
    'upload_url' => '/uploads/recipes',
    'max_upload_bytes' => 5 * 1024 * 1024,
];
