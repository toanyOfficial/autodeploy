<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\DeployHistoryRepository;
use App\Repositories\DeployProjectRepository;
use App\Repositories\DeployVersionRepository;

final class DashboardController
{
    public function index(): void
    {
        $projectRepository = new DeployProjectRepository();
        $versionRepository = new DeployVersionRepository();
        $historyRepository = new DeployHistoryRepository();

        $projects = array_map(function (array $project) use ($versionRepository, $historyRepository): array {
            $project['recent_versions'] = $versionRepository->byProject((int) $project['id'], 3);
            $project['current_deploy'] = $historyRepository->latestSuccessByProject((int) $project['id']);
            return $project;
        }, $projectRepository->all(true));

        Response::view('projects/index', ['projects' => $projects]);
    }
}
