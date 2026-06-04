<?php

namespace App\Services;

use App\Config\Env;
use App\Repositories\DeployHistoryRepository;
use App\Repositories\DeployProjectRepository;
use App\Repositories\DeployVersionRepository;

final class DeployService
{
    private DeployProjectRepository $projects;
    private DeployVersionRepository $versions;
    private DeployHistoryRepository $histories;
    private DeploymentLock $lock;
    private array $log = [];

    public function __construct()
    {
        $this->projects = new DeployProjectRepository();
        $this->versions = new DeployVersionRepository();
        $this->histories = new DeployHistoryRepository();
        $this->lock = new DeploymentLock();
    }

    public function deployLatest(int $projectId): array
    {
        return $this->deploy($projectId, null, 'origin/main');
    }

    public function deployStable(int $projectId): array
    {
        $stable = $this->versions->findStableByProject($projectId);
        if ($stable === null || empty($stable['git_commit_hash'])) {
            throw new \RuntimeException('안정화 버전 또는 Commit Hash가 등록되어 있지 않습니다.');
        }

        return $this->deploy($projectId, $stable, (string) $stable['git_commit_hash']);
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

        return $this->deploy($projectId, $version, (string) $version['git_commit_hash']);
    }

    public function isDeploying(): bool
    {
        return $this->lock->isLocked() || $this->histories->hasRunning();
    }

    private function deploy(int $projectId, ?array $version, string $targetRef): array
    {
        if (!$this->lock->acquire()) {
            throw new \RuntimeException('다른 배포가 진행 중입니다. 잠시 후 다시 시도해 주세요.');
        }

        $history = null;
        $reportFile = null;
        $this->log = [];

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

            $this->writeLog('배포 시작: ' . $project['project_name'] . ' / 대상: ' . $targetRef);
            $success = $this->runRuntimeFlow($project, $targetRef);
            $deployedCommit = $success ? $this->currentCommit((string) $project['server_path']) : null;
            $status = $success ? 'success' : 'failed';

            $reportFile = $this->writeReport((int) $project['id'], (int) $history['id']);
            $updated = $this->histories->update((int) $history['id'], [
                'deploy_status' => $status,
                'deployed_commit_hash' => $deployedCommit,
                'ended_at' => $this->now(),
                'report_file' => $reportFile,
            ]);
            $this->pruneReports((int) $project['id']);

            return $updated ?? $history;
        } catch (\Throwable $throwable) {
            $this->writeLog('오류: ' . $throwable->getMessage());
            if ($history !== null) {
                $reportFile = $this->writeReport($projectId, (int) $history['id']);
                $this->histories->update((int) $history['id'], [
                    'deploy_status' => 'failed',
                    'ended_at' => $this->now(),
                    'report_file' => $reportFile,
                ]);
                $this->pruneReports($projectId);
            }
            throw $throwable;
        } finally {
            $this->lock->release();
        }
    }

    private function runRuntimeFlow(array $project, string $targetRef): bool
    {
        $runtime = (string) $project['runtime_type'];
        $path = (string) $project['server_path'];
        $port = (int) $project['port'];

        $this->runCommand(['git', 'fetch', '--all'], $path);
        $this->runCommand(['git', 'reset', '--hard', $targetRef], $path);

        if ($runtime === 'nextjs_bun') {
            if (!$this->runCommand(['npm', 'ci'], $path)) {
                $this->writeLog('npm ci 실패: 기존 서비스는 종료하지 않습니다.');
                return false;
            }
            if (!$this->runShellCommand('rm -rf .next', $path)) {
                $this->writeLog('rm -rf .next 실패: 기존 서비스는 종료하지 않습니다.');
                return false;
            }
            if (!$this->runCommand(['bun', 'run', 'build'], $path)) {
                $this->writeLog('bun run build 실패: 기존 서비스는 종료하지 않습니다.');
                return false;
            }

            $this->stopPort($port);
            $this->runShellCommand('nohup env PORT=' . $port . ' bun run start -H 0.0.0.0 > app.log 2>&1 &', $path);
            return true;
        }

        if ($runtime === 'python_static') {
            $this->stopPort($port);
            $this->runShellCommand('nohup python3 -m http.server ' . $port . ' --bind 0.0.0.0 > app.log 2>&1 &', $path);
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
    }

    private function validateGitRef(string $ref): void
    {
        if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $ref)) {
            throw new \RuntimeException('Git ref 형식이 올바르지 않습니다.');
        }
    }

    private function stopPort(int $port): void
    {
        $this->runShellCommand('pids=$(lsof -ti tcp:' . $port . ' 2>/dev/null || true); if [ -n "$pids" ]; then kill $pids; fi', null);
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

    private function runShellCommand(string $command, ?string $cwd): bool
    {
        $this->writeLog('$ ' . $command);
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptor, $pipes, $cwd ?: null);
        if (!is_resource($process)) {
            $this->writeLog('명령 실행을 시작할 수 없습니다.');
            return false;
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if ($stdout !== '') {
            $this->writeLog(trim($stdout));
        }
        if ($stderr !== '') {
            $this->writeLog(trim($stderr));
        }

        $code = proc_close($process);
        $this->writeLog('exit code: ' . $code);

        return $code === 0;
    }

    private function writeLog(string $message): void
    {
        $this->log[] = '[' . $this->now() . '] ' . $message;
    }

    private function writeReport(int $projectId, int $historyId): string
    {
        $reportDir = rtrim(Env::get('REPORT_DIR', __DIR__ . '/../../reports') ?? __DIR__ . '/../../reports', '/');
        $projectDir = $reportDir . '/project_' . $projectId;
        if (!is_dir($projectDir)) {
            mkdir($projectDir, 0755, true);
        }

        $file = $projectDir . '/deploy_' . $historyId . '_' . date('Ymd_His') . '.txt';
        file_put_contents($file, implode(PHP_EOL, $this->log) . PHP_EOL);

        return $file;
    }

    private function pruneReports(int $projectId): void
    {
        $reportDir = rtrim(Env::get('REPORT_DIR', __DIR__ . '/../../reports') ?? __DIR__ . '/../../reports', '/');
        $files = glob($reportDir . '/project_' . $projectId . '/deploy_*.txt') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, 5) as $file) {
            @unlink($file);
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
