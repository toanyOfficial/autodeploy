<?php

namespace App\Middleware;

final class AuthMiddleware
{
    public static function check(): bool
    {
        return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login', true, 302);
            exit;
        }
    }
}
