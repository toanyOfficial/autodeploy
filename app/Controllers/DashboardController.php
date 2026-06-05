<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\DeployHistoryRepository;
use App\Repositories\DeployProjectRepository;
use App\Repositories\DeployVersionRepository;
use App\Services\DeployService;

final class DashboardController
{
    public function index(): void
    {
        $projectRepository = new DeployProjectRepository();
        $versionRepository = new DeployVersionRepository();
        $historyRepository = new DeployHistoryRepository();
        $deployService = new DeployService();

        $projects = array_map(function (array $project) use ($versionRepository, $historyRepository): array {
            $project['recent_versions'] = $versionRepository->byProject((int) $project['id'], 5);
            $project['current_deploy'] = $historyRepository->latestSuccessByProject((int) $project['id']);
            $project['recent_histories'] = $historyRepository->byProject((int) $project['id'], 3);
            return $project;
        }, $projectRepository->all(true));

        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        Response::view('projects/index', [
            'projects' => $projects,
            'isDeploying' => $deployService->isDeploying(),
            'flashError' => $flashError,
        ]);
    }
}
