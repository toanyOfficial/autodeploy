#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\Env;
use App\Services\DeployService;

$root = dirname(__DIR__);
require $root . '/app/Core/Autoloader.php';

Env::load($root . '/.env');

$projectId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($projectId < 1) {
    fwrite(STDERR, 'Usage: php scripts/deploy_stable_project.php <project-id>' . PHP_EOL);
    exit(2);
}

$startedAt = time();
try {
    $history = (new DeployService())->deployStable($projectId);
    $status = (string) ($history['deploy_status'] ?? 'failed');
    echo json_encode([
        'success' => $status === 'success',
        'status' => $status,
        'elapsed' => time() - $startedAt,
        'history' => $history,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($status === 'success' ? 0 : 1);
} catch (\Throwable $throwable) {
    echo json_encode([
        'success' => false,
        'status' => 'failed',
        'elapsed' => time() - $startedAt,
        'error' => $throwable->getMessage(),
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
