<?php

use App\Config\Env;
use App\Security\AdminPassword;

require __DIR__ . '/../app/Core/Autoloader.php';

Env::load(__DIR__ . '/../.env');

$password = $argv[1] ?? '';
if ($password === '') {
    fwrite(STDERR, "Usage: php scripts/hash_admin_password.php '<admin-password>' [session-secret]\n");
    exit(1);
}

$sessionSecret = $argv[2] ?? (Env::get('SESSION_SECRET', '') ?? '');
if ($sessionSecret === '') {
    fwrite(STDERR, "SESSION_SECRET is required. Set it in .env or pass it as the second argument.\n");
    exit(1);
}

echo AdminPassword::hash($password, $sessionSecret) . PHP_EOL;
