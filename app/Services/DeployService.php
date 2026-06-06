<?php

namespace App\Services;

use App\Repositories\DeployHistoryRepository;
use App\Repositories\DeployProjectRepository;
use App\Repositories\DeployVersionRepository;

final class DeployService
{
    private const PROJECT_TIMEOUT_SECONDS = 300;
    private const COMMAND_TIMEOUT_SECONDS = 300;
    private const STALE_RUNNING_SECONDS = 600;
    private const AUTO_DEPLOY_PORT = 9090;

    private DeployProjectRepository $projects;
    private DeployVersionRepository $versions;
    private DeployHistoryRepository $histories;
    private DeploymentLock $lock;
    private ReportService $reports;
    private array $stdout = [];
    private array $stderr = [];
    private ?string $failureReason = null;
    private ?int $deadlineAt = null;

    public function __construct()
    {
        $this->projects = new DeployProjectRepository();
        $this->versions = new DeployVersionRepository();
        $this->histories = new DeployHistoryRepository();
        $this->lock = new DeploymentLock();
        $this->reports = new ReportService();
    }

    public function deployLatest(int $projectId): array
    {
        return $this->deploy($projectId, null, 'origin/main', '최신버전 빌드');
    }

    public function deployStable(int $projectId): array
    {
        $stable = $this->versions->findStableByProject($projectId);
        if ($stable === null) {
            throw new \RuntimeException('안정화버전이 등록되어 있지 않습니다.');
        }
        if (empty($stable['git_commit_hash'])) {
            throw new \RuntimeException('안정화버전에 Commit Hash가 등록되어 있지 않습니다.');
        }

        return $this->deploy($projectId, $stable, (string) $stable['git_commit_hash'], '안정화버전 빌드');
    }

    public function deployVersion(int $projectId, int $versionId): array
    {
        $version = $this->versions->find($versionId);
        if ($version === null || (int) $version['project_id'] !== $projectId || (int) $version['is_active'] !== 1) {
            throw new \RuntimeException('배포할 버전을 찾을 수 없습니다.');
        }
        if (empty($version['git_commit_hash'])) {
            throw new \RuntimeException('선택한 버전에 Commit Hash가 등록되어 있지 않습니다.');
        }

        return $this->deploy($projectId, $version, (string) $version['git_commit_hash'], '특정버전 배포');
    }

    public function isDeploying(): bool
    {
        $this->histories->failStaleRunning(self::STALE_RUNNING_SECONDS);

        return $this->lock->isLocked() || $this->histories->hasRunning();
    }

    private function deploy(int $projectId, ?array $version, string $targetRef, string $deployType): array
    {
        if (!$this->lock->acquire()) {
            throw new \RuntimeException('다른 배포가 진행 중입니다. 잠시 후 다시 시도해 주세요.');
        }

        $history = null;
        $project = null;
        $this->stdout = [];
        $this->stderr = [];
        $this->failureReason = null;
        $this->deadlineAt = time() + self::PROJECT_TIMEOUT_SECONDS;

        try {
            $project = $this->projects->find($projectId);
            if ($project === null || (int) $project['is_active'] !== 1) {
                throw new \RuntimeException('활성 프로젝트를 찾을 수 없습니다.');
            }

            $this->validateProject($project);
            $this->validateGitRef($targetRef);

            $startedAt = $this->now();
            $history = $this->histories->create($projectId, [
                'deploy_version_id' => $version['id'] ?? null,
                'deploy_status' => 'running',
                'requested_commit_hash' => $targetRef,
                'started_at' => $startedAt,
            ]);

            $success = $this->runRuntimeFlow($project, $targetRef);
            $deployedCommit = $success ? $this->currentCommit((string) $project['server_path']) : null;
            $status = $success ? 'success' : 'failed';
            $endedAt = $this->now();
            $reportFile = $this->reports->createReport($project, [
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'deploy_type' => $deployType,
                'version_name' => $version['version_name'] ?? '최신 main',
                'requested_commit_hash' => $targetRef,
                'deployed_commit_hash' => $deployedCommit,
                'result' => $status,
                'stdout' => implode(PHP_EOL, $this->stdout),
                'stderr' => implode(PHP_EOL, $this->stderr),
                'failure_reason' => $this->failureReason,
            ]);

            $updated = $this->histories->update((int) $history['id'], [
                'deploy_status' => $status,
                'deployed_commit_hash' => $deployedCommit,
                'ended_at' => $endedAt,
                'report_file' => $reportFile,
            ]);
            $this->reports->pruneProjectReports($project);

            return $updated ?? $history;
        } catch (\Throwable $throwable) {
            $this->failureReason = $this->failureReason ?? $throwable->getMessage();
            if ($history !== null && $project !== null) {
                $endedAt = $this->now();
                $reportFile = $this->reports->createReport($project, [
                    'started_at' => $history['started_at'] ?? '',
                    'ended_at' => $endedAt,
                    'deploy_type' => $deployType,
                    'version_name' => $version['version_name'] ?? '최신 main',
                    'requested_commit_hash' => $targetRef,
                    'deployed_commit_hash' => null,
                    'result' => 'failed',
                    'stdout' => implode(PHP_EOL, $this->stdout),
                    'stderr' => implode(PHP_EOL, $this->stderr),
                    'failure_reason' => $this->failureReason,
                ]);
                $this->histories->update((int) $history['id'], [
                    'deploy_status' => 'failed',
                    'ended_at' => $endedAt,
                    'report_file' => $reportFile,
                ]);
                $this->reports->pruneProjectReports($project);
            }
            throw $throwable;
        } finally {
            $this->deadlineAt = null;
            $this->lock->release();
        }
    }

