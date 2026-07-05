<?php

namespace App\Security;

final class AdminPassword
{
    public const HMAC_SHA256_PREFIX = 'hmac-sha256:';

    public static function hash(string $plainPassword, string $sessionSecret): string
    {
        return self::HMAC_SHA256_PREFIX . hash_hmac('sha256', $plainPassword, $sessionSecret);
    }

    public static function verify(string $storedPassword, string $plainPassword, string $sessionSecret): bool
    {
        if (str_starts_with($storedPassword, self::HMAC_SHA256_PREFIX)) {
            if ($sessionSecret === '') {
                return false;
            }

            return hash_equals($storedPassword, self::hash($plainPassword, $sessionSecret));
        }

        return hash_equals($storedPassword, $plainPassword);
    }
}
