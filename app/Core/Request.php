<?php

namespace App\Core;

final class Request
{
    public function method(): string
    {
        $method = $_POST['_method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET';
        return strtoupper((string) $method);
    }

    public function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return rtrim($path, '/') ?: '/';
    }

    public function input(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw ?: '{}', true);
            return is_array($decoded) ? $decoded : [];
        }

        return array_merge($_GET, $_POST);
    }
}