    private function runRuntimeFlow(array $project, string $targetRef): bool
    {
        $runtime = (string) $project['runtime_type'];
        $path = (string) $project['server_path'];
        $port = (int) $project['port'];

        if (!$this->ensureProjectTimeRemaining('배포 시작')) {
            return false;
        }

        if (!$this->runCommand(['git', 'fetch', '--all'], $path)) {
            return $this->fail('git fetch --all 실패');
        }
        if (!$this->runCommand(['git', 'reset', '--hard', $targetRef], $path)) {
            return $this->fail('git reset --hard 실패');
        }

        if ($runtime === 'nextjs_bun') {
            $processName = $this->pm2ProcessName($project);
            $this->stdout[] = sprintf('프로젝트 배포 시작: id=%s name=%s runtime=%s path=%s port=%d pm2=%s',
                (string) ($project['id'] ?? ''),
                (string) ($project['project_name'] ?? $project['project_key'] ?? ''),
                $runtime,
                $path,
                $port,
                $processName
            );
            $this->stdout[] = '[STEP] npm ci';
            if (!$this->runCommand(['npm', 'ci'], $path)) {
                return $this->fail('npm ci 실패: 기존 서비스는 종료하지 않습니다.');
            }
            $this->stdout[] = '[STEP] clean .next';
            if (!$this->runShellCommand('rm -rf .next', $path)) {
                return $this->fail('rm -rf .next 실패: 기존 서비스는 종료하지 않습니다.');
            }
            $this->stdout[] = '[STEP] bun run build';
            if (!$this->runCommand(['bun', 'run', 'build'], $path)) {
                return $this->fail('bun run build 실패: 기존 서비스는 종료하지 않습니다.');
            }

            $this->stdout[] = '[STEP] stop only this project process/port';
            if (!$this->deletePm2Process($processName, $path)) {
                return $this->fail('pm2 기존 프로세스 종료 실패: ' . $processName);
            }
            if (!$this->stopProjectPort($port)) {
                return $this->fail('프로젝트 포트 종료 실패: ' . $port);
            }

            $this->stdout[] = '[STEP] pm2 start only this project: ' . $processName;
            $pm2Command = 'env PORT=' . escapeshellarg((string) $port)
                . ' pm2 start bun --name ' . escapeshellarg($processName) . ' -- run start -H 0.0.0.0';
            if (!$this->runLoginShellCommand($pm2Command, $path)) {
                $this->cleanupProjectRuntime($project, $path, $port);
                return $this->fail('pm2 서비스 시작 실패: ' . $processName);
            }
            if (!$this->waitForPortListening($port)) {
                $this->cleanupProjectRuntime($project, $path, $port);
                return $this->fail('포트 LISTEN 확인 실패: ' . $port . ' (pm2=' . $processName . ')');
            }
            $this->stdout[] = '[DONE] 프로젝트 서비스 시작 및 포트 확인 완료: ' . $processName . ' port=' . $port;
            return true;
        }

        if ($runtime === 'python_static') {
            $this->stdout[] = sprintf('프로젝트 배포 시작: id=%s name=%s runtime=%s path=%s port=%d',
                (string) ($project['id'] ?? ''),
                (string) ($project['project_name'] ?? $project['project_key'] ?? ''),
                $runtime,
                $path,
                $port
            );
            $this->stdout[] = '[STEP] stop only this project port';
            if (!$this->stopProjectPort($port)) {
                return $this->fail('프로젝트 포트 종료 실패: ' . $port);
            }
            $this->stdout[] = '[STEP] python_static start';
            if (!$this->runShellCommand('nohup python3 -m http.server ' . $port . ' --bind 0.0.0.0 > app.log 2>&1 &', $path)) {
                $this->stopProjectPort($port);
                return $this->fail('python_static 서비스 시작 실패');
            }
            if (!$this->waitForPortListening($port)) {
                $this->stopProjectPort($port);
                return $this->fail('포트 LISTEN 확인 실패: ' . $port);
            }
            $this->stdout[] = '[DONE] 프로젝트 서비스 시작 및 포트 확인 완료: port=' . $port;
            return true;
        }

        throw new \RuntimeException('지원하지 않는 runtime_type 입니다: ' . $runtime);
    }

