<?php

namespace App\Services;

use App\Config\Env;

final class DeploymentLock
{
    /** @var resource|null */
    private $handle = null;
    private string $path;

    public function __construct()
    {
        $reportDir = rtrim(Env::get('REPORT_DIR', __DIR__ . '/../../reports') ?? __DIR__ . '/../../reports', '/');
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }
        $this->path = $reportDir . '/deploy.lock';
    }

    public function acquire(): bool
    {
        $this->handle = fopen($this->path, 'c+');
        if ($this->handle === false) {
            throw new \RuntimeException('배포 잠금 파일을 열 수 없습니다.');
        }

        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            return false;
        }

        ftruncate($this->handle, 0);
        fwrite($this->handle, (string) getmypid());
        fflush($this->handle);

        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function isLocked(): bool
    {
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            return false;
        }

        $locked = !flock($handle, LOCK_EX | LOCK_NB);
        if (!$locked) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return $locked;
    }
}
