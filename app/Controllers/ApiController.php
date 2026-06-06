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
use App\Services\ReportOperationService;

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


    public function reportOperation(Request $request, int $historyId): void
    {
        if ($request->method() !== 'POST') {
            Response::json(['message' => 'Method not allowed.'], 405);
            return;
        }

        $operation = (string) ($request->input()['operation'] ?? '');
        try {
            Response::json((new ReportOperationService())->run($historyId, $operation));
        } catch (\Throwable $exception) {
            Response::json([
                'success' => false,
                'message' => $exception->getMessage(),
                'log' => null,
            ], 409);
        }
    }


    public function rebootAndRestore(Request $request): void
    {
        if ($request->method() !== 'POST') {
            Response::json(['message' => 'Method not allowed.'], 405);
            return;
        }

        $command = ['sudo', '/usr/local/sbin/auto-reboot-deploy.sh'];
        $commandText = implode(' ', $command);
        $this->appendRebootDeployLog($this->formatDiagnosticLog('API reboot-and-restore execution attempt', [
            'location' => __METHOD__ . ':start',
            'command' => $commandText,
        ]));

        try {
            $descriptor = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open($command, $descriptor, $pipes);
            if (!is_resource($process)) {
                $diagnostic = [
                    'location' => __METHOD__ . ':proc_open',
                    'command' => $commandText,
                    'exit_code' => null,
                    'stdout' => '',
                    'stderr' => 'proc_open returned a non-resource process handle.',
                ];
                $logWriteError = $this->appendRebootDeployLog($this->formatDiagnosticLog('API reboot-and-restore failed', $diagnostic));

                Response::json([
                    'success' => false,
                    'message' => '서버 재부팅 자동화 명령을 시작할 수 없습니다.',
                    'diagnostic' => $diagnostic,
                    'diagnostic_log' => $this->formatDiagnosticText($diagnostic),
                    'log_write_error' => $logWriteError,
                ], 500);
                return;
            }

            $stdout = stream_get_contents($pipes[1]) ?: '';
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);

            $diagnostic = [
                'location' => __METHOD__ . ':proc_close',
                'command' => $commandText,
                'exit_code' => $code,
                'stdout' => trim($stdout),
                'stderr' => trim($stderr),
            ];

            if ($code !== 0) {
                $logWriteError = $this->appendRebootDeployLog($this->formatDiagnosticLog('API reboot-and-restore failed', $diagnostic));

                Response::json([
                    'success' => false,
                    'message' => '서버 재부팅 자동화 명령 실행에 실패했습니다.',
                    'diagnostic' => $diagnostic,
                    'diagnostic_log' => $this->formatDiagnosticText($diagnostic),
                    'log_write_error' => $logWriteError,
                ], 409);
                return;
            }

            $this->appendRebootDeployLog($this->formatDiagnosticLog('API reboot-and-restore command completed', $diagnostic));

            Response::json([
                'success' => true,
                'message' => '서버 재부팅 및 자동 안정화버전 배포가 예약되었습니다.',
                'diagnostic' => $diagnostic,
            ]);
        } catch (\Throwable $exception) {
            $diagnostic = [
                'location' => __METHOD__ . ':exception',
                'command' => $commandText,
                'exit_code' => null,
                'stdout' => '',
                'stderr' => '',
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'exception_trace' => $exception->getTraceAsString(),
            ];
            $logWriteError = $this->appendRebootDeployLog($this->formatDiagnosticLog('API reboot-and-restore exception', $diagnostic, true));

            Response::json([
                'success' => false,
                'message' => '서버 재부팅 자동화 처리 중 PHP 예외가 발생했습니다.',
                'diagnostic' => $this->publicDiagnostic($diagnostic),
                'diagnostic_log' => $this->formatDiagnosticText($diagnostic),
                'log_write_error' => $logWriteError,
            ], 500);
        }
    }

    public function rebootDeployLog(): void
    {
        $path = $this->rebootDeployLogPath();
        if (!is_readable($path)) {
            Response::json([
                'success' => true,
                'log' => $this->formatDiagnosticText([
                    'location' => __METHOD__ . ':read_log',
                    'command' => 'read ' . $path,
                    'exit_code' => null,
                    'stdout' => '',
                    'stderr' => '로그 파일이 없거나 읽을 수 없습니다. 운영 설치 시 /var/log/auto_deploy/reboot-deploy.log를 웹 실행 사용자(appuser)가 읽을 수 있어야 합니다.',
                ]),
                'message' => '재부팅 자동화 로그 파일을 읽을 수 없습니다.',
            ]);
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $diagnostic = [
                'location' => __METHOD__ . ':file',
                'command' => 'read ' . $path,
                'exit_code' => null,
                'stdout' => '',
                'stderr' => 'file() returned false while reading reboot deploy log.',
            ];
            Response::json([
                'success' => false,
                'log' => $this->formatDiagnosticText($diagnostic),
                'message' => '재부팅 자동화 로그를 읽을 수 없습니다.',
                'diagnostic' => $diagnostic,
            ], 500);
            return;
        }

        Response::json([
            'success' => true,
            'log' => implode(PHP_EOL, array_slice($lines, -400)),
        ]);
    }

    private function rebootDeployLogPath(): string
    {
        return '/var/log/auto_deploy/reboot-deploy.log';
    }

    private function appendRebootDeployLog(string $content): ?string
    {
        $path = $this->rebootDeployLogPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return '로그 디렉터리를 생성할 수 없습니다: ' . $directory;
        }

        if (@file_put_contents($path, $content . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            return '로그 파일에 쓸 수 없습니다: ' . $path;
        }

        return null;
    }

    private function formatDiagnosticLog(string $title, array $diagnostic, bool $includeTrace = false): string
    {
        $lines = [
            '============================================================',
            '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $title,
            $this->formatDiagnosticText($diagnostic, $includeTrace),
        ];

        return implode(PHP_EOL, $lines);
    }

    private function formatDiagnosticText(array $diagnostic, bool $includeTrace = false): string
    {
        $lines = [
            '실패 발생 위치:',
            (string) ($diagnostic['location'] ?? ''),
            '',
            '실행 명령:',
            (string) ($diagnostic['command'] ?? ''),
            '',
            'exit code:',
            array_key_exists('exit_code', $diagnostic) && $diagnostic['exit_code'] !== null ? (string) $diagnostic['exit_code'] : 'N/A',
            '',
            'stdout:',
            (string) ($diagnostic['stdout'] ?? ''),
            '',
            'stderr:',
            (string) ($diagnostic['stderr'] ?? ''),
        ];

        if (isset($diagnostic['exception_message'])) {
            $lines[] = '';
            $lines[] = 'Exception Message:';
            $lines[] = (string) $diagnostic['exception_message'];
            $lines[] = 'File:';
            $lines[] = (string) ($diagnostic['exception_file'] ?? '');
            $lines[] = 'Line:';
            $lines[] = (string) ($diagnostic['exception_line'] ?? '');
        }

        if ($includeTrace && isset($diagnostic['exception_trace'])) {
            $lines[] = 'Stack Trace:';
            $lines[] = (string) $diagnostic['exception_trace'];
        }

        return implode(PHP_EOL, $lines);
    }

    private function publicDiagnostic(array $diagnostic): array
    {
        unset($diagnostic['exception_trace']);
        return $diagnostic;
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
