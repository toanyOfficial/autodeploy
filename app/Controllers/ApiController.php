<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\DeployHistoryRepository;
use App\Repositories\DeployProjectRepository;
use App\Repositories\DeployVersionRepository;
use App\Services\DeployService;
use App\Services\ReportService;

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
            Response::json(['data' => $repository->byProject($projectId, 3)]);
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

    public function report(int $historyId): void
    {
        $report = (new ReportService())->readByHistoryId($historyId);
        if ($report === null) {
            Response::json(['message' => 'Report not found.'], 404);
            return;
        }

        if (!empty($report['missing'])) {
            Response::json(['message' => 'Report file is missing.', 'history' => $report['history']], 404);
            return;
        }

        Response::json($report);
    }

    public function deployStatus(): void
    {
        Response::json(['deploying' => (new DeployService())->isDeploying()]);
    }

    public function deployLatest(int $projectId): void
    {
        $this->runDeploy(static fn (DeployService $service): array => $service->deployLatest($projectId));
    }

    public function deployStable(int $projectId): void
    {
        $this->runDeploy(static fn (DeployService $service): array => $service->deployStable($projectId));
    }

    public function deployVersion(int $projectId, int $versionId): void
    {
        $this->runDeploy(static fn (DeployService $service): array => $service->deployVersion($projectId, $versionId));
    }

    private function runDeploy(callable $callback): void
    {
        try {
            $history = $callback(new DeployService());
            $success = ($history['deploy_status'] ?? null) === 'success';
            $message = $success ? '배포가 완료되었습니다.' : $this->failureMessage($history, '배포에 실패했습니다. 리포트를 확인해주세요.');

            Response::json([
                'success' => $success,
                'status' => $history['deploy_status'] ?? ($success ? 'success' : 'failed'),
                'message' => $message,
                'report_url' => isset($history['id']) && !empty($history['report_file']) ? '/reports/' . (int) $history['id'] : null,
                'data' => $history,
            ], 200);
        } catch (\Throwable $exception) {
            Response::json([
                'success' => false,
                'status' => 'failed',
                'message' => $this->githubAuthMessage($exception->getMessage()) ?? $exception->getMessage(),
                'report_url' => null,
            ], 409);
        }
    }

    private function failureMessage(array $history, string $default): string
    {
        $reportFile = (string) ($history['report_file'] ?? '');
        if ($reportFile !== '' && is_readable($reportFile)) {
            $content = file_get_contents($reportFile) ?: '';
            return $this->githubAuthMessage($content) ?? $default;
        }

        return $default;
    }

    private function githubAuthMessage(string $text): ?string
    {
        if (str_contains($text, "fatal: could not read Username for 'https://github.com'")) {
            return 'GitHub 인증 문제로 배포에 실패했습니다. 서버의 git remote가 HTTPS 방식이거나 appuser 계정에 GitHub 인증이 설정되어 있지 않을 수 있습니다.';
        }

        return null;
    }
}
