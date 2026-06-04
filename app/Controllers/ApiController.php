<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\DeployHistoryRepository;
use App\Repositories\DeployProjectRepository;
use App\Repositories\DeployVersionRepository;

final class ApiController
{
    public function dbStatus(): void
    {
        $row = Database::connection()->query('SELECT DATABASE() AS database_name, 1 AS ok')->fetch();
        Response::json(['ok' => true, 'database' => $row['database_name'] ?? null]);
    }

    public function projects(Request $request): void
    {
        $repository = new DeployProjectRepository();
        $method = $request->method();

        if ($method === 'GET') {
            Response::json(['data' => $repository->all()]);
            return;
        }

        if ($method === 'POST') {
            Response::json(['data' => $repository->create($request->input())], 201);
            return;
        }

        Response::json(['message' => 'Method not allowed.'], 405);
    }

    public function project(Request $request, int $id): void
    {
        $repository = new DeployProjectRepository();
        $method = $request->method();

        if ($method === 'GET') {
            $project = $repository->find($id);
            $project === null ? Response::json(['message' => 'Project not found.'], 404) : Response::json(['data' => $project]);
            return;
        }

        if ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
            $project = $repository->update($id, $request->input());
            $project === null ? Response::json(['message' => 'Project not found.'], 404) : Response::json(['data' => $project]);
            return;
        }

        if ($method === 'DELETE') {
            $project = $repository->deactivate($id);
            $project === null ? Response::json(['message' => 'Project not found.'], 404) : Response::json(['data' => $project]);
            return;
        }

        Response::json(['message' => 'Method not allowed.'], 405);
    }

    public function deactivateProject(int $id): void
    {
        $project = (new DeployProjectRepository())->deactivate($id);
        $project === null ? Response::json(['message' => 'Project not found.'], 404) : Response::json(['data' => $project]);
    }

    public function versions(Request $request, int $projectId): void
    {
        $repository = new DeployVersionRepository();

        if ($request->method() === 'GET') {
            Response::json(['data' => $repository->byProject($projectId)]);
            return;
        }

        if ($request->method() === 'POST') {
            Response::json(['data' => $repository->create($projectId, $request->input())], 201);
            return;
        }

        Response::json(['message' => 'Method not allowed.'], 405);
    }

    public function version(Request $request, int $id): void
    {
        $repository = new DeployVersionRepository();
        $method = $request->method();

        if ($method === 'GET') {
            $version = $repository->find($id);
        } elseif ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
            $version = $repository->update($id, $request->input());
        } elseif ($method === 'DELETE') {
            $version = $repository->deactivate($id);
        } else {
            Response::json(['message' => 'Method not allowed.'], 405);
            return;
        }

        $version === null ? Response::json(['message' => 'Version not found.'], 404) : Response::json(['data' => $version]);
    }

    public function markStableVersion(int $id): void
    {
        $version = (new DeployVersionRepository())->markStable($id);
        $version === null ? Response::json(['message' => 'Version not found.'], 404) : Response::json(['data' => $version]);
    }

    public function histories(Request $request, int $projectId): void
    {
        $repository = new DeployHistoryRepository();

        if ($request->method() === 'GET') {
            Response::json(['data' => $repository->byProject($projectId)]);
            return;
        }

        if ($request->method() === 'POST') {
            Response::json(['data' => $repository->create($projectId, $request->input())], 201);
            return;
        }

        Response::json(['message' => 'Method not allowed.'], 405);
    }

    public function history(Request $request, int $id): void
    {
        $repository = new DeployHistoryRepository();
        $method = $request->method();

        if ($method === 'GET') {
            $history = $repository->find($id);
        } elseif ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
            $history = $repository->update($id, $request->input());
        } else {
            Response::json(['message' => 'Method not allowed.'], 405);
            return;
        }

        $history === null ? Response::json(['message' => 'Deploy history not found.'], 404) : Response::json(['data' => $history]);
    }
}
