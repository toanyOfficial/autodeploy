<?php

use App\Config\Env;
use App\Core\Request;
use App\Core\Router;

require __DIR__ . '/../app/Core/Autoloader.php';

Env::load(__DIR__ . '/../.env');

$secret = Env::get('SESSION_SECRET', '');
if ($secret !== '') {
    session_name('AUTO_DEPLOY_SESSION');
}

$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

(new Router())->dispatch(new Request());
