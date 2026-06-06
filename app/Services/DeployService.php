<?php

namespace App\Services;

use App\Repositories\DeployHistoryRepository;
use App\Repositories\DeployProjectRepository;
use App\Repositories\DeployVersionRepository;

final class DeployService
{
    private const PROJECT_TIMEOUT_SECONDS = 600;
    private const COMMAND_TIMEOUT_SECONDS = 300;
    private const STALE_RUNNING_SECONDS = 720;
    private const AUTO_DEPLOY_PORT = 9090;
    private const DEFAULT_PORT_LISTEN_ATTEMPTS = 30;
    private const NEXTJS_BUN_PORT_LISTEN_ATTEMPTS = 90;
    private const PORT_LISTEN_INTERVAL_SECONDS = 2;

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
        return (bool) $this->deploymentStatus()['deploying'];
    }

    /**
     * @return array{deploying:bool,locked:bool,has_running:bool,stale_failed:int,stale_after_seconds:int,running:array<int,array<string,mixed>>}
     */
    public function deploymentStatus(): array
    {
        $staleFailed = $this->histories->failStaleRunning(self::STALE_RUNNING_SECONDS);
        $running = $this->histories->running(10);
        $locked = $this->lock->isLocked();

        return [
            'deploying' => $locked || $running !== [],
            'locked' => $locked,
            'has_running' => $running !== [],
            'stale_failed' => $staleFailed,
            'stale_after_seconds' => self::STALE_RUNNING_SECONDS,
            'running' => $running,
        ];
    }

    private function deploy(int $projectId, ?array $version, string $targetRef, string $deployType): array
    {
        $this->prepareLongRunningExecution();
        if (!$this->lock->acquire()) {
            throw new \RuntimeException('다른 배포가 진행 중입니다. 잠시 후 다시 시도해 주세요.');
        }

        $history = null;
        $project = null;
        $reportFile = null;
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
            $this->stdout[] = '[START] at=' . $startedAt
                . ' project_id=' . $projectId
                . ' project_key=' . (string) ($project['project_key'] ?? '')
                . ' runtime=' . (string) ($project['runtime_type'] ?? '')
                . ' path=' . (string) ($project['server_path'] ?? '')
                . ' expected_port=' . (int) ($project['port'] ?? 0)
                . ' target_ref=' . $targetRef;
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
            if ($success) {
                $this->stdout[] = '[DEPLOY_SUCCESS_MARK] at=' . $endedAt . ' deployed_commit=' . ($deployedCommit ?? '(none)');
            } else {
                $this->stderr[] = '[DEPLOY_FAILED_MARK] at=' . $endedAt . ' reason=' . ($this->failureReason ?? 'unknown');
            }
            $this->stdout[] = '런타임 플로우 종료: status=' . $status . ' deployed_commit=' . ($deployedCommit ?? '(none)');
            $this->stdout[] = 'deploy_history 업데이트 예정: id=' . (int) $history['id'] . ' status=' . $status . ' ended_at=' . $endedAt;

            $reportData = $this->reportData($startedAt, $endedAt, $deployType, $version, $targetRef, $deployedCommit, $status);
            $reportFile = $this->reports->createReport($project, $reportData);
            $this->stdout[] = '리포트 파일 생성: ' . $reportFile;

            $updated = $this->histories->update((int) $history['id'], [
                'deploy_status' => $status,
                'deployed_commit_hash' => $deployedCommit,
                'ended_at' => $endedAt,
                'report_file' => $reportFile,
            ]);
            $finalHistory = $updated ?? $history;
            $this->stdout[] = 'deploy_history 업데이트 완료: id=' . (int) ($finalHistory['id'] ?? $history['id'])
                . ' status=' . (string) ($finalHistory['deploy_status'] ?? $status)
                . ' ended_at=' . (string) ($finalHistory['ended_at'] ?? $endedAt)
                . ' report_file=' . $reportFile;
            $this->stdout[] = 'DeploymentLock release 예정: currently_locked=' . ($this->lock->isLocked() ? 'yes' : 'no');
            $this->reports->writeReport($reportFile, $project, $this->reportData($startedAt, $endedAt, $deployType, $version, $targetRef, $deployedCommit, $status, $reportFile));
            $this->pruneProjectReportsIfPossible($project);

            return $finalHistory;
        } catch (\Throwable $throwable) {
            $this->failureReason = $this->failureReason ?? $throwable->getMessage();
            if ($history !== null && $project !== null) {
                $endedAt = $this->now();
                $this->stderr[] = '[DEPLOY_FAILED_MARK] at=' . $endedAt . ' reason=' . $this->failureReason;
                $this->stderr[] = '배포 예외 발생: ' . $throwable->getMessage() . ' file=' . $throwable->getFile() . ' line=' . $throwable->getLine();
                $this->stdout[] = 'deploy_history 실패 업데이트 예정: id=' . (int) $history['id'] . ' ended_at=' . $endedAt;
                $reportFile = $this->reports->createReport($project, $this->reportData(
                    (string) ($history['started_at'] ?? ''),
                    $endedAt,
                    $deployType,
                    $version,
                    $targetRef,
                    null,
                    'failed'
                ));
                $this->stdout[] = '리포트 파일 생성: ' . $reportFile;
                $updated = $this->histories->update((int) $history['id'], [
                    'deploy_status' => 'failed',
                    'ended_at' => $endedAt,
                    'report_file' => $reportFile,
                ]);
                $this->stdout[] = 'deploy_history 실패 업데이트 완료: id=' . (int) ($updated['id'] ?? $history['id'])
                    . ' status=' . (string) ($updated['deploy_status'] ?? 'failed')
                    . ' ended_at=' . (string) ($updated['ended_at'] ?? $endedAt)
                    . ' report_file=' . $reportFile;
                $this->stdout[] = 'DeploymentLock release 예정: currently_locked=' . ($this->lock->isLocked() ? 'yes' : 'no');
                $this->reports->writeReport($reportFile, $project, $this->reportData(
                    (string) ($history['started_at'] ?? ''),
                    $endedAt,
                    $deployType,
                    $version,
                    $targetRef,
                    null,
                    'failed',
                    $reportFile
                ));
                $this->pruneProjectReportsIfPossible($project);
            }
            throw $throwable;
        } finally {
            $this->deadlineAt = null;
            $this->lock->release();
            if (is_string($reportFile) && $reportFile !== '') {
                @file_put_contents(
                    $reportFile,
                    PHP_EOL . '[LOCK_RELEASED] currently_locked='
                    . ($this->lock->isLocked() ? 'yes' : 'no') . PHP_EOL,
                    FILE_APPEND
                );
            }
        }
    }

    private function pruneProjectReportsIfPossible(array $project): void
    {
        try {
            $this->reports->pruneProjectReports($project);
        } catch (\Throwable $throwable) {
            $this->stderr[] = '리포트 정리 실패(배포 결과는 유지): ' . $throwable->getMessage();
        }
    }

    private function prepareLongRunningExecution(): void
    {
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(self::PROJECT_TIMEOUT_SECONDS + 120);
        }
    }

    private function reportData(
        string $startedAt,
        string $endedAt,
        string $deployType,
        ?array $version,
        string $targetRef,
        ?string $deployedCommit,
        string $status,
        ?string $reportFile = null
    ): array {
        return [
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'deploy_type' => $deployType,
            'version_name' => $version['version_name'] ?? '최신 main',
            'requested_commit_hash' => $targetRef,
            'deployed_commit_hash' => $deployedCommit,
            'result' => $status,
            'report_file' => $reportFile,
            'stdout' => implode(PHP_EOL, $this->stdout),
            'stderr' => implode(PHP_EOL, $this->stderr),
            'failure_reason' => $this->failureReason,
        ];
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
            $startMode = $this->nextjsBunStartMode($project);
            $this->logProjectPortConfiguration($project, $startMode === 'pm2' ? $processName : null, $startMode);
            $this->stdout[] = sprintf('프로젝트 배포 시작: id=%s name=%s runtime=%s start_mode=%s path=%s port=%d pm2=%s',
                (string) ($project['id'] ?? ''),
                (string) ($project['project_name'] ?? $project['project_key'] ?? ''),
                $runtime,
                $startMode,
                $path,
                $port,
                $startMode === 'pm2' ? $processName : 'n/a'
            );

            if ($startMode === 'pm2') {
                $this->stdout[] = '[STEP] npm ci';
                if (!$this->runCommand(['npm', 'ci'], $path)) {
                    return $this->fail('npm ci 실패: 기존 서비스는 종료하지 않습니다.');
                }
            } else {
                $this->stdout[] = '[STEP] npm ci skipped for nohup start mode; 수동 검증 명령과 동일하게 유지합니다.';
            }

            $this->stdout[] = '[STEP] clean .next';
            if (!$this->runShellCommand('rm -rf .next', $path)) {
                return $this->fail('rm -rf .next 실패: 기존 서비스는 종료하지 않습니다.');
            }
            $this->stdout[] = '[STEP] bun run build';
            if (!$this->runCommand(['bun', 'run', 'build'], $path)) {
                return $this->fail('bun run build 실패: 기존 서비스는 종료하지 않습니다.');
            }

            if ($startMode === 'nohup') {
                $this->stdout[] = '[STEP] stop only this project port for nohup start';
                if (!$this->stopProjectPort($port)) {
                    return $this->fail('프로젝트 포트 종료 실패: ' . $port);
                }

                $startCommand = 'nohup env PORT=' . escapeshellarg((string) $port)
                    . ' bun run start -H 0.0.0.0 > app.log 2>&1 &';
                $this->stdout[] = '[NOHUP_START] at=' . $this->now()
                    . ' cwd=' . $path
                    . ' expected_port=' . $port
                    . ' app_log=' . rtrim($path, '/') . '/app.log'
                    . ' command=' . $startCommand;
                if (!$this->runLoginShellCommand($startCommand, $path)) {
                    return $this->fail('nohup 서비스 시작 실패: port=' . $port);
                }
                $this->stdout[] = '[APP_LOG] file=' . rtrim($path, '/') . '/app.log exists=' . (is_file(rtrim($path, '/') . '/app.log') ? 'yes' : 'no');

                if (!$this->waitForProjectPortListening(
                    $port,
                    self::NEXTJS_BUN_PORT_LISTEN_ATTEMPTS,
                    self::PORT_LISTEN_INTERVAL_SECONDS
                )) {
                    return $this->fail('포트 LISTEN 확인 실패: ' . $port . ' (start_mode=nohup)');
                }
                if (!$this->waitForHttpResponse(
                    $port,
                    self::DEFAULT_PORT_LISTEN_ATTEMPTS,
                    self::PORT_LISTEN_INTERVAL_SECONDS
                )) {
                    return $this->fail('HTTP 응답 확인 실패: ' . $port . ' (start_mode=nohup)');
                }

                $this->stdout[] = '[DEPLOY_SUCCESS_MARK] start_mode=nohup port=' . $port;
                $this->stdout[] = '[DONE] 프로젝트 서비스 시작 및 포트/HTTP 확인 완료: start_mode=nohup port=' . $port;
                return true;
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
            $this->stdout[] = '[PM2_START] at=' . $this->now()
                . ' pm2=' . $processName
                . ' cwd=' . $path
                . ' expected_port=' . $port
                . ' command=' . $pm2Command;
            if (!$this->runLoginShellCommand($pm2Command, $path)) {
                $this->cleanupProjectRuntime($project, $path, $port, 'pm2_start_failure');
                return $this->fail('pm2 서비스 시작 실패: ' . $processName);
            }
            if (!$this->waitForProjectPortListening(
                $port,
                self::NEXTJS_BUN_PORT_LISTEN_ATTEMPTS,
                self::PORT_LISTEN_INTERVAL_SECONDS,
                $processName,
                $path
            )) {
                $this->appendPm2Diagnostics($processName, $path, '포트 LISTEN 실패 직전');
                if ($this->shouldCleanupAfterPortFailure($processName, $path, $port)) {
                    $this->cleanupProjectRuntime($project, $path, $port, 'port_listen_failure');
                } else {
                    $this->stderr[] = 'PM2 프로세스가 아직 살아 있어 실패 cleanup을 건너뜁니다: pm2=' . $processName . ' port=' . $port;
                }
                return $this->fail('포트 LISTEN 확인 실패: ' . $port . ' (pm2=' . $processName . ')');
            }
            $this->stdout[] = '[DEPLOY_SUCCESS_MARK] pm2=' . $processName . ' port=' . $port;
            $this->stdout[] = '[DONE] 프로젝트 서비스 시작 및 포트 확인 완료: ' . $processName . ' port=' . $port;
            return true;
        }

        if ($runtime === 'python_static') {
            $this->logProjectPortConfiguration($project, null);
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
            if (!$this->waitForProjectPortListening(
                $port,
                self::DEFAULT_PORT_LISTEN_ATTEMPTS,
                self::PORT_LISTEN_INTERVAL_SECONDS
            )) {
                $this->stopProjectPort($port);
                return $this->fail('포트 LISTEN 확인 실패: ' . $port);
            }
            $this->stdout[] = '[DEPLOY_SUCCESS_MARK] port=' . $port;
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

    private function cleanupProjectRuntime(array $project, string $cwd, int $port, string $reason): void
    {
        $processName = $this->pm2ProcessName($project);
        $snapshot = $this->pm2ProcessSnapshot($processName, $cwd);
        $this->stderr[] = '[CLEANUP_START] at=' . $this->now()
            . ' reason=' . $reason
            . ' pm2=' . $processName
            . ' pm2_status=' . ($snapshot['status'] ?? 'unknown')
            . ' pm2_pid=' . ($snapshot['pid'] ?? 'unknown')
            . ' expected_port=' . $port
            . ' listen=' . $this->listeningPortsSummary($port, $snapshot['pid'] ?? null);
        $this->appendPm2Diagnostics($processName, $cwd, 'cleanup 전 PM2 상태');
        $this->deletePm2Process($processName, $cwd);
        $this->stopProjectPort($port);
        $this->stderr[] = '[CLEANUP_END] at=' . $this->now()
            . ' reason=' . $reason
            . ' pm2=' . $processName
            . ' expected_port=' . $port
            . ' listen=' . $this->listeningPortsSummary($port, $snapshot['pid'] ?? null);
    }

    private function waitForProjectPortListening(
        int $port,
        int $maxAttempts,
        int $intervalSeconds,
        ?string $processName = null,
        ?string $cwd = null
    ): bool {
        $maxWaitSeconds = $maxAttempts * $intervalSeconds;
        $this->stdout[] = '[PORT_CHECK_START] port=' . $port
            . ' max_attempts=' . $maxAttempts
            . ' interval=' . $intervalSeconds . 's'
            . ' max_wait=' . $maxWaitSeconds . 's'
            . ($processName !== null ? ' pm2=' . $processName : '');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (!$this->ensureProjectTimeRemaining('포트 LISTEN 확인')) {
                if ($processName !== null && $cwd !== null) {
                    $this->appendPm2Diagnostics($processName, $cwd, '프로젝트 타임아웃으로 포트 확인 중단');
                }
                $this->stderr[] = '[PORT_CHECK_FAIL] port=' . $port . ' reason=project_timeout attempt=' . $attempt . '/' . $maxAttempts;
                return false;
            }

            $details = $this->portReadinessDetails($port);
            if ((bool) $details['ready']) {
                $this->stdout[] = '[PORT_CHECK_SUCCESS] port=' . $port
                    . ' pid=' . ($details['pid'] ?? 'unknown')
                    . ' source=' . (string) $details['source']
                    . ' attempt=' . $attempt . '/' . $maxAttempts
                    . ' detail=' . (string) $details['detail'];
                return true;
            }

            if ($attempt === 1 || $attempt % 10 === 0 || $attempt === $maxAttempts) {
                $snapshot = ($processName !== null && $cwd !== null) ? $this->pm2ProcessSnapshot($processName, $cwd) : null;
                $status = $snapshot['status'] ?? null;
                $this->stdout[] = '[PORT_CHECK_WAIT] port=' . $port
                    . ' attempt=' . $attempt . '/' . $maxAttempts
                    . ($processName !== null ? ' pm2=' . $processName : '')
                    . ' pm2_status=' . ($status ?? 'n/a')
                    . ' pm2_pid=' . ($snapshot['pid'] ?? 'n/a')
                    . ' listen=' . $this->listeningPortsSummary($port, $snapshot['pid'] ?? null)
                    . ' probes=' . (string) $details['detail'];
                if ($status === 'online') {
                    $this->stdout[] = '[PM2_ONLINE] at=' . $this->now()
                        . ' pm2=' . $processName
                        . ' pm2_pid=' . ($snapshot['pid'] ?? 'unknown')
                        . ' expected_port=' . $port
                        . ' listen=' . $this->listeningPortsSummary($port, $snapshot['pid'] ?? null);
                    $this->stdout[] = 'PM2 프로세스는 online 이지만 포트가 아직 확인되지 않았습니다. 즉시 실패/cleanup 처리하지 않고 계속 대기합니다.';
                } elseif ($processName !== null && $status === null) {
                    $this->stderr[] = 'PM2 프로세스 상태를 확인할 수 없습니다: ' . $processName . ' (attempt=' . $attempt . ')';
                } elseif ($status !== null && $status !== 'online') {
                    $this->stderr[] = 'PM2 프로세스가 online 상태가 아닙니다: ' . $processName . ' status=' . $status . ' (attempt=' . $attempt . ')';
                }
            }

            $remaining = $this->remainingProjectSeconds();
            if ($attempt < $maxAttempts && $remaining > 0) {
                sleep(min($intervalSeconds, $remaining));
            }
        }

        $finalDetails = $this->portReadinessDetails($port);
        if ((bool) $finalDetails['ready']) {
            $this->stdout[] = '[PORT_CHECK_SUCCESS] port=' . $port
                . ' pid=' . ($finalDetails['pid'] ?? 'unknown')
                . ' source=' . (string) $finalDetails['source']
                . ' attempt=final'
                . ' detail=' . (string) $finalDetails['detail'];
            return true;
        }

        $finalSnapshot = ($processName !== null && $cwd !== null) ? $this->pm2ProcessSnapshot($processName, $cwd) : null;
        $this->stderr[] = '[PORT_CHECK_FAIL] port=' . $port
            . ' attempts=' . $maxAttempts
            . ' interval=' . $intervalSeconds . 's'
            . ' max_wait=' . $maxWaitSeconds . 's'
            . ' pm2_status=' . ($finalSnapshot['status'] ?? 'n/a')
            . ' pm2_pid=' . ($finalSnapshot['pid'] ?? 'n/a')
            . ' listen=' . $this->listeningPortsSummary($port, $finalSnapshot['pid'] ?? null)
            . ' probes=' . (string) $finalDetails['detail'];
        if ($processName !== null && $cwd !== null) {
            $this->appendPm2Diagnostics($processName, $cwd, '포트 LISTEN timeout 후 PM2 상태');
        }

        return false;
    }

    private function shouldCleanupAfterPortFailure(string $processName, string $cwd, int $port): bool
    {
        $details = $this->portReadinessDetails($port);
        if ((bool) $details['ready']) {
            $this->stdout[] = '[PORT_CHECK_SUCCESS] cleanup 직전 재확인 성공: port=' . $port
                . ' pid=' . ($details['pid'] ?? 'unknown')
                . ' source=' . (string) $details['source'];
            return false;
        }

        $snapshot = $this->pm2ProcessSnapshot($processName, $cwd);
        $status = $snapshot['status'] ?? null;
        $this->stderr[] = '[CLEANUP_DECISION] port=' . $port
            . ' pm2=' . $processName
            . ' pm2_status=' . ($status ?? 'unknown')
            . ' pm2_pid=' . ($snapshot['pid'] ?? 'unknown')
            . ' listen=' . $this->listeningPortsSummary($port, $snapshot['pid'] ?? null)
            . ' port_ready=no';

        return $status === 'stopped' || $status === 'errored' || $status === 'stopping';
    }

    private function nextjsBunStartMode(array $project): string
    {
        $runtime = strtolower((string) ($project['runtime_type'] ?? ''));
        if (in_array($runtime, ['nextjs_bun_nohup', 'nextjs_bun:nohup'], true)) {
            return 'nohup';
        }

        $serverPath = rtrim((string) ($project['server_path'] ?? ''), '/');
        $projectKey = strtolower((string) ($project['project_key'] ?? ''));
        $projectName = strtolower((string) ($project['project_name'] ?? ''));
        $pathBaseName = strtolower(basename($serverPath));

        if ($serverPath === '/srv/tenaCierge'
            || $pathBaseName === 'tenacierge'
            || str_contains($projectKey, 'tenacierge')
            || str_contains($projectName, 'tenacierge')
            || str_contains($projectName, '컨시어지')) {
            return 'nohup';
        }

        return 'pm2';
    }

    private function pm2ProcessStatus(string $processName, string $cwd): ?string
    {
        return $this->pm2ProcessSnapshot($processName, $cwd)['status'] ?? null;
    }

    /**
     * @return array{status:?string,pid:?int}
     */
    private function pm2ProcessSnapshot(string $processName, string $cwd): array
    {
        $processes = $this->pm2ProcessList($cwd);
        if ($processes === null) {
            return ['status' => null, 'pid' => null];
        }

        foreach ($processes as $process) {
            if (!is_array($process) || (string) ($process['name'] ?? '') !== $processName) {
                continue;
            }

            $environment = $process['pm2_env'] ?? [];
            $status = is_array($environment) ? (string) ($environment['status'] ?? 'unknown') : 'unknown';
            $pid = isset($process['pid']) ? (int) $process['pid'] : null;

            return ['status' => $status, 'pid' => $pid];
        }

        return ['status' => 'missing', 'pid' => null];
    }

    /**
     * @return array<int,array<string,mixed>>|null
     */
    private function pm2ProcessList(string $cwd): ?array
    {
        $output = [];
        $code = 0;
        exec('cd ' . escapeshellarg($cwd) . ' && bash -lc ' . escapeshellarg('timeout --kill-after=2s 10s pm2 jlist 2>/dev/null'), $output, $code);
        if ($code !== 0) {
            return null;
        }

        $decoded = json_decode(implode(PHP_EOL, $output), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function appendPm2Diagnostics(string $processName, string $cwd, string $context): void
    {
        $output = [];
        $code = 0;
        $command = 'timeout --kill-after=2s 10s pm2 describe ' . escapeshellarg($processName) . ' 2>&1 | head -120';
        exec('cd ' . escapeshellarg($cwd) . ' && bash -lc ' . escapeshellarg($command), $output, $code);

        $this->stderr[] = '[PM2 DIAG] ' . $context . ' process=' . $processName . ' cwd=' . $cwd . ' exit_code=' . $code;
        if ($output !== []) {
            $this->stderr[] = implode(PHP_EOL, $output);
        }
    }

    private function waitForHttpResponse(int $port, int $maxAttempts, int $intervalSeconds): bool
    {
        $url = 'http://127.0.0.1:' . $port . '/';
        $this->stdout[] = '[HTTP_CHECK_START] url=' . $url
            . ' max_attempts=' . $maxAttempts
            . ' interval=' . $intervalSeconds . 's';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (!$this->ensureProjectTimeRemaining('HTTP 응답 확인')) {
                $this->stderr[] = '[HTTP_CHECK_FAIL] url=' . $url . ' reason=project_timeout attempt=' . $attempt . '/' . $maxAttempts;
                return false;
            }

            $result = $this->httpStatusCode($url);
            $statusCode = (int) $result['status_code'];
            if ($statusCode > 0 && $statusCode < 500) {
                $this->stdout[] = '[HTTP_CHECK_SUCCESS] url=' . $url
                    . ' status=' . $statusCode
                    . ' attempt=' . $attempt . '/' . $maxAttempts;
                return true;
            }

            if ($attempt === 1 || $attempt % 5 === 0 || $attempt === $maxAttempts) {
                $this->stdout[] = '[HTTP_CHECK_WAIT] url=' . $url
                    . ' status=' . ($statusCode > 0 ? (string) $statusCode : 'none')
                    . ' curl_exit=' . (int) $result['exit_code']
                    . ' attempt=' . $attempt . '/' . $maxAttempts;
            }

            $remaining = $this->remainingProjectSeconds();
            if ($attempt < $maxAttempts && $remaining > 0) {
                sleep(min($intervalSeconds, $remaining));
            }
        }

        $result = $this->httpStatusCode($url);
        $this->stderr[] = '[HTTP_CHECK_FAIL] url=' . $url
            . ' status=' . (((int) $result['status_code']) > 0 ? (string) $result['status_code'] : 'none')
            . ' curl_exit=' . (int) $result['exit_code'];

        return false;
    }

    /**
     * @return array{status_code:int,exit_code:int}
     */
    private function httpStatusCode(string $url): array
    {
        $output = [];
        $code = 0;
        exec('curl -sS --max-time 5 -o /dev/null -w "%{http_code}" ' . escapeshellarg($url) . ' 2>/dev/null', $output, $code);
        $statusCode = isset($output[0]) ? (int) trim((string) $output[0]) : 0;

        return [
            'status_code' => $statusCode,
            'exit_code' => $code,
        ];
    }

    private function isPortListening(int $port): bool
    {
        return (bool) $this->portReadinessDetails($port)['ready'];
    }

    /**
     * @return array{ready:bool,pid:?string,source:string,detail:string}
     */
    private function portReadinessDetails(int $port): array
    {
        $checks = [];

        $lsofOutput = [];
        $lsofCode = 0;
        exec('lsof -nP -iTCP:' . $port . ' -sTCP:LISTEN -t 2>/dev/null', $lsofOutput, $lsofCode);
        $pids = array_values(array_filter(array_map('trim', $lsofOutput), static fn (string $pid): bool => $pid !== ''));
        $checks[] = 'lsof_code=' . $lsofCode . ' pids=' . ($pids === [] ? 'none' : implode(',', $pids));
        if ($lsofCode === 0 && $pids !== []) {
            return [
                'ready' => true,
                'pid' => $pids[0],
                'source' => 'lsof',
                'detail' => implode('; ', $checks),
            ];
        }

        $ssOutput = [];
        $ssCode = 0;
        exec('ss -ltnp 2>/dev/null | grep -E ' . escapeshellarg('(^|[[:space:]])[^[:space:]]*:' . $port . '[[:space:]]'), $ssOutput, $ssCode);
        $checks[] = 'ss_code=' . $ssCode . ' lines=' . count($ssOutput);
        if ($ssCode === 0 && $ssOutput !== []) {
            return [
                'ready' => true,
                'pid' => $this->pidFromSocketLine($ssOutput[0] ?? ''),
                'source' => 'ss',
                'detail' => implode('; ', $checks),
            ];
        }

        $netstatOutput = [];
        $netstatCode = 0;
        exec('netstat -ltnp 2>/dev/null | grep -E ' . escapeshellarg('(^|[[:space:]])[^[:space:]]*:' . $port . '[[:space:]]'), $netstatOutput, $netstatCode);
        $checks[] = 'netstat_code=' . $netstatCode . ' lines=' . count($netstatOutput);
        if ($netstatCode === 0 && $netstatOutput !== []) {
            return [
                'ready' => true,
                'pid' => $this->pidFromSocketLine($netstatOutput[0] ?? ''),
                'source' => 'netstat',
                'detail' => implode('; ', $checks),
            ];
        }

        $tcpOutput = [];
        $tcpCode = 0;
        exec('timeout 2s bash -lc ' . escapeshellarg('</dev/tcp/127.0.0.1/' . $port), $tcpOutput, $tcpCode);
        $checks[] = 'tcp_connect_code=' . $tcpCode;
        if ($tcpCode === 0) {
            return [
                'ready' => true,
                'pid' => null,
                'source' => 'tcp_connect',
                'detail' => implode('; ', $checks),
            ];
        }

        return [
            'ready' => false,
            'pid' => null,
            'source' => 'none',
            'detail' => implode('; ', $checks),
        ];
    }

    private function pidFromSocketLine(string $line): ?string
    {
        if (preg_match('/pid=(\d+)/', $line, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('#\s(\d+)/[^\s]+#', $line, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function logProjectPortConfiguration(array $project, ?string $processName, string $startMode = 'pm2'): void
    {
        $path = (string) $project['server_path'];
        $expectedPort = (int) $project['port'];
        $this->stdout[] = '[PORT_CONFIG] source=auto_deploy project_port=' . $expectedPort
            . ' runtime=' . (string) ($project['runtime_type'] ?? '')
            . ' start_mode=' . $startMode
            . ' pm2=' . ($processName ?? 'n/a')
            . ' path=' . $path
            . ' home=' . (string) (getenv('HOME') ?: '');

        foreach ($this->portEvidenceFiles($path) as $file) {
            $this->stdout[] = '[PORT_CONFIG] ' . $this->summarizePortEvidence($file);
        }
    }

    /**
     * @return array<int,string>
     */
    private function portEvidenceFiles(string $path): array
    {
        $patterns = [
            'package.json',
            'ecosystem.config.js',
            'ecosystem.config.cjs',
            'ecosystem.config.mjs',
            'next.config.js',
            'next.config.mjs',
            'next.config.ts',
            '.env',
            '.env.local',
            '.env.production',
        ];
        $files = [];
        foreach ($patterns as $relativePath) {
            $file = rtrim($path, '/') . '/' . $relativePath;
            if (is_file($file) && is_readable($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function summarizePortEvidence(string $file): string
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return 'file=' . $file . ' readable=no';
        }

        $matches = [];
        preg_match_all('/(?:PORT|port|--port|-p|localhost:|127\.0\.0\.1:|0\.0\.0\.0:)\s*[=:]?\s*["\']?(\d{2,5})/', $content, $matches);
        $ports = array_values(array_unique($matches[1] ?? []));

        return 'file=' . $file
            . ' ports=' . ($ports === [] ? 'none' : implode(',', $ports));
    }

    private function listeningPortsSummary(int $expectedPort, ?int $pid): string
    {
        $parts = [];
        $expectedDetails = $this->portReadinessDetails($expectedPort);
        $parts[] = 'expected:' . $expectedPort . '=' . ((bool) $expectedDetails['ready'] ? 'LISTEN' : 'not_listening')
            . '/source:' . (string) $expectedDetails['source']
            . '/pid:' . ($expectedDetails['pid'] ?? 'unknown');

        if ($pid !== null && $pid > 0) {
            $ports = $this->listListeningPortsForPid($pid);
            $parts[] = 'pm2_pid:' . $pid . '_ports=' . ($ports === [] ? 'none' : implode(',', $ports));
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<int,string>
     */
    private function listListeningPortsForPid(int $pid): array
    {
        $output = [];
        $code = 0;
        exec('lsof -Pan -p ' . $pid . ' -iTCP -sTCP:LISTEN 2>/dev/null', $output, $code);
        if ($code !== 0 || $output === []) {
            return [];
        }

        $ports = [];
        foreach ($output as $line) {
            if (preg_match('/:(\d+)\s+\(LISTEN\)/', $line, $matches) === 1) {
                $ports[] = $matches[1];
            }
        }

        return array_values(array_unique($ports));
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
