#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\Env;
use App\Services\StableDeploymentBatchService;

$root = dirname(__DIR__);
require $root . '/app/Core/Autoloader.php';

Env::load($root . '/.env');

$timestamp = static fn (): string => gmdate('Y-m-d H:i:s') . ' UTC';
$write = static function (string $message) use ($timestamp): void {
    fwrite(STDOUT, '[' . $timestamp() . '] ' . $message . PHP_EOL);
    fflush(STDOUT);
};

try {
    $write('전체 활성 프로젝트 안정화버전 배포를 시작합니다.');
    $summary = (new StableDeploymentBatchService($write))->deployAll();

    $write(sprintf(
        '완료: total=%d success=%d failed=%d skipped=%d',
        (int) $summary['total'],
        (int) $summary['success'],
        (int) $summary['failed'],
        (int) $summary['skipped']
    ));

    exit(((int) $summary['failed']) > 0 ? 1 : 0);
} catch (\Throwable $throwable) {
    fwrite(STDERR, '[' . $timestamp() . '] 전체 안정화버전 배포 실행 실패: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
