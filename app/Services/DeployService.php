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
    private const PROJECT_ENV_UNSET_KEYS = [
        'DB_HOST',
        'DB_PORT',
        'DB_USER',
        'DB_PASSWORD',
        'DB_NAME',
        'DATABASE_URL',
        'MYSQL_HOST',
        'MYSQL_PORT',
        'MYSQL_USER',
        'MYSQL_PASSWORD',
        'MYSQL_DATABASE',
    ];
    private const DEFAULT_PORT_LISTEN_ATTEMPTS = 30;
    private const NEXTJS_BUN_PORT_LISTEN_ATTEMPTS = 90;
    private const PORT_LISTEN_INTERVAL_SECONDS = 2;
    private const NEXTJS_BUILD_ROOT = '/srv/.auto-deploy-builds';
    private const NODE_APP_BUILD_ROOT = '/srv/.auto-deploy-builds-node';
    private const MIN_FREE_BYTES_FOR_NEXTJS_BUILD = 1073741824;
    private const PROJECT_SYSTEMD_CONTROL = '/usr/local/sbin/auto-deploy-project-control';
    private const PROJECT_SYSTEMD_UNIT_PREFIX = 'auto-deploy-project@';

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

        $deploying = $locked || $running !== [];

        return [
            'deploying' => $deploying,
            'locked' => $locked,
            'has_running' => $running !== [],
            'stale_failed' => $staleFailed,
            'stale_after_seconds' => self::STALE_RUNNING_SECONDS,
            'running' => $running,
            'projects' => $this->deploymentProjectStatuses($running, $deploying),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $running
     * @return array<int,array<string,mixed>>
     */
    private function deploymentProjectStatuses(array $running, bool $deploying): array
    {
        $runningByProject = [];
        $oldestRunningAt = null;

        foreach ($running as $history) {
            $projectId = (int) ($history['project_id'] ?? 0);
            if ($projectId <= 0) {
                continue;
            }

            $runningByProject[$projectId] = $history;
            $startedAt = $this->timestampOrNull((string) ($history['started_at'] ?? $history['created_at'] ?? ''));
            if ($startedAt !== null && ($oldestRunningAt === null || $startedAt < $oldestRunningAt)) {
                $oldestRunningAt = $startedAt;
            }
        }

        $batchWindowStart = $oldestRunningAt !== null ? $oldestRunningAt - self::STALE_RUNNING_SECONDS : time() - self::STALE_RUNNING_SECONDS;
        $statuses = [];

        foreach ($this->projects->all(true) as $project) {
            $projectId = (int) $project['id'];
            $latest = $this->histories->latestByProject($projectId);
            $runningHistory = $runningByProject[$projectId] ?? null;
            $state = 'pending';
            $label = '배포대기';
            $history = $latest;

            if ($runningHistory !== null) {
                $state = 'running';
                $label = '배포중';
                $history = $runningHistory;
            } elseif ($deploying && $latest !== null && (string) ($latest['deploy_status'] ?? '') === 'success') {
                $endedAt = $this->timestampOrNull((string) ($latest['ended_at'] ?? $latest['created_at'] ?? ''));
                if ($endedAt !== null && $endedAt >= $batchWindowStart) {
                    $state = 'success';
                    $label = '배포성공';
                }
            } elseif (!$deploying && $latest !== null && (string) ($latest['deploy_status'] ?? '') === 'success') {
                $state = 'success';
                $label = '배포성공';
            }

            $statuses[] = [
                'project_id' => $projectId,
                'project_name' => (string) ($project['project_name'] ?? $project['project_key'] ?? ('project-' . $projectId)),
                'project_key' => (string) ($project['project_key'] ?? ''),
                'state' => $state,
                'label' => $label,
                'started_at' => $history['started_at'] ?? null,
                'ended_at' => $history['ended_at'] ?? null,
                'version_name' => $history['version_name'] ?? null,
            ];
        }

        return $statuses;
    }

    private function timestampOrNull(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value . ' UTC');
        return $timestamp === false ? null : $timestamp;
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
            $targetRef = $this->targetRefForProject($project, $targetRef, $version);
            $this->validateGitRef($targetRef);

            $startedAt = $this->now();
            $this->stdout[] = '[START] at=' . $startedAt
                . ' project_id=' . $projectId
                . ' project_key=' . (string) ($project['project_key'] ?? '')
                . ' runtime=' . (string) ($project['runtime_type'] ?? '')
                . ' branch=' . (string) ($project['branch_name'] ?? '')
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

        if ($runtime === 'nextjs_bun') {
            return $this->runNextjsBunSafeFlow($project, $targetRef);
        }

        if ($runtime === 'node_app') {
            return $this->runNodeAppSafeFlow($project, $targetRef);
        }

        if (!$this->runCommand(['git', 'fetch', '--all'], $path)) {
            return $this->fail('git fetch --all 실패');
        }
        if (!$this->runCommand(['git', 'reset', '--hard', $targetRef], $path)) {
            return $this->fail('git reset --hard 실패');
        }

        if ($runtime === 'python_static') {
            $this->logProjectPortConfiguration($project, 'systemd');
            $this->stdout[] = sprintf('프로젝트 배포 시작: id=%s name=%s runtime=%s path=%s port=%d',
                (string) ($project['id'] ?? ''),
                (string) ($project['project_name'] ?? $project['project_key'] ?? ''),
                $runtime,
                $path,
                $port
            );
            $this->stdout[] = '[STEP] stop project systemd service';
            if (!$this->stopProjectService($project)) {
                return $this->fail('프로젝트 systemd 서비스 종료 실패: ' . $this->projectSystemdUnit($project));
            }
            $this->stdout[] = '[STEP] python_static start via systemd';
            if (!$this->startProjectService($project, 'python3 -m http.server ' . escapeshellarg((string) $port) . ' --bind 0.0.0.0')) {
                $this->stopProjectService($project);
                return $this->fail('python_static systemd 서비스 시작 실패');
            }
            if (!$this->waitForProjectPortListening(
                $port,
                self::DEFAULT_PORT_LISTEN_ATTEMPTS,
                self::PORT_LISTEN_INTERVAL_SECONDS
            )) {
                $this->stopProjectService($project);
                return $this->fail('포트 LISTEN 확인 실패: ' . $port);
            }
            $this->stdout[] = '[DEPLOY_SUCCESS_MARK] port=' . $port;
            $this->stdout[] = '[DONE] 프로젝트 서비스 시작 및 포트 확인 완료: port=' . $port;
            return true;
        }

        throw new \RuntimeException('지원하지 않는 runtime_type 입니다: ' . $runtime);
    }

    private function targetRefForProject(array $project, string $targetRef, ?array $version): string
    {
        if ($version !== null) {
            return $targetRef;
        }

        $branchName = trim((string) ($project['branch_name'] ?? 'main'));
        if ($branchName === '') {
            $branchName = 'main';
        }

        return 'origin/' . $branchName;
    }


    private function runNextjsBunSafeFlow(array $project, string $targetRef): bool
    {
        $path = (string) $project['server_path'];
        $port = (int) $project['port'];
        $projectId = (int) ($project['id'] ?? 0);
        $projectKey = $this->safeProjectKey((string) ($project['project_key'] ?? ('project-' . $projectId)));
        $startMode = 'systemd';
        $this->logProjectPortConfiguration($project, $startMode);
        $this->stdout[] = sprintf('프로젝트 배포 시작: id=%s name=%s runtime=nextjs_bun start_mode=%s path=%s port=%d',
            (string) ($project['id'] ?? ''),
            (string) ($project['project_name'] ?? $project['project_key'] ?? ''),
            $startMode,
            $path,
            $port
        );

        $worktreeCreated = false;
        $buildPath = null;
        $candidateNext = null;
        $rollbackNext = null;
        $candidateNodeModules = null;
        $rollbackNodeModules = null;
        $targetCommit = null;
        $previousCommit = null;
        $previousPids = [];

        try {
            $this->stdout[] = '[STEP] fetch repository';
            if (!$this->runCommand(['git', 'fetch', '--all'], $path)) {
                return $this->fail('git fetch --all 실패');
            }

            $this->stdout[] = '[STEP] resolve target commit';
            $targetCommit = $this->resolveGitCommit($path, $targetRef);
            if ($targetCommit === null) {
                return $this->fail('target commit 확정 실패: ' . $targetRef);
            }
            $previousCommit = $this->currentCommit($path);
            if ($previousCommit === null) {
                return $this->fail('기존 운영 commit 확인 실패');
            }
            $deployId = gmdate('Ymd_His') . '-' . $projectId . '-' . substr($targetCommit, 0, 12) . '-' . bin2hex(random_bytes(4));
            $buildPath = self::NEXTJS_BUILD_ROOT . '/' . $projectKey . '/' . $deployId;
            $candidateNext = rtrim($path, '/') . '/.next.candidate-' . $deployId;
            $rollbackNext = rtrim($path, '/') . '/.next.rollback-' . $deployId;
            $candidateNodeModules = rtrim($path, '/') . '/node_modules.candidate-' . $deployId;
            $rollbackNodeModules = rtrim($path, '/') . '/node_modules.rollback-' . $deployId;
            $this->stdout[] = '[INFO] previous_commit=' . $previousCommit . ' target_commit=' . $targetCommit . ' build_path=' . $buildPath;

            $this->stdout[] = '[STEP] preflight filesystem';
            if (!$this->ensureNextjsBuildFilesystem($path, dirname($buildPath))) {
                return false;
            }

            $this->stdout[] = '[STEP] create candidate worktree';
            if (!$this->runCommand(['git', 'worktree', 'prune'], $path)) {
                $this->stdout[] = '[WARN] git worktree prune failed; continuing with unique build path';
                $this->failureReason = null;
            }
            if (!$this->runCommand(['git', 'worktree', 'add', '--detach', $buildPath, $targetCommit], $path)) {
                $this->logSafeCandidateFailure('candidate worktree creation failed', $path, $port);
                return $this->fail('candidate worktree 생성 실패');
            }
            $worktreeCreated = true;

            $this->stdout[] = '[STEP] prepare candidate environment';
            if (!$this->prepareCandidateEnvironment($path, $buildPath)) {
                $this->logSafeCandidateFailure('candidate environment preparation failed', $path, $port);
                return $this->fail('candidate 환경 준비 실패');
            }

            $this->stdout[] = '[STEP] install candidate dependencies';
            if (!$this->installCandidateDependencies($buildPath)) {
                $this->logSafeCandidateFailure('candidate dependency install failed', $path, $port);
                return $this->fail('candidate 의존성 설치 실패');
            }

            $this->stdout[] = '[STEP] build candidate';
            if (!$this->runShellCommand($this->projectEnvCommand('bun run build', $buildPath), $buildPath)) {
                $this->stderr[] = '[FAIL] candidate build failed';
                $this->stdout[] = '[INFO] existing service remains running';
                $this->stdout[] = '[INFO] production directory was not modified';
                $this->logExistingServiceState($port);
                return $this->fail('candidate bun run build 실패');
            }

            $this->stdout[] = '[STEP] verify candidate artifacts';
            if (!$this->verifyNextjsCandidateArtifacts($buildPath)) {
                $this->logSafeCandidateFailure('candidate artifact verification failed', $path, $port);
                return $this->fail('candidate .next 산출물 검증 실패');
            }

            $this->stdout[] = '[STEP] prepare production switch';
            $previousPids = $this->portPids($port);
            $this->stdout[] = '[SWITCH_INFO] previous_commit=' . $previousCommit
                . ' target_commit=' . $targetCommit
                . ' previous_pids=' . ($previousPids === [] ? 'none' : implode(',', $previousPids))
                . ' port=' . $port
                . ' previous_next_exists=' . (is_dir(rtrim($path, '/') . '/.next') ? 'yes' : 'no')
                . ' rollback_next=' . $rollbackNext
                . ' started_at=' . $this->now();
            if (!$this->copyCandidateNext($buildPath, $candidateNext)) {
                return $this->fail('candidate .next 복사 실패');
            }
            if (!$this->copyCandidateNodeModules($buildPath, $candidateNodeModules)) {
                return $this->fail('candidate node_modules 복사 실패');
            }

            $this->stdout[] = '[STEP] stop existing service';
            if (!$this->stopProjectService($project)) {
                return $this->rollbackNextjsDeployment($project, $path, $port, $previousCommit, $rollbackNext, $candidateNext, $rollbackNodeModules, $candidateNodeModules, $previousPids, '기존 systemd 서비스 종료 실패: ' . $this->projectSystemdUnit($project));
            }

            $this->stdout[] = '[STEP] switch production commit';
            if (!$this->runCommand(['git', 'reset', '--hard', $targetCommit], $path)) {
                return $this->rollbackNextjsDeployment($project, $path, $port, $previousCommit, $rollbackNext, $candidateNext, $rollbackNodeModules, $candidateNodeModules, $previousPids, '운영 프로젝트 git reset 실패');
            }

            $this->stdout[] = '[STEP] install candidate build artifacts';
            if (!$this->installCandidateNext($path, $candidateNext, $rollbackNext)) {
                return $this->rollbackNextjsDeployment($project, $path, $port, $previousCommit, $rollbackNext, $candidateNext, $rollbackNodeModules, $candidateNodeModules, $previousPids, '.next 교체 실패');
            }
            if (!$this->installCandidateNodeModules($path, $candidateNodeModules, $rollbackNodeModules)) {
                return $this->rollbackNextjsDeployment($project, $path, $port, $previousCommit, $rollbackNext, $candidateNext, $rollbackNodeModules, $candidateNodeModules, $previousPids, 'node_modules 교체 실패');
            }

            $this->stdout[] = '[STEP] start new service';
            if (!$this->startNextjsService($project)) {
                return $this->rollbackNextjsDeployment($project, $path, $port, $previousCommit, $rollbackNext, $candidateNext, $rollbackNodeModules, $candidateNodeModules, $previousPids, '신규 systemd 서비스 시작 실패');
            }

            $this->stdout[] = '[STEP] verify port listener';
            if (!$this->waitForProjectPortListening($port, self::NEXTJS_BUN_PORT_LISTEN_ATTEMPTS, self::PORT_LISTEN_INTERVAL_SECONDS, $previousPids)) {
                return $this->rollbackNextjsDeployment($project, $path, $port, $previousCommit, $rollbackNext, $candidateNext, $rollbackNodeModules, $candidateNodeModules, $previousPids, '포트 LISTEN 확인 실패');
            }

            $this->stdout[] = '[STEP] verify HTTP response';
            if (!$this->waitForHttpResponse($port, self::DEFAULT_PORT_LISTEN_ATTEMPTS, self::PORT_LISTEN_INTERVAL_SECONDS)) {
                return $this->rollbackNextjsDeployment($project, $path, $port, $previousCommit, $rollbackNext, $candidateNext, $rollbackNodeModules, $candidateNodeModules, $previousPids, 'HTTP 응답 확인 실패');
            }
            if ($this->projectJournalHasAddressInUse($project)) {
                return $this->rollbackNextjsDeployment($project, $path, $port, $previousCommit, $rollbackNext, $candidateNext, $rollbackNodeModules, $candidateNodeModules, $previousPids, 'journal EADDRINUSE 확인');
            }

            $this->cleanupOldNextjsRollbacks($path);
            $this->stdout[] = '[DEPLOY_SUCCESS_MARK] runtime=nextjs_bun start_mode=systemd port=' . $port . ' target_commit=' . $targetCommit;
            $this->stdout[] = '[DONE] 프로젝트 서비스 시작 및 포트 확인 완료: runtime=nextjs_bun start_mode=systemd port=' . $port;
            return true;
        } finally {
            $this->stdout[] = '[STEP] cleanup candidate worktree';
            if ($worktreeCreated && $buildPath !== null) {
                $this->runCommand(['git', 'worktree', 'remove', '--force', $buildPath], $path);
                $this->runCommand(['git', 'worktree', 'prune'], $path);
            } elseif ($buildPath !== null && is_dir($buildPath)) {
                $this->runShellCommand('rm -rf ' . escapeshellarg($buildPath), null);
            }
            if ($candidateNext !== null && is_dir($candidateNext)) {
                $this->runShellCommand('rm -rf ' . escapeshellarg($candidateNext), null);
            }
            if ($candidateNodeModules !== null && is_dir($candidateNodeModules)) {
                $this->runShellCommand('rm -rf ' . escapeshellarg($candidateNodeModules), null);
            }
            $this->cleanupOldBuildDirectories(self::NEXTJS_BUILD_ROOT . '/' . $projectKey);
        }
    }


    private function runNodeAppSafeFlow(array $project, string $targetRef): bool
    {
        $path = (string) $project['server_path'];
        $port = (int) $project['port'];
        $projectId = (int) ($project['id'] ?? 0);
        $projectKey = $this->safeProjectKey((string) ($project['project_key'] ?? ('project-' . $projectId)));
        $startMode = 'systemd';
        $this->logProjectPortConfiguration($project, $startMode);
        $this->stdout[] = sprintf('프로젝트 배포 시작: id=%s name=%s runtime=node_app start_mode=%s path=%s port=%d',
            (string) ($project['id'] ?? ''),
            (string) ($project['project_name'] ?? $project['project_key'] ?? ''),
            $startMode,
            $path,
            $port
        );

        $worktreeCreated = false;
        $buildPath = null;
        $targetCommit = null;
        $previousCommit = null;
        $previousPids = [];
        $candidateNodeModules = null;
        $rollbackNodeModules = null;
        $candidateArtifacts = [];
        $rollbackArtifacts = [];

        try {
            $this->stdout[] = '[STEP] fetch repository';
            if (!$this->runCommand(['git', 'fetch', '--all'], $path)) {
                return $this->fail('git fetch --all 실패');
            }

            $this->stdout[] = '[STEP] resolve target commit';
            $targetCommit = $this->resolveGitCommit($path, $targetRef);
            if ($targetCommit === null) {
                return $this->fail('target commit 확정 실패: ' . $targetRef);
            }
            $previousCommit = $this->currentCommit($path);
            if ($previousCommit === null) {
                return $this->fail('기존 운영 commit 확인 실패');
            }

            $deployId = gmdate('Ymd_His') . '-' . $projectId . '-' . substr($targetCommit, 0, 12) . '-' . bin2hex(random_bytes(4));
            $buildPath = self::NODE_APP_BUILD_ROOT . '/' . $projectKey . '/' . $deployId;
            $candidateNodeModules = rtrim($path, '/') . '/node_modules.candidate-' . $deployId;
            $rollbackNodeModules = rtrim($path, '/') . '/node_modules.rollback-' . $deployId;
            $this->stdout[] = '[INFO] previous_commit=' . $previousCommit . ' target_commit=' . $targetCommit . ' build_path=' . $buildPath;

            $this->stdout[] = '[STEP] preflight filesystem';
            if (!$this->ensureNextjsBuildFilesystem($path, dirname($buildPath))) {
                return false;
            }

            $this->stdout[] = '[STEP] create candidate worktree';
            if (!$this->runCommand(['git', 'worktree', 'prune'], $path)) {
                $this->stdout[] = '[WARN] git worktree prune failed; continuing with unique build path';
                $this->failureReason = null;
            }
            if (!$this->runCommand(['git', 'worktree', 'add', '--detach', $buildPath, $targetCommit], $path)) {
                $this->logNodeCandidateFailure('candidate worktree creation failed', $port);
                return $this->fail('node_app candidate worktree 생성 실패');
            }
            $worktreeCreated = true;

            $this->stdout[] = '[STEP] prepare candidate environment';
            if (!$this->prepareCandidateEnvironment($path, $buildPath)) {
                $this->logNodeCandidateFailure('candidate environment preparation failed', $port);
                return $this->fail('node_app candidate 환경 준비 실패');
            }

            $packageManager = $this->nodePackageManager($buildPath);
            $this->stdout[] = '[NODE_APP] package_manager=' . $packageManager;

            $this->stdout[] = '[STEP] install candidate dependencies';
            if (!$this->installNodeAppDependencies($buildPath, $packageManager)) {
                $this->logNodeCandidateFailure('candidate dependency install failed', $port);
                return $this->fail('node_app candidate 의존성 설치 실패');
            }

            if ($this->packageScriptExists($buildPath, 'build')) {
                $this->stdout[] = '[STEP] build candidate';
                if (!$this->runShellCommand($this->projectEnvCommand($this->nodeRunScriptCommand($packageManager, 'build'), $buildPath), $buildPath)) {
                    $this->logNodeCandidateFailure('candidate build failed', $port);
                    return $this->fail('node_app candidate build 실패');
                }
            } else {
                $this->stdout[] = '[STEP] build candidate skipped: package.json scripts.build 없음';
            }

            $this->stdout[] = '[STEP] verify candidate start command';
            if (!$this->nodeStartCommand($buildPath, $port, $packageManager)) {
                $this->logNodeCandidateFailure('candidate start command missing', $port);
                return $this->fail('node_app 시작 명령을 찾을 수 없습니다. package.json scripts.start 또는 server.js/app.js/index.js가 필요합니다.');
            }

            $this->stdout[] = '[STEP] prepare production switch';
            $previousPids = $this->portPids($port);
            if (!$this->copyCandidateNodeModules($buildPath, $candidateNodeModules)) {
                return $this->fail('candidate node_modules 복사 실패');
            }
            $candidateArtifacts = $this->prepareNodeCandidateArtifacts($path, $buildPath, $deployId);

            $this->stdout[] = '[STEP] stop existing service';
            if (!$this->stopProjectService($project)) {
                return $this->rollbackNodeAppDeployment($project, $path, $port, $previousCommit, $rollbackNodeModules, $candidateNodeModules, $candidateArtifacts, $rollbackArtifacts, $previousPids, '기존 systemd 서비스 종료 실패: ' . $this->projectSystemdUnit($project));
            }

            $this->stdout[] = '[STEP] switch production commit';
            if (!$this->runCommand(['git', 'reset', '--hard', $targetCommit], $path)) {
                return $this->rollbackNodeAppDeployment($project, $path, $port, $previousCommit, $rollbackNodeModules, $candidateNodeModules, $candidateArtifacts, $rollbackArtifacts, $previousPids, '운영 프로젝트 git reset 실패');
            }

            $this->stdout[] = '[STEP] install candidate node_modules and artifacts';
            if (!$this->installCandidateNodeModules($path, $candidateNodeModules, $rollbackNodeModules)) {
                return $this->rollbackNodeAppDeployment($project, $path, $port, $previousCommit, $rollbackNodeModules, $candidateNodeModules, $candidateArtifacts, $rollbackArtifacts, $previousPids, 'node_modules 교체 실패');
            }
            if (!$this->installNodeCandidateArtifacts($path, $candidateArtifacts, $rollbackArtifacts)) {
                return $this->rollbackNodeAppDeployment($project, $path, $port, $previousCommit, $rollbackNodeModules, $candidateNodeModules, $candidateArtifacts, $rollbackArtifacts, $previousPids, '빌드 산출물 교체 실패');
            }

            $this->stdout[] = '[STEP] start new service';
            if (!$this->startNodeAppService($project)) {
                return $this->rollbackNodeAppDeployment($project, $path, $port, $previousCommit, $rollbackNodeModules, $candidateNodeModules, $candidateArtifacts, $rollbackArtifacts, $previousPids, '신규 node_app systemd 서비스 시작 실패');
            }
            if (!$this->waitForProjectPortListening($port, self::NEXTJS_BUN_PORT_LISTEN_ATTEMPTS, self::PORT_LISTEN_INTERVAL_SECONDS, $previousPids)) {
                return $this->rollbackNodeAppDeployment($project, $path, $port, $previousCommit, $rollbackNodeModules, $candidateNodeModules, $candidateArtifacts, $rollbackArtifacts, $previousPids, '포트 LISTEN 확인 실패');
            }
            if (!$this->waitForHttpResponse($port, self::DEFAULT_PORT_LISTEN_ATTEMPTS, self::PORT_LISTEN_INTERVAL_SECONDS)) {
                return $this->rollbackNodeAppDeployment($project, $path, $port, $previousCommit, $rollbackNodeModules, $candidateNodeModules, $candidateArtifacts, $rollbackArtifacts, $previousPids, 'HTTP 응답 확인 실패');
            }
            if ($this->projectJournalHasAddressInUse($project)) {
                return $this->rollbackNodeAppDeployment($project, $path, $port, $previousCommit, $rollbackNodeModules, $candidateNodeModules, $candidateArtifacts, $rollbackArtifacts, $previousPids, 'journal EADDRINUSE 확인');
            }

            $this->cleanupOldNodeAppRollbacks($path);
            $this->stdout[] = '[DEPLOY_SUCCESS_MARK] runtime=node_app start_mode=systemd port=' . $port . ' target_commit=' . $targetCommit;
            $this->stdout[] = '[DONE] 프로젝트 서비스 시작 및 포트/HTTP 확인 완료: runtime=node_app start_mode=systemd port=' . $port;
            return true;
        } finally {
            $this->stdout[] = '[STEP] cleanup node_app candidate worktree';
            if ($worktreeCreated && $buildPath !== null) {
                $this->runCommand(['git', 'worktree', 'remove', '--force', $buildPath], $path);
                $this->runCommand(['git', 'worktree', 'prune'], $path);
            } elseif ($buildPath !== null && is_dir($buildPath)) {
                $this->runShellCommand('rm -rf ' . escapeshellarg($buildPath), null);
            }
            if ($candidateNodeModules !== null && is_dir($candidateNodeModules)) {
                $this->runShellCommand('rm -rf ' . escapeshellarg($candidateNodeModules), null);
            }
            foreach ($candidateArtifacts as $candidatePath) {
                if (is_dir($candidatePath) || is_file($candidatePath) || is_link($candidatePath)) {
                    $this->runShellCommand('rm -rf ' . escapeshellarg($candidatePath), null);
                }
            }
            $this->cleanupOldBuildDirectories(self::NODE_APP_BUILD_ROOT . '/' . $projectKey);
        }
    }

    private function resolveGitCommit(string $path, string $targetRef): ?string
    {
        $output = [];
        $code = 0;
        exec('cd ' . escapeshellarg($path) . ' && git rev-parse --verify ' . escapeshellarg($targetRef . '^{commit}') . ' 2>/dev/null', $output, $code);
        if ($code !== 0 || trim((string) ($output[0] ?? '')) === '') {
            return null;
        }

        return trim((string) $output[0]);
    }

    private function safeProjectKey(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $key) ?: 'project';
        return trim($safe, '._-') !== '' ? trim($safe, '._-') : 'project';
    }

    private function ensureNextjsBuildFilesystem(string $productionPath, string $buildParent): bool
    {
        if (!is_dir($productionPath) || !is_writable($productionPath)) {
            return $this->fail('운영 프로젝트 경로에 쓸 수 없습니다: ' . $productionPath);
        }
        if (!is_dir($buildParent) && !mkdir($buildParent, 0755, true) && !is_dir($buildParent)) {
            return $this->fail('임시 빌드 경로를 생성할 수 없습니다: ' . $buildParent);
        }
        if (!is_writable($buildParent)) {
            return $this->fail('임시 빌드 경로에 쓸 수 없습니다: ' . $buildParent);
        }
        $free = @disk_free_space($productionPath);
        if ($free !== false && $free < self::MIN_FREE_BYTES_FOR_NEXTJS_BUILD) {
            return $this->fail('디스크 여유 공간이 부족합니다: free_bytes=' . (int) $free);
        }
        $this->stdout[] = '[DISK_CHECK] production_path=' . $productionPath . ' free_bytes=' . ($free === false ? 'unknown' : (string) (int) $free);
        return true;
    }

    private function prepareCandidateEnvironment(string $productionPath, string $buildPath): bool
    {
        foreach ($this->candidateEnvFiles($productionPath) as $file) {
            $target = rtrim($buildPath, '/') . '/' . basename($file);
            if (file_exists($target) || is_link($target)) {
                continue;
            }
            if (!symlink($file, $target)) {
                return false;
            }
            $this->stdout[] = '[ENV_LINK] source=' . $file . ' target=' . $target;
        }
        return true;
    }

    /** @return array<int,string> */
    private function candidateEnvFiles(string $productionPath): array
    {
        $names = ['.env', '.env.local', '.env.production', '.env.production.local'];
        $files = [];
        foreach ($names as $name) {
            $file = rtrim($productionPath, '/') . '/' . $name;
            if ((is_file($file) || is_link($file)) && is_readable($file)) {
                $files[] = $file;
            }
        }
        return $files;
    }

    private function installCandidateDependencies(string $buildPath): bool
    {
        if (!is_file(rtrim($buildPath, '/') . '/package.json')) {
            return $this->fail('package.json이 없어 nextjs_bun 의존성을 설치할 수 없습니다: ' . $buildPath);
        }

        $hasLock = is_file(rtrim($buildPath, '/') . '/bun.lock') || is_file(rtrim($buildPath, '/') . '/bun.lockb');
        if (!$hasLock) {
            $this->stdout[] = '[DEPENDENCY_INSTALL] lockfile=none command="bun install"';
            return $this->runShellCommand('bun install', $buildPath);
        }

        $this->stdout[] = '[DEPENDENCY_INSTALL] lockfile=present command="bun install --frozen-lockfile"';
        $previousFailureReason = $this->failureReason;
        if ($this->runShellCommand('bun install --frozen-lockfile', $buildPath)) {
            return true;
        }

        $this->stdout[] = '[DEPENDENCY_INSTALL_FALLBACK] frozen lockfile install failed in candidate worktree; retrying candidate-only bun install without modifying production';
        $this->failureReason = $previousFailureReason;
        return $this->runShellCommand('bun install', $buildPath);
    }

    private function verifyNextjsCandidateArtifacts(string $buildPath): bool
    {
        $root = rtrim($buildPath, '/');
        if (!is_file($root . '/package.json')) {
            $this->stderr[] = '[ARTIFACT_FAIL] missing package.json';
            return false;
        }
        if (!is_dir($root . '/.next')) {
            $this->stderr[] = '[ARTIFACT_FAIL] missing .next directory';
            return false;
        }
        $manifestCandidates = [
            $root . '/.next/routes-manifest.json',
            $root . '/.next/build-manifest.json',
            $root . '/.next/prerender-manifest.json',
            $root . '/.next/server/middleware-manifest.json',
        ];
        foreach ($manifestCandidates as $manifest) {
            if (is_file($manifest)) {
                $this->stdout[] = '[ARTIFACT_OK] manifest=' . $manifest;
                return true;
            }
        }
        $this->stderr[] = '[ARTIFACT_FAIL] no known Next.js manifest found under .next';
        return false;
    }

    private function copyCandidateNext(string $buildPath, string $candidateNext): bool
    {
        if (is_dir($candidateNext) && !$this->runShellCommand('rm -rf ' . escapeshellarg($candidateNext), null)) {
            return false;
        }
        if (!$this->runShellCommand('cp -a ' . escapeshellarg(rtrim($buildPath, '/') . '/.next') . ' ' . escapeshellarg($candidateNext), null)) {
            return false;
        }
        return is_dir($candidateNext);
    }

    private function copyCandidateNodeModules(string $buildPath, ?string $candidateNodeModules): bool
    {
        if ($candidateNodeModules === null) {
            return true;
        }
        $source = rtrim($buildPath, '/') . '/node_modules';
        if (!is_dir($source)) {
            $this->stdout[] = '[NODE_MODULES] candidate node_modules not found; keeping production node_modules';
            return true;
        }
        if (is_dir($candidateNodeModules) && !$this->runShellCommand('rm -rf ' . escapeshellarg($candidateNodeModules), null)) {
            return false;
        }
        $this->stdout[] = '[NODE_MODULES] copy candidate node_modules to production filesystem before service stop';
        return $this->runShellCommand('cp -a ' . escapeshellarg($source) . ' ' . escapeshellarg($candidateNodeModules), null)
            && is_dir($candidateNodeModules);
    }

    private function installCandidateNext(string $path, string $candidateNext, string $rollbackNext): bool
    {
        $currentNext = rtrim($path, '/') . '/.next';
        if (file_exists($rollbackNext) || is_link($rollbackNext)) {
            return $this->fail('rollback .next 경로가 이미 존재합니다: ' . $rollbackNext);
        }
        if (!is_dir($candidateNext)) {
            return $this->fail('candidate .next 경로가 없습니다: ' . $candidateNext);
        }
        if (is_dir($currentNext) || is_link($currentNext)) {
            if (!rename($currentNext, $rollbackNext)) {
                return $this->fail('기존 .next rollback rename 실패');
            }
            $this->stdout[] = '[SWITCH_NEXT] current .next renamed to ' . $rollbackNext;
        }
        if (!rename($candidateNext, $currentNext)) {
            if ((is_dir($rollbackNext) || is_link($rollbackNext)) && !file_exists($currentNext)) {
                @rename($rollbackNext, $currentNext);
            }
            return $this->fail('candidate .next 활성화 rename 실패');
        }
        $this->stdout[] = '[SWITCH_NEXT] candidate .next activated';
        return true;
    }

    private function installCandidateNodeModules(string $path, ?string $candidateNodeModules, ?string $rollbackNodeModules): bool
    {
        if ($candidateNodeModules === null || !is_dir($candidateNodeModules)) {
            $this->stdout[] = '[NODE_MODULES] no candidate node_modules switch needed';
            return true;
        }
        if ($rollbackNodeModules === null) {
            return $this->fail('node_modules rollback 경로가 없습니다.');
        }

        $currentNodeModules = rtrim($path, '/') . '/node_modules';
        if (file_exists($rollbackNodeModules) || is_link($rollbackNodeModules)) {
            return $this->fail('rollback node_modules 경로가 이미 존재합니다: ' . $rollbackNodeModules);
        }
        if (is_dir($currentNodeModules) || is_link($currentNodeModules)) {
            if (!rename($currentNodeModules, $rollbackNodeModules)) {
                return $this->fail('기존 node_modules rollback rename 실패');
            }
            $this->stdout[] = '[NODE_MODULES] current node_modules renamed to ' . $rollbackNodeModules;
        }
        if (!rename($candidateNodeModules, $currentNodeModules)) {
            if ((is_dir($rollbackNodeModules) || is_link($rollbackNodeModules)) && !file_exists($currentNodeModules)) {
                @rename($rollbackNodeModules, $currentNodeModules);
            }
            return $this->fail('candidate node_modules 활성화 rename 실패');
        }
        $this->stdout[] = '[NODE_MODULES] candidate node_modules activated';
        return true;
    }

    private function startNextjsService(array $project): bool
    {
        $port = (int) $project['port'];
        return $this->startProjectService($project, 'PORT=' . escapeshellarg((string) $port) . ' bun run start -H 0.0.0.0');
    }

    private function rollbackNextjsDeployment(array $project, string $path, int $port, string $previousCommit, ?string $rollbackNext, ?string $candidateNext, ?string $rollbackNodeModules, ?string $candidateNodeModules, array $previousPids, string $reason): bool
    {
        $this->stderr[] = '[ROLLBACK] reason=' . $reason;
        $rollbackOk = true;
        $this->stopProjectService($project);
        if ($rollbackNext !== null && (is_dir($rollbackNext) || is_link($rollbackNext))) {
            $this->stdout[] = '[ROLLBACK] restore previous .next';
            $currentNext = rtrim($path, '/') . '/.next';
            if ((is_dir($currentNext) || is_link($currentNext)) && !$this->runShellCommand('rm -rf ' . escapeshellarg($currentNext), null)) {
                $rollbackOk = false;
            }
            if (!file_exists($currentNext) && !@rename($rollbackNext, $currentNext)) {
                $rollbackOk = false;
            }
        }
        if ($candidateNext !== null && is_dir($candidateNext)) {
            $this->runShellCommand('rm -rf ' . escapeshellarg($candidateNext), null);
        }
        if ($rollbackNodeModules !== null && (is_dir($rollbackNodeModules) || is_link($rollbackNodeModules))) {
            $this->stdout[] = '[ROLLBACK] restore previous node_modules';
            $currentNodeModules = rtrim($path, '/') . '/node_modules';
            if ((is_dir($currentNodeModules) || is_link($currentNodeModules)) && !$this->runShellCommand('rm -rf ' . escapeshellarg($currentNodeModules), null)) {
                $rollbackOk = false;
            }
            if (!file_exists($currentNodeModules) && !@rename($rollbackNodeModules, $currentNodeModules)) {
                $rollbackOk = false;
            }
        }
        if ($candidateNodeModules !== null && is_dir($candidateNodeModules)) {
            $this->runShellCommand('rm -rf ' . escapeshellarg($candidateNodeModules), null);
        }
        $this->stdout[] = '[ROLLBACK] restore previous commit';
        if (!$this->runCommand(['git', 'reset', '--hard', $previousCommit], $path)) {
            $rollbackOk = false;
        }
        $this->stdout[] = '[ROLLBACK] restart previous service';
        if (!$this->startNextjsService($project)
            || !$this->waitForProjectPortListening($port, self::NEXTJS_BUN_PORT_LISTEN_ATTEMPTS, self::PORT_LISTEN_INTERVAL_SECONDS)
            || !$this->waitForHttpResponse($port, self::DEFAULT_PORT_LISTEN_ATTEMPTS, self::PORT_LISTEN_INTERVAL_SECONDS)) {
            $rollbackOk = false;
        }
        if ($rollbackOk) {
            $this->stdout[] = '[ROLLBACK SUCCESS] previous service healthy';
            return $this->fail($reason . ' (rollback 성공)');
        }
        $this->stderr[] = '[CRITICAL] deployment failed and rollback failed';
        return $this->fail($reason . ' (rollback 실패)');
    }

    private function logSafeCandidateFailure(string $reason, string $path, int $port): void
    {
        $this->stderr[] = '[FAIL] ' . $reason;
        $this->stdout[] = '[SAFE] existing production service was not stopped';
        $this->stdout[] = '[SAFE] existing production .next was not modified';
        $this->stdout[] = '[SAFE] production directory was not modified';
        $this->logExistingServiceState($port);
    }

    private function logExistingServiceState(int $port): void
    {
        $details = $this->portReadinessDetails($port);
        $this->stdout[] = '[INFO] existing service listen=' . ((bool) $details['ready'] ? 'yes' : 'no')
            . ' pid=' . ($details['pid'] ?? 'unknown')
            . ' detail=' . (string) $details['detail'];
    }

    private function cleanupOldNextjsRollbacks(string $path): void
    {
        $this->runShellCommand('find ' . escapeshellarg($path) . ' -maxdepth 1 -type d \( -name ' . escapeshellarg('.next.rollback-*') . ' -o -name ' . escapeshellarg('node_modules.rollback-*') . ' \) -mmin +1440 -exec rm -rf {} + 2>/dev/null || true', null);
    }

    private function cleanupOldBuildDirectories(string $projectBuildRoot): void
    {
        if (!is_dir($projectBuildRoot)) {
            return;
        }
        $this->runShellCommand('find ' . escapeshellarg($projectBuildRoot) . ' -mindepth 1 -maxdepth 1 -type d -mmin +1440 -exec rm -rf {} + 2>/dev/null || true', null);
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


    private function startProjectService(array $project, string $startCommand): bool
    {
        $instance = $this->projectSystemdInstance($project);
        $unit = $this->projectSystemdUnit($project);
        $env = $this->projectSystemdEnvironment($project, $startCommand);

        $this->stdout[] = '[SYSTEMD_ENV_WRITE] unit=' . $unit . ' instance=' . $instance;
        if (!$this->writeProjectSystemdEnvironment($instance, $env)) {
            return false;
        }

        $this->stdout[] = '[SYSTEMD_RESTART] at=' . $this->now()
            . ' unit=' . $unit
            . ' cwd=' . (string) $project['server_path']
            . ' expected_port=' . (int) $project['port']
            . ' journal="journalctl -u ' . $unit . '"'
            . ' command=' . $this->sanitizeCommandForLog($startCommand);

        if (!$this->projectSystemdControl('restart', $instance)) {
            return false;
        }

        return $this->projectSystemdControl('is-active', $instance);
    }

    private function stopProjectService(array $project): bool
    {
        $instance = $this->projectSystemdInstance($project);
        $unit = $this->projectSystemdUnit($project);
        $this->stdout[] = '[SYSTEMD_STOP] unit=' . $unit;

        $this->projectSystemdControl('stop', $instance, false);
        $this->projectSystemdControl('reset-failed', $instance, false);

        return true;
    }

    private function writeProjectSystemdEnvironment(string $instance, string $env): bool
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'auto-deploy-project-env-');
        if ($tempFile === false) {
            $this->stderr[] = '[SYSTEMD_ENV_FAILED] reason=tempnam_failed';
            return false;
        }

        try {
            if (file_put_contents($tempFile, $env) === false) {
                $this->stderr[] = '[SYSTEMD_ENV_FAILED] reason=temp_write_failed file=' . $tempFile;
                return false;
            }

            return $this->runShellCommand(
                'sudo -n ' . escapeshellarg(self::PROJECT_SYSTEMD_CONTROL)
                . ' write-env ' . escapeshellarg($instance)
                . ' < ' . escapeshellarg($tempFile),
                null
            );
        } finally {
            @unlink($tempFile);
        }
    }

    private function projectSystemdControl(string $action, string $instance, bool $strict = true): bool
    {
        $command = 'sudo -n ' . escapeshellarg(self::PROJECT_SYSTEMD_CONTROL)
            . ' ' . escapeshellarg($action)
            . ' ' . escapeshellarg($instance);
        $ok = $this->runShellCommand($command, null);
        if (!$ok && !$strict) {
            $this->failureReason = null;
            return true;
        }

        return $ok;
    }

    private function projectJournalHasAddressInUse(array $project): bool
    {
        $instance = $this->projectSystemdInstance($project);
        $unit = $this->projectSystemdUnit($project);
        $command = 'sudo -n ' . escapeshellarg(self::PROJECT_SYSTEMD_CONTROL)
            . ' journal ' . escapeshellarg($instance)
            . ' --since ' . escapeshellarg('-2 minutes')
            . ' --no-pager -n 80 2>/dev/null';
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        $content = implode("\n", $output);
        $hasError = str_contains($content, 'EADDRINUSE') || str_contains(strtolower($content), 'address already in use');
        $this->stdout[] = '[JOURNAL_CHECK] unit=' . $unit
            . ' exit_code=' . $code
            . ' eaddrinuse=' . ($hasError ? 'yes' : 'no');

        return $hasError;
    }

    private function projectSystemdEnvironment(array $project, string $startCommand): string
    {
        $path = (string) $project['server_path'];
        $port = (string) ((int) $project['port']);
        $runtime = (string) $project['runtime_type'];
        $projectKey = (string) ($project['project_key'] ?? ('project-' . (int) ($project['id'] ?? 0)));

        return implode("\n", [
            'PROJECT_KEY=' . $this->systemdEnvValue($projectKey),
            'PROJECT_RUNTIME=' . $this->systemdEnvValue($runtime),
            'PROJECT_PATH=' . $this->systemdEnvValue($path),
            'PROJECT_PORT=' . $this->systemdEnvValue($port),
            'PROJECT_HOST=' . $this->systemdEnvValue('0.0.0.0'),
            'PROJECT_HOSTNAME=' . $this->systemdEnvValue('0.0.0.0'),
            'PROJECT_START_COMMAND=' . $this->systemdEnvValue($startCommand),
            '',
        ]);
    }

    private function systemdEnvValue(string $value): string
    {
        return '"' . str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], $value) . '"';
    }

    private function projectSystemdUnit(array $project): string
    {
        return self::PROJECT_SYSTEMD_UNIT_PREFIX . $this->projectSystemdInstance($project) . '.service';
    }

    private function projectSystemdInstance(array $project): string
    {
        $id = (int) ($project['id'] ?? 0);
        $key = $this->safeProjectKey((string) ($project['project_key'] ?? ('project-' . $id)));
        return $id . '-' . $key;
    }

    /**
     * @return array<int,string>
     */
    private function portPids(int $port): array
    {
        if ($port < 1 || $port > 65535) {
            return [];
        }

        $pids = [];
        $lsofOutput = [];
        exec('lsof -nP -iTCP:' . $port . ' -sTCP:LISTEN -t 2>/dev/null', $lsofOutput);
        $pids = array_merge($pids, $this->numericPids($lsofOutput));

        return array_values(array_unique($this->numericPids($pids)));
    }

    /**
     * @param array<int,string> $values
     * @return array<int,string>
     */
    private function numericPids(array $values): array
    {
        return array_values(array_filter(array_map('trim', $values), function (string $pid): bool {
            return $this->isNumericPid($pid);
        }));
    }

    private function isNumericPid(mixed $pid): bool
    {
        return is_string($pid) && preg_match('/^\d+$/', $pid) === 1;
    }

    private function nodePackageManager(string $path): string
    {
        $root = rtrim($path, '/');
        if (is_file($root . '/bun.lock') || is_file($root . '/bun.lockb')) {
            return 'bun';
        }
        if (is_file($root . '/pnpm-lock.yaml')) {
            return 'pnpm';
        }
        if (is_file($root . '/yarn.lock')) {
            return 'yarn';
        }
        return 'npm';
    }

    private function installNodeAppDependencies(string $buildPath, string $packageManager): bool
    {
        if (!is_file(rtrim($buildPath, '/') . '/package.json')) {
            return $this->fail('package.json이 없어 node_app 의존성을 설치할 수 없습니다: ' . $buildPath);
        }

        $command = match ($packageManager) {
            'bun' => (is_file(rtrim($buildPath, '/') . '/bun.lock') || is_file(rtrim($buildPath, '/') . '/bun.lockb')) ? 'bun install --frozen-lockfile' : 'bun install',
            'pnpm' => 'pnpm install --frozen-lockfile',
            'yarn' => 'yarn install --frozen-lockfile',
            default => is_file(rtrim($buildPath, '/') . '/package-lock.json') ? 'npm ci' : 'npm install',
        };

        $this->stdout[] = '[NODE_APP_DEPENDENCY_INSTALL] command=' . $command;
        return $this->runShellCommand($this->projectEnvCommand($command, $buildPath), $buildPath);
    }

    private function packageScriptExists(string $path, string $script): bool
    {
        $package = $this->readPackageJson($path);
        return isset($package['scripts']) && is_array($package['scripts']) && isset($package['scripts'][$script]) && trim((string) $package['scripts'][$script]) !== '';
    }

    private function nodeRunScriptCommand(string $packageManager, string $script): string
    {
        return match ($packageManager) {
            'bun' => 'bun run ' . escapeshellarg($script),
            'pnpm' => 'pnpm run ' . escapeshellarg($script),
            'yarn' => 'yarn run ' . escapeshellarg($script),
            default => 'npm run ' . escapeshellarg($script),
        };
    }

    private function nodeStartCommand(string $path, int $port, ?string $packageManager = null): ?string
    {
        $packageManager = $packageManager ?? $this->nodePackageManager($path);
        $prefix = 'PORT=' . escapeshellarg((string) $port) . ' HOST=0.0.0.0 HOSTNAME=0.0.0.0 ';
        if ($this->packageScriptExists($path, 'start')) {
            return $prefix . $this->nodeRunScriptCommand($packageManager, 'start');
        }

        foreach (['server.js', 'app.js', 'index.js'] as $entrypoint) {
            if (is_file(rtrim($path, '/') . '/' . $entrypoint)) {
                return $prefix . 'node ' . escapeshellarg($entrypoint);
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function readPackageJson(string $path): array
    {
        $file = rtrim($path, '/') . '/package.json';
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,string>
     */
    private function prepareNodeCandidateArtifacts(string $path, string $buildPath, string $deployId): array
    {
        $artifacts = [];
        foreach (['dist', 'build', 'out'] as $name) {
            $source = rtrim($buildPath, '/') . '/' . $name;
            if (!is_dir($source)) {
                continue;
            }
            $candidate = rtrim($path, '/') . '/' . $name . '.candidate-' . $deployId;
            if (is_dir($candidate) && !$this->runShellCommand('rm -rf ' . escapeshellarg($candidate), null)) {
                continue;
            }
            if ($this->runShellCommand('cp -a ' . escapeshellarg($source) . ' ' . escapeshellarg($candidate), null) && is_dir($candidate)) {
                $artifacts[$name] = $candidate;
                $this->stdout[] = '[NODE_APP_ARTIFACT] prepared name=' . $name . ' candidate=' . $candidate;
            }
        }
        return $artifacts;
    }

    /**
     * @param array<string,string> $candidateArtifacts
     * @param array<string,string> $rollbackArtifacts
     */
    private function installNodeCandidateArtifacts(string $path, array $candidateArtifacts, array &$rollbackArtifacts): bool
    {
        foreach ($candidateArtifacts as $name => $candidate) {
            $current = rtrim($path, '/') . '/' . $name;
            $rollback = rtrim($path, '/') . '/' . $name . '.rollback-' . basename($candidate);
            if (file_exists($rollback) || is_link($rollback)) {
                return $this->fail('rollback 산출물 경로가 이미 존재합니다: ' . $rollback);
            }
            if (is_dir($current) || is_link($current)) {
                if (!rename($current, $rollback)) {
                    return $this->fail('기존 산출물 rollback rename 실패: ' . $name);
                }
                $rollbackArtifacts[$name] = $rollback;
                $this->stdout[] = '[NODE_APP_ARTIFACT] current renamed name=' . $name . ' rollback=' . $rollback;
            }
            if (!rename($candidate, $current)) {
                if (isset($rollbackArtifacts[$name]) && !file_exists($current)) {
                    @rename($rollbackArtifacts[$name], $current);
                    unset($rollbackArtifacts[$name]);
                }
                return $this->fail('candidate 산출물 활성화 rename 실패: ' . $name);
            }
            $this->stdout[] = '[NODE_APP_ARTIFACT] candidate activated name=' . $name;
        }
        return true;
    }

    private function startNodeAppService(array $project): bool
    {
        $path = (string) $project['server_path'];
        $port = (int) $project['port'];
        $command = $this->nodeStartCommand($path, $port);
        return $command !== null && $this->startProjectService($project, $command);
    }

    /**
     * @param array<string,string> $candidateArtifacts
     * @param array<string,string> $rollbackArtifacts
     * @param array<int,string> $previousPids
     */
    private function rollbackNodeAppDeployment(array $project, string $path, int $port, string $previousCommit, ?string $rollbackNodeModules, ?string $candidateNodeModules, array $candidateArtifacts, array $rollbackArtifacts, array $previousPids, string $reason): bool
    {
        $this->stderr[] = '[ROLLBACK] reason=' . $reason;
        $rollbackOk = true;
        $this->stopProjectService($project);
        foreach ($candidateArtifacts as $name => $candidate) {
            $current = rtrim($path, '/') . '/' . $name;
            if ((is_dir($current) || is_link($current)) && !$this->runShellCommand('rm -rf ' . escapeshellarg($current), null)) {
                $rollbackOk = false;
            }
            if (isset($rollbackArtifacts[$name]) && (is_dir($rollbackArtifacts[$name]) || is_link($rollbackArtifacts[$name])) && !@rename($rollbackArtifacts[$name], $current)) {
                $rollbackOk = false;
            }
            if (is_dir($candidate) || is_link($candidate)) {
                $this->runShellCommand('rm -rf ' . escapeshellarg($candidate), null);
            }
        }
        if ($rollbackNodeModules !== null && (is_dir($rollbackNodeModules) || is_link($rollbackNodeModules))) {
            $currentNodeModules = rtrim($path, '/') . '/node_modules';
            if ((is_dir($currentNodeModules) || is_link($currentNodeModules)) && !$this->runShellCommand('rm -rf ' . escapeshellarg($currentNodeModules), null)) {
                $rollbackOk = false;
            }
            if (!file_exists($currentNodeModules) && !@rename($rollbackNodeModules, $currentNodeModules)) {
                $rollbackOk = false;
            }
        }
        if ($candidateNodeModules !== null && is_dir($candidateNodeModules)) {
            $this->runShellCommand('rm -rf ' . escapeshellarg($candidateNodeModules), null);
        }
        if (!$this->runCommand(['git', 'reset', '--hard', $previousCommit], $path)) {
            $rollbackOk = false;
        }
        if (!$this->startNodeAppService($project)
            || !$this->waitForProjectPortListening($port, self::NEXTJS_BUN_PORT_LISTEN_ATTEMPTS, self::PORT_LISTEN_INTERVAL_SECONDS)
            || !$this->waitForHttpResponse($port, self::DEFAULT_PORT_LISTEN_ATTEMPTS, self::PORT_LISTEN_INTERVAL_SECONDS)) {
            $rollbackOk = false;
        }
        if ($rollbackOk) {
            $this->stdout[] = '[ROLLBACK SUCCESS] previous node_app service healthy';
            return $this->fail($reason . ' (rollback 성공)');
        }
        $this->stderr[] = '[CRITICAL] node_app deployment failed and rollback failed';
        return $this->fail($reason . ' (rollback 실패)');
    }

    private function logNodeCandidateFailure(string $reason, int $port): void
    {
        $this->stderr[] = '[FAIL] ' . $reason;
        $this->stdout[] = '[SAFE] existing production service was not stopped';
        $this->stdout[] = '[SAFE] production directory was not modified';
        $this->logExistingServiceState($port);
    }

    private function cleanupOldNodeAppRollbacks(string $path): void
    {
        $this->runShellCommand('find ' . escapeshellarg($path) . ' -maxdepth 1 -type d \( -name ' . escapeshellarg('node_modules.rollback-*') . ' -o -name ' . escapeshellarg('*.rollback-*') . ' \) -mmin +1440 -exec rm -rf {} + 2>/dev/null || true', null);
    }

    private function projectEnvCommand(string $command, string $path): string
    {
        $unsetParts = array_map(static function (string $key): string {
            return '-u ' . escapeshellarg($key);
        }, self::PROJECT_ENV_UNSET_KEYS);

        $envParts = [];
        foreach ($this->projectDatabaseEnv($path) as $key => $value) {
            $envParts[] = $key . '=' . escapeshellarg($value);
        }

        return trim('env ' . implode(' ', $unsetParts) . ' ' . implode(' ', $envParts) . ' ' . $command);
    }

    /**
     * @return array<string,string>
     */
    private function projectDatabaseEnv(string $path): array
    {
        $envFile = rtrim($path, '/') . '/.env';
        if (!is_file($envFile) || !is_readable($envFile)) {
            return [];
        }

        $values = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!in_array($key, self::PROJECT_ENV_UNSET_KEYS, true)) {
                continue;
            }

            $values[$key] = $this->normalizeDotenvValue($value);
        }

        return $values;
    }

    private function normalizeDotenvValue(string $value): string
    {
        if (strlen($value) >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
                $value = substr($value, 1, -1);
                return $quote === '"' ? stripcslashes($value) : str_replace("\'", "'", $value);
            }
        }

        return $value;
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
            if ($statusCode >= 500) {
                $this->stderr[] = '[HTTP_CHECK_RESPONSE_WITH_APP_ERROR] url=' . $url
                    . ' status=' . $statusCode
                    . ' attempt=' . $attempt . '/' . $maxAttempts;
                return false;
            }
            if ($statusCode > 0) {
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

    /**
     * @param array<int,string> $previousPids
     */
    private function waitForProjectPortListening(int $port, int $maxAttempts, int $intervalSeconds, array $previousPids = []): bool
    {
        $maxWaitSeconds = $maxAttempts * $intervalSeconds;
        $this->stdout[] = '[PORT_CHECK_START] port=' . $port
            . ' max_attempts=' . $maxAttempts
            . ' interval=' . $intervalSeconds . 's'
            . ' max_wait=' . $maxWaitSeconds . 's';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (!$this->ensureProjectTimeRemaining('포트 LISTEN 확인')) {
                $this->stderr[] = '[PORT_CHECK_FAIL] port=' . $port . ' reason=project_timeout attempt=' . $attempt . '/' . $maxAttempts;
                return false;
            }

            $details = $this->portReadinessDetails($port);
            if ((bool) $details['ready']) {
                $pid = $details['pid'] ?? null;
                if ($this->isPreviousPortPid($pid, $previousPids)) {
                    $this->stderr[] = '[PORT_CHECK_FAIL] port=' . $port
                        . ' reason=previous_pid_still_listening'
                        . ' pid=' . (string) $pid
                        . ' attempt=' . $attempt . '/' . $maxAttempts
                        . ' detail=' . (string) $details['detail'];
                    return false;
                }
                $this->stdout[] = '[PORT_CHECK_SUCCESS] port=' . $port
                    . ' pid=' . ($pid ?? 'unknown')
                    . ' source=' . (string) $details['source']
                    . ' attempt=' . $attempt . '/' . $maxAttempts
                    . ' detail=' . (string) $details['detail'];
                return true;
            }

            if ($attempt === 1 || $attempt % 10 === 0 || $attempt === $maxAttempts) {
                $this->stdout[] = '[PORT_CHECK_WAIT] port=' . $port
                    . ' attempt=' . $attempt . '/' . $maxAttempts
                    . ' listen=' . $this->listeningPortsSummary($port, null)
                    . ' probes=' . (string) $details['detail'];
            }

            $remaining = $this->remainingProjectSeconds();
            if ($attempt < $maxAttempts && $remaining > 0) {
                sleep(min($intervalSeconds, $remaining));
            }
        }

        $finalDetails = $this->portReadinessDetails($port);
        if ((bool) $finalDetails['ready']) {
            $pid = $finalDetails['pid'] ?? null;
            if ($this->isPreviousPortPid($pid, $previousPids)) {
                $this->stderr[] = '[PORT_CHECK_FAIL] port=' . $port
                    . ' reason=previous_pid_still_listening'
                    . ' pid=' . (string) $pid
                    . ' attempt=final'
                    . ' detail=' . (string) $finalDetails['detail'];
                return false;
            }
            $this->stdout[] = '[PORT_CHECK_SUCCESS] port=' . $port
                . ' pid=' . ($pid ?? 'unknown')
                . ' source=' . (string) $finalDetails['source']
                . ' attempt=final'
                . ' detail=' . (string) $finalDetails['detail'];
            return true;
        }

        $this->stderr[] = '[PORT_CHECK_FAIL] port=' . $port
            . ' attempts=' . $maxAttempts
            . ' interval=' . $intervalSeconds . 's'
            . ' max_wait=' . $maxWaitSeconds . 's'
            . ' listen=' . $this->listeningPortsSummary($port, null)
            . ' probes=' . (string) $finalDetails['detail'];

        return false;
    }

    private function isPortListening(int $port): bool
    {
        return (bool) $this->portReadinessDetails($port)['ready'];
    }

    /**
     * @param array<int,string> $previousPids
     */
    private function isPreviousPortPid(mixed $pid, array $previousPids): bool
    {
        if (!$this->isNumericPid($pid)) {
            return false;
        }

        return in_array((string) $pid, $previousPids, true);
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
        exec('ss -ltnp ' . escapeshellarg('sport = :' . $port) . ' 2>/dev/null', $ssOutput, $ssCode);
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
        $allNetstatOutput = [];
        exec('netstat -ltnp 2>/dev/null', $allNetstatOutput, $netstatCode);
        $netstatOutput = array_values(array_filter($allNetstatOutput, static fn (string $line): bool => preg_match('/(^|[[:space:]])[^[:space:]]*:' . $port . '[[:space:]]/', $line) === 1));
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
        return $this->pidsFromSocketLine($line)[0] ?? null;
    }

    /**
     * @return array<int,string>
     */
    private function pidsFromSocketLine(string $line): array
    {
        $pids = [];
        if (preg_match_all('/pid=(\d+)/', $line, $matches) > 0) {
            $pids = array_merge($pids, $matches[1]);
        }
        if (preg_match('#\s(\d+)/[^\s]+#', $line, $matches) === 1) {
            $pids[] = $matches[1];
        }

        return array_values(array_unique($this->numericPids($pids)));
    }

    private function logProjectPortConfiguration(array $project, string $startMode): void
    {
        $path = (string) $project['server_path'];
        $expectedPort = (int) $project['port'];
        $this->stdout[] = '[PORT_CONFIG] source=auto_deploy project_port=' . $expectedPort
            . ' runtime=' . (string) ($project['runtime_type'] ?? '')
            . ' start_mode=' . $startMode
            . ' branch=' . (string) ($project['branch_name'] ?? '')
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
            $parts[] = 'pid:' . $pid . '_ports=' . ($ports === [] ? 'none' : implode(',', $ports));
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

    private function sanitizeCommandForLog(string $command): string
    {
        foreach (self::PROJECT_ENV_UNSET_KEYS as $key) {
            $command = preg_replace('/(' . preg_quote($key, '/') . '=)(?:' . "'[^']*'" . '|"[^"]*"|\S+)/', '$1[REDACTED]', $command) ?? $command;
        }
        $command = preg_replace("#([A-Za-z][A-Za-z0-9+.-]*://)[^\\s'\"]+#", '$1[REDACTED]', $command) ?? $command;
        $command = preg_replace('/\[REDACTED\](?:' . "'[^']*'" . '|"[^"]*"|\S*)/', '[REDACTED]', $command) ?? $command;

        return $command;
    }

    private function runShellCommand(string $command, ?string $cwd): bool
    {
        if (!$this->ensureProjectTimeRemaining('명령 실행 전')) {
            return false;
        }

        $timeout = min(self::COMMAND_TIMEOUT_SECONDS, $this->remainingProjectSeconds());
        if ($timeout <= 0) {
            return $this->fail('프로젝트 배포 타임아웃으로 명령을 실행하지 않습니다: ' . $this->sanitizeCommandForLog($command));
        }

        $logCommand = $this->sanitizeCommandForLog($command);
        $this->stdout[] = '$ ' . $logCommand;
        $this->stdout[] = 'command timeout: ' . $timeout . 's';
        $timedCommand = $this->timedShellCommand($command, $timeout);
        $this->stdout[] = '$ ' . $this->sanitizeCommandForLog($timedCommand);
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($timedCommand, $descriptor, $pipes, $cwd ?: null);
        if (!is_resource($process)) {
            $this->failureReason = '명령 실행을 시작할 수 없습니다: ' . $logCommand;
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
            $this->stderr[] = '$ ' . $logCommand;
            $this->stderr[] = trim($stderr);
        }

        if ($timedOut) {
            $this->stderr[] = '명령 타임아웃: ' . $logCommand . ' (timeout ' . $timeout . 's)';
            $this->failureReason = $this->failureReason ?? '명령 타임아웃: ' . $logCommand;
            return false;
        }

        $this->stdout[] = 'exit code: ' . $code;
        if ($code === 124 && $this->failureReason === null) {
            $this->failureReason = '명령 타임아웃: ' . $logCommand . ' (timeout ' . $timeout . 's)';
        } elseif ($code !== 0 && $this->failureReason === null) {
            $this->failureReason = '명령 실패: ' . $logCommand . ' (exit code ' . $code . ')';
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
