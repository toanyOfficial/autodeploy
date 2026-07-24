<?php

namespace App\Services;

final class AppVersionService
{
    public function currentCommitHash(): string
    {
        $root = dirname(__DIR__, 2);
        $output = [];
        $code = 0;
        exec('cd ' . escapeshellarg($root) . ' && git rev-parse --short=12 HEAD 2>/dev/null', $output, $code);
        $hash = trim((string) ($output[0] ?? ''));

        return $code === 0 && $hash !== '' ? $hash : 'unknown';
    }
}
