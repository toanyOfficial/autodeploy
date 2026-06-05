<?php

namespace App\Controllers;

use App\Core\Response;
use App\Services\DeployService;

final class DeployController
{
    public function latest(int $projectId): void
    {
        $this->run(static fn (DeployService $service): array => $service->deployLatest($projectId));
    }

    public function stable(int $projectId): void
    {
        $this->run(static fn (DeployService $service): array => $service->deployStable($projectId));
    }

    public function version(int $projectId, int $versionId): void
    {
        $this->run(static fn (DeployService $service): array => $service->deployVersion($projectId, $versionId));
    }

    private function run(callable $callback): void
    {
        try {
            $callback(new DeployService());
            Response::redirect('/dashboard');
        } catch (\Throwable $throwable) {
            $_SESSION['flash_error'] = $throwable->getMessage();
            Response::redirect('/dashboard');
        }
    }
}