    private function validateProject(array $project): void
    {
        $path = (string) $project['server_path'];
        if (!is_dir($path)) {
            throw new \RuntimeException('server_path 디렉터리를 찾을 수 없습니다: ' . $path);
        }
        if (!is_dir($path . '/.git')) {
            throw new \RuntimeException('server_path는 Git 저장소여야 합니다: ' . $path);
        }

        $port = (int) $project['port'];
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('포트 값이 올바르지 않습니다.');
        }
        if ($port === self::AUTO_DEPLOY_PORT) {
            throw new \RuntimeException('Auto Deploy 포트 9090은 프로젝트 배포 대상으로 사용할 수 없습니다.');
        }
    }

    private function validateGitRef(string $ref): void
    {
        if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $ref)) {
            throw new \RuntimeException('Git ref 형식이 올바르지 않습니다.');
        }
    }

    private function stopProjectPort(int $port): bool
    {
        if ($port === self::AUTO_DEPLOY_PORT) {
            $this->stderr[] = 'Auto Deploy 보호 포트 9090은 종료하지 않습니다.';
            return false;
        }

        $this->stdout[] = '프로젝트 포트만 종료합니다: ' . $port;
        return $this->runShellCommand('pids=$(lsof -ti tcp:' . $port . ' 2>/dev/null || true); if [ -n "$pids" ]; then kill $pids; fi', null);
    }

    private function deletePm2Process(string $processName, string $cwd): bool
    {
        $this->stdout[] = 'PM2 프로세스만 종료합니다: ' . $processName;
        return $this->runLoginShellCommand('pm2 delete ' . escapeshellarg($processName) . ' >/dev/null 2>&1 || true', $cwd);
    }

    private function cleanupProjectRuntime(array $project, string $cwd, int $port): void
    {
        $processName = $this->pm2ProcessName($project);
        $this->stderr[] = '프로젝트 실패 cleanup 시작: pm2=' . $processName . ' port=' . $port;
        $this->deletePm2Process($processName, $cwd);
        $this->stopProjectPort($port);
        $this->stderr[] = '프로젝트 실패 cleanup 종료: pm2=' . $processName . ' port=' . $port;
    }

    private function waitForPortListening(int $port): bool
    {
        $this->stdout[] = '포트 LISTEN 확인 대기: ' . $port . ' (max_attempts=30, interval=2s)';
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            if (!$this->ensureProjectTimeRemaining('포트 LISTEN 확인')) {
                return false;
            }
            if ($this->isPortListening($port)) {
                $this->stdout[] = '포트가 LISTEN 상태입니다: ' . $port . ' (attempt=' . $attempt . ')';
                return true;
            }
            $remaining = $this->remainingProjectSeconds();
            if ($attempt < 30 && $remaining > 0) {
                sleep(min(2, $remaining));
            }
        }

        $this->stderr[] = '포트 LISTEN 대기 시간이 초과되었습니다: ' . $port . ' (attempts=30, interval=2s)';
        return false;
    }

    private function isPortListening(int $port): bool
    {
        $command = 'lsof -nP -iTCP:' . $port . ' -sTCP:LISTEN -t >/dev/null 2>&1';
        exec($command, $output, $code);

        return $code === 0;
    }

    private function pm2ProcessName(array $project): string
    {
        $name = (string) ($project['project_key'] ?? $project['project_name'] ?? ('project-' . ($project['id'] ?? 'unknown')));
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) ?: 'auto-deploy-project';

        return trim($name, '-') ?: 'auto-deploy-project';
    }

    private function runLoginShellCommand(string $command, string $cwd): bool
    {
        return $this->runShellCommand('bash -lc ' . escapeshellarg('cd ' . escapeshellarg($cwd) . ' && ' . $command), null);
    }

    private function currentCommit(string $cwd): ?string
    {
        $output = [];
        $code = 0;
        exec('cd ' . escapeshellarg($cwd) . ' && git rev-parse HEAD 2>/dev/null', $output, $code);

        return $code === 0 ? ($output[0] ?? null) : null;
    }

    private function runCommand(array $command, string $cwd): bool
    {
        $escaped = array_map('escapeshellarg', $command);
        return $this->runShellCommand(implode(' ', $escaped), $cwd);
    }

    private function timedShellCommand(string $command, int $timeout): string
    {
        return 'timeout --kill-after=5s ' . (int) $timeout . 's bash -lc ' . escapeshellarg($command);
    }

    private function runShellCommand(string $command, ?string $cwd): bool
    {
        if (!$this->ensureProjectTimeRemaining('명령 실행 전')) {
            return false;
        }

        $timeout = min(self::COMMAND_TIMEOUT_SECONDS, $this->remainingProjectSeconds());
        if ($timeout <= 0) {
            return $this->fail('프로젝트 배포 타임아웃으로 명령을 실행하지 않습니다: ' . $command);
        }

        $this->stdout[] = '$ ' . $command;
        $this->stdout[] = 'command timeout: ' . $timeout . 's';
        $timedCommand = $this->timedShellCommand($command, $timeout);
        $this->stdout[] = '$ ' . $timedCommand;
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($timedCommand, $descriptor, $pipes, $cwd ?: null);
        if (!is_resource($process)) {
            $this->failureReason = '명령 실행을 시작할 수 없습니다: ' . $command;
            return false;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = time();
        $timedOut = false;
        $exitCode = null;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) ($status['exitcode'] ?? -1);
                break;
            }

            if ((time() - $startedAt) >= $timeout || $this->remainingProjectSeconds() <= 0) {
                $timedOut = true;
                proc_terminate($process);
                usleep(300000);
                $status = proc_get_status($process);
                if ($status['running'] && defined('SIGKILL')) {
                    proc_terminate($process, SIGKILL);
                }
                break;
            }

            usleep(100000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code === -1 && $exitCode !== null && $exitCode !== -1) {
            $code = $exitCode;
        }

        if ($stdout !== '') {
            $this->stdout[] = trim($stdout);
        }
        if ($stderr !== '') {
            $this->stderr[] = '$ ' . $command;
            $this->stderr[] = trim($stderr);
        }

        if ($timedOut) {
            $this->stderr[] = '명령 타임아웃: ' . $command . ' (timeout ' . $timeout . 's)';
            $this->failureReason = $this->failureReason ?? '명령 타임아웃: ' . $command;
            return false;
        }

        $this->stdout[] = 'exit code: ' . $code;
        if ($code === 124 && $this->failureReason === null) {
            $this->failureReason = '명령 타임아웃: ' . $command . ' (timeout ' . $timeout . 's)';
        } elseif ($code !== 0 && $this->failureReason === null) {
            $this->failureReason = '명령 실패: ' . $command . ' (exit code ' . $code . ')';
        }

        return $code === 0;
    }


    private function ensureProjectTimeRemaining(string $context): bool
    {
        $remaining = $this->remainingProjectSeconds();
        if ($remaining > 0) {
            return true;
        }

        return $this->fail('프로젝트 배포 타임아웃: ' . $context . ' (limit ' . self::PROJECT_TIMEOUT_SECONDS . 's)');
    }

    private function remainingProjectSeconds(): int
    {
        if ($this->deadlineAt === null) {
            return self::COMMAND_TIMEOUT_SECONDS;
        }

        return max(0, $this->deadlineAt - time());
    }

    private function fail(string $reason): bool
    {
        $this->failureReason = $this->failureReason ?? $reason;
        $this->stderr[] = $reason;

        return false;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
