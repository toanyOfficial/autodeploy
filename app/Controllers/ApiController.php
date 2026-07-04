<?php

namespace App\Controllers;

use App\Config\Database;
use App\Config\Env;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\DeployHistoryRepository;
use App\Repositories\DeployProjectRepository;
use App\Repositories\DeployVersionRepository;
use App\Services\DeployService;
use App\Services\ReportService;
use App\Services\ReportOperationService;
use App\Services\RebootAutomationStatusService;

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


    public function rebootAutomationStatus(): void
    {
        Response::json((new RebootAutomationStatusService())->status());
    }

    public function selfReboot(Request $request): void
    {
        if ($request->method() !== 'POST') {
            Response::json(['message' => 'Method not allowed.'], 405);
            return;
        }

        $root = dirname(__DIR__, 2);
        $port = (int) (Env::get('PORT', '9090') ?? '9090');
        if ($port < 1 || $port > 65535) {
            Response::json([
                'success' => false,
                'message' => 'Auto Deploy 포트 설정이 올바르지 않아 self reboot를 시작할 수 없습니다.',
            ], 500);
            return;
        }

        $phpBinary = PHP_BINARY ?: 'php';
        $logFile = sys_get_temp_dir() . '/auto_deploy_self_reboot.log';
        $scriptFile = tempnam(sys_get_temp_dir(), 'auto-deploy-self-reboot-');
        if ($scriptFile === false) {
            Response::json([
                'success' => false,
                'message' => 'Auto Deploy self reboot 임시 스크립트를 생성할 수 없습니다.',
            ], 500);
            return;
        }

        $restartScript = $this->selfRebootScript($root, $port, $phpBinary, $logFile, $scriptFile);
        if (file_put_contents($scriptFile, $restartScript) === false || !chmod($scriptFile, 0700)) {
            @unlink($scriptFile);
            Response::json([
                'success' => false,
                'message' => 'Auto Deploy self reboot 임시 스크립트를 저장할 수 없습니다.',
            ], 500);
            return;
        }

        $command = 'nohup setsid bash ' . escapeshellarg($scriptFile) . ' >> ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null &';

        try {
            $process = proc_open(['bash', '-lc', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                @unlink($scriptFile);
                Response::json([
                    'success' => false,
                    'message' => 'Auto Deploy self reboot 명령을 시작할 수 없습니다.',
                    'detail' => 'proc_open returned a non-resource process handle.',
                ], 500);
                return;
            }

            $stdout = trim(stream_get_contents($pipes[1]) ?: '');
            $stderr = trim(stream_get_contents($pipes[2]) ?: '');
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);

            if ($code !== 0) {
                @unlink($scriptFile);
                Response::json([
                    'success' => false,
                    'message' => 'Auto Deploy self reboot 명령 실행에 실패했습니다.',
                    'exit_code' => $code,
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ], 409);
                return;
            }

            Response::json([
                'success' => true,
                'message' => 'Auto Deploy self reboot를 예약했습니다. Auto Deploy 리스너만 안전하게 재시작하므로 잠시 뒤 페이지를 새로고침해 주세요.',
                'port' => $port,
                'log_file' => $logFile,
            ]);
        } catch (\Throwable $exception) {
            @unlink($scriptFile);
            Response::json([
                'success' => false,
                'message' => 'Auto Deploy self reboot 처리 중 PHP 예외가 발생했습니다.',
                'detail' => $exception->getMessage(),
            ], 500);
        }
    }

    private function selfRebootScript(string $root, int $port, string $phpBinary, string $logFile, string $scriptFile): string
    {
        return implode("\n", [
            '#!/usr/bin/env bash',
            'set -Eeuo pipefail',
            'log_file=' . escapeshellarg($logFile),
            'script_file=' . escapeshellarg($scriptFile),
            'root_dir=' . escapeshellarg($root),
            'port=' . escapeshellarg((string) $port),
            'php_binary=' . escapeshellarg($phpBinary),
            'log() { echo "[$(date -Is)] $*"; }',
            'cleanup() { rm -f "$script_file"; }',
            '# Do not use fuser -k here: it can terminate reverse proxies or client processes that only touch this port.',
            '# Kill only the TCP LISTEN owner so unrelated sites keep running during self reboot.',
            'listener_pids() {',
            '  if command -v lsof >/dev/null 2>&1; then',
            '    lsof -nP -iTCP:"${port}" -sTCP:LISTEN -t 2>/dev/null | grep -E "^[0-9]+$" | sort -u',
            '  fi',
            '}',
            'release_auto_deploy_listener() {',
            '  local pids pid',
            '  pids="$(listener_pids || true)"',
            '  if [ -z "${pids}" ]; then log "no listener on port ${port}"; return 0; fi',
            '  log "terminate Auto Deploy listener pid(s): ${pids}"',
            '  while IFS= read -r pid; do',
            '    [ -n "${pid}" ] || continue',
            '    kill "${pid}" 2>/dev/null || true',
            '  done <<EOF_PIDS',
            '${pids}',
            'EOF_PIDS',
            '  for attempt in $(seq 1 15); do',
            '    pids="$(listener_pids || true)"',
            '    [ -n "${pids}" ] || return 0',
            '    sleep 1',
            '  done',
            '  pids="$(listener_pids || true)"',
            '  [ -n "${pids}" ] || return 0',
            '  log "force terminate Auto Deploy listener pid(s): ${pids}"',
            '  while IFS= read -r pid; do',
            '    [ -n "${pid}" ] || continue',
            '    kill -9 "${pid}" 2>/dev/null || true',
            '  done <<EOF_PIDS',
            '${pids}',
            'EOF_PIDS',
            '}',
            'trap cleanup EXIT',
            'sleep 2',
            'log "self reboot start root=${root_dir} port=${port}"',
            'cd "$root_dir"',
            'log "git fetch --all"',
            'git fetch --all',
            'log "git reset --hard origin/main"',
            'git reset --hard origin/main',
            'if [ -d .next ]; then log "rm -rf .next"; rm -rf .next; fi',
            'log "release Auto Deploy listener on port ${port}"',
            'release_auto_deploy_listener',
            'log "start php built-in server"',
            'nohup "$php_binary" -S "0.0.0.0:${port}" -t public > app.log 2>&1 < /dev/null &',
            'server_pid=$!',
            'log "started pid=${server_pid}"',
            'for attempt in $(seq 1 30); do',
            '  if curl -fsS --max-time 2 "http://127.0.0.1:${port}/login" >/dev/null 2>&1; then log "ready attempt=${attempt}"; exit 0; fi',
            '  sleep 1',
            'done',
            'log "ready check failed"',
            'exit 1',
            '',
        ]);
    }

    public function rebootAndRestore(Request $request): void
    {
        if ($request->method() !== 'POST') {
            Response::json(['message' => 'Method not allowed.'], 405);
            return;
        }

        $status = (new RebootAutomationStatusService())->status();
        if (!$status['installed']) {
            Response::json([
                'success' => false,
                'message' => '재부팅 자동화 기능이 아직 설치되지 않았습니다. 설치 가이드를 확인해 주세요.',
                'status' => $status,
            ], 428);
            return;
        }

        $command = ['sudo', '/usr/local/sbin/auto-reboot-deploy.sh'];
        $commandText = implode(' ', $command);

        try {
            $descriptor = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open($command, $descriptor, $pipes);
            if (!is_resource($process)) {
                Response::json([
                    'success' => false,
                    'message' => '서버 재부팅 자동화 명령을 시작할 수 없습니다.',
                    'detail' => 'proc_open returned a non-resource process handle.',
                ], 500);
                return;
            }

            $stdout = trim(stream_get_contents($pipes[1]) ?: '');
            $stderr = trim(stream_get_contents($pipes[2]) ?: '');
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);

            if ($code !== 0) {
                Response::json([
                    'success' => false,
                    'message' => '서버 재부팅 자동화 명령 실행에 실패했습니다.',
                    'exit_code' => $code,
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'command' => $commandText,
                ], 409);
                return;
            }

            Response::json([
                'success' => true,
                'message' => '서버 재부팅 및 자동 안정화버전 배포가 예약되었습니다.',
            ]);
        } catch (\Throwable $exception) {
            Response::json([
                'success' => false,
                'message' => '서버 재부팅 자동화 처리 중 PHP 예외가 발생했습니다.',
                'detail' => $exception->getMessage(),
                'command' => $commandText,
            ], 500);
        }
    }

    public function rebootDeployLog(): void
    {
        $path = $this->rebootDeployLogPath();
        if (!is_readable($path)) {
            Response::json([
                'success' => true,
                'log' => '',
                'message' => '재부팅 자동화 로그 파일을 읽을 수 없습니다.',
            ]);
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            Response::json([
                'success' => false,
                'log' => '',
                'message' => '재부팅 자동화 로그를 읽을 수 없습니다.',
            ], 500);
            return;
        }

        Response::json([
            'success' => true,
            'log' => implode(PHP_EOL, array_slice($lines, -200)),
        ]);
    }

    private function rebootDeployLogPath(): string
    {
        return '/var/log/auto_deploy/reboot-deploy.log';
    }

    public function deployStatus(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(5);
        }

        try {
            Response::json((new DeployService())->deploymentStatus());
        } catch (\Throwable $throwable) {
            Response::json([
                'deploying' => false,
                'locked' => false,
                'has_running' => false,
                'stale_failed' => 0,
                'stale_after_seconds' => 0,
                'running' => [],
                'error' => $throwable->getMessage(),
            ], 503);
        }
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
