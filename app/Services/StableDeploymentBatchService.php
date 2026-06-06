<?php

namespace App\Services;

use App\Config\Env;
use App\Repositories\DeployHistoryRepository;
use App\Repositories\DeployProjectRepository;

final class StableDeploymentBatchService
{
    private const DEFAULT_PROJECT_TIMEOUT_SECONDS = 720;

    /** @var callable|null */
    private $logger;
    private int $projectTimeoutSeconds;

    public function __construct(?callable $logger = null, ?int $projectTimeoutSeconds = null)
    {
        $this->logger = $logger;
        $this->projectTimeoutSeconds = $projectTimeoutSeconds
            ?? max(60, (int) (Env::get('BATCH_PROJECT_TIMEOUT_SECONDS', (string) self::DEFAULT_PROJECT_TIMEOUT_SECONDS) ?? self::DEFAULT_PROJECT_TIMEOUT_SECONDS));
    }

    /**
     * @return array{success:int,failed:int,skipped:int,total:int,results:array<int,array<string,mixed>>}
     */
    public function deployAll(): array
    {
        $projects = (new DeployProjectRepository())->all(true);
        $summary = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'total' => count($projects),
            'results' => [],
        ];

        foreach ($projects as $project) {
            $projectId = (int) $project['id'];
            $projectName = (string) ($project['project_name'] ?? $project['project_key'] ?? ('project-' . $projectId));
            $projectKey = (string) ($project['project_key'] ?? '');
            $port = (int) ($project['port'] ?? 0);
            $pm2Name = $this->pm2ProcessName($project);

            $this->log(sprintf(
                '[PROJECT_START] #%d %s key=%s pm2=%s port=%d timeout=%ds',
                $projectId,
                $projectName,
                $projectKey,
                $pm2Name,
                $port,
                $this->projectTimeoutSeconds
            ));
            $this->log('[PRE_PM2_LIST] ' . $this->pm2ListSummary());
            $this->log('[PRE_LISTEN_PORTS] ' . $this->listenPortsSummary());
            $this->log(sprintf('[PROJECT_DEPLOY_START] #%d %s pm2=%s port=%d', $projectId, $projectName, $pm2Name, $port));

            $startedAt = time();
            $result = $this->runProjectDeployProcess($projectId);
            $elapsed = time() - $startedAt;

            $this->log('[POST_PM2_LIST] ' . $this->pm2ListSummary());
            $this->log('[POST_LISTEN_PORTS] ' . $this->listenPortsSummary());

            if ((bool) $result['timed_out']) {
                $timeoutReport = $this->createTimeoutReport($project, $startedAt, $elapsed, $result);
                $failedRows = (new DeployHistoryRepository())->failRunningByProject($projectId, $timeoutReport);
                $message = sprintf(
                    '프로젝트 배포 timeout: elapsed=%ds timeout=%ds failed_running_rows=%d cleanup=not_run pm2=%s port=%d report=%s',
                    $elapsed,
                    $this->projectTimeoutSeconds,
                    $failedRows,
                    $pm2Name,
                    $port,
                    $timeoutReport ?? '(none)'
                );
                $summary['failed']++;
                $summary['results'][] = $this->result($projectId, $projectName, 'failed', $message, null);
                $this->log(sprintf('[FAILED] #%d %s: %s', $projectId, $projectName, $message));
                continue;
            }

            $payload = $this->decodeProjectDeployOutput((string) $result['stdout']);
            $history = is_array($payload['history'] ?? null) ? $payload['history'] : null;
            $status = (string) ($payload['status'] ?? ($result['exit_code'] === 0 ? 'success' : 'failed'));

            if ($status === 'success' && (int) $result['exit_code'] === 0) {
                $message = '안정화버전 배포가 완료되었습니다. elapsed=' . $elapsed . 's';
                $summary['success']++;
                $summary['results'][] = $this->result($projectId, $projectName, 'success', $message, $history);
                $this->log(sprintf('[SUCCESS] #%d %s: %s', $projectId, $projectName, $message));
                continue;
            }

            $error = trim((string) ($payload['error'] ?? $result['stderr'] ?? ''));
            $statusKey = $this->isSkippableFailure($error) ? 'skipped' : 'failed';
            $summary[$statusKey]++;
            $message = $this->failureMessage($history, '안정화버전 배포가 실패 상태로 종료되었습니다. elapsed=' . $elapsed . 's');
            if ($error !== '') {
                $message .= ' error=' . $error;
            }
            $summary['results'][] = $this->result($projectId, $projectName, $statusKey, $message, $history);
            $label = $statusKey === 'skipped' ? 'SKIP' : 'FAILED';
            $this->log(sprintf('[%s] #%d %s: %s', $label, $projectId, $projectName, $message));
        }

        $this->log(sprintf(
            '[SUMMARY] total=%d success=%d failed=%d skipped=%d',
            (int) $summary['total'],
            (int) $summary['success'],
            (int) $summary['failed'],
            (int) $summary['skipped']
        ));

        return $summary;
    }

    /**
     * @return array{project_id:int,project_name:string,status:string,message:string,history:?array}
     */
    private function result(int $projectId, string $projectName, string $status, string $message, ?array $history): array
    {
        return [
            'project_id' => $projectId,
            'project_name' => $projectName,
            'status' => $status,
            'message' => $message,
            'history' => $history,
        ];
    }

    /**
     * @return array{exit_code:int|null,stdout:string,stderr:string,timed_out:bool}
     */
    private function runProjectDeployProcess(int $projectId): array
    {
        $root = dirname(__DIR__, 2);
        $command = [PHP_BINARY, $root . '/scripts/deploy_stable_project.php', (string) $projectId];
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptor, $pipes, $root);
        if (!is_resource($process)) {
            return [
                'exit_code' => null,
                'stdout' => '',
                'stderr' => '프로젝트 배포 child process를 시작할 수 없습니다.',
                'timed_out' => false,
            ];
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

            if ((time() - $startedAt) >= $this->projectTimeoutSeconds) {
                $timedOut = true;
                proc_terminate($process);
                usleep(500000);
                $status = proc_get_status($process);
                if ($status['running'] && defined('SIGKILL')) {
                    proc_terminate($process, SIGKILL);
                }
                break;
            }

            usleep(200000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        if ($exitCode === null || $exitCode === -1) {
            $exitCode = $closeCode;
        }

        return [
            'exit_code' => $exitCode,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
            'timed_out' => $timedOut,
        ];
    }

    private function createTimeoutReport(array $project, int $startedAt, int $elapsed, array $processResult): ?string
    {
        try {
            return (new ReportService())->createReport($project, [
                'started_at' => date('Y-m-d H:i:s', $startedAt),
                'ended_at' => date('Y-m-d H:i:s'),
                'deploy_type' => '전체 안정화버전 순차 배포',
                'version_name' => '안정화버전',
                'requested_commit_hash' => '',
                'deployed_commit_hash' => null,
                'result' => 'failed',
                'stdout' => '[BATCH_TIMEOUT] elapsed=' . $elapsed . 's timeout=' . $this->projectTimeoutSeconds . 's'
                    . PHP_EOL . '[child stdout]' . PHP_EOL . (string) ($processResult['stdout'] ?? ''),
                'stderr' => '[child stderr]' . PHP_EOL . (string) ($processResult['stderr'] ?? ''),
                'failure_reason' => '전체 순차 배포 프로젝트 timeout: ' . $this->projectTimeoutSeconds . 's',
            ]);
        } catch (\Throwable $throwable) {
            $this->log('[TIMEOUT_REPORT_FAILED] ' . $throwable->getMessage());
            return null;
        }
    }

    private function decodeProjectDeployOutput(string $stdout): array
    {
        $lines = array_values(array_filter(array_map('trim', explode(PHP_EOL, $stdout)), static fn (string $line): bool => $line !== ''));
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $decoded = json_decode($lines[$index], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }

    private function failureMessage(?array $history, string $default): string
    {
        $reportFile = (string) ($history['report_file'] ?? '');
        $reason = $this->failureReasonFromReport($reportFile);
        if ($reason !== null) {
            return $default . ' reason=' . $reason . ' report=' . $reportFile;
        }
        if ($reportFile !== '') {
            return $default . ' report=' . $reportFile;
        }

        return $default;
    }

    private function failureReasonFromReport(string $reportFile): ?string
    {
        if ($reportFile === '' || !is_readable($reportFile)) {
            return null;
        }

        $content = file_get_contents($reportFile) ?: '';
        if (preg_match('/실패 원인: (.+)/u', $content, $matches) === 1 && trim($matches[1]) !== '(없음)') {
            return trim($matches[1]);
        }
        if (preg_match('/\[stderr\]\s*(.+?)\s*실패 원인:/su', $content, $matches) === 1) {
            $stderr = trim($matches[1]);
            if ($stderr !== '' && $stderr !== '(없음)') {
                return substr(preg_replace('/\s+/', ' ', $stderr), 0, 300);
            }
        }

        return null;
    }

    private function isSkippableFailure(string $message): bool
    {
        return str_contains($message, '안정화버전이 등록되어 있지 않습니다.')
            || str_contains($message, '안정화버전에 Commit Hash가 등록되어 있지 않습니다.');
    }

    private function pm2ProcessName(array $project): string
    {
        $name = (string) ($project['project_key'] ?? $project['project_name'] ?? ('project-' . ($project['id'] ?? 'unknown')));
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) ?: 'auto-deploy-project';

        return trim($name, '-') ?: 'auto-deploy-project';
    }

    private function pm2ListSummary(): string
    {
        $output = [];
        $code = 0;
        exec('timeout --kill-after=2s 8s pm2 jlist 2>/dev/null', $output, $code);
        if ($code !== 0) {
            return 'unavailable exit_code=' . $code;
        }

        $decoded = json_decode(implode(PHP_EOL, $output), true);
        if (!is_array($decoded)) {
            return 'unavailable invalid_json';
        }

        $items = [];
        foreach ($decoded as $process) {
            if (!is_array($process)) {
                continue;
            }
            $environment = $process['pm2_env'] ?? [];
            $items[] = sprintf(
                '%s:%s:pid=%s',
                (string) ($process['name'] ?? 'unknown'),
                is_array($environment) ? (string) ($environment['status'] ?? 'unknown') : 'unknown',
                (string) ($process['pid'] ?? 'n/a')
            );
        }

        return $items === [] ? 'empty' : implode(', ', $items);
    }

    private function listenPortsSummary(): string
    {
        $output = [];
        $code = 0;
        exec("timeout --kill-after=2s 8s bash -lc 'ss -ltnp 2>/dev/null || netstat -ltnp 2>/dev/null || true'", $output, $code);
        if ($code !== 0) {
            return 'unavailable exit_code=' . $code;
        }

        $lines = [];
        foreach ($output as $line) {
            if (preg_match('/:(\d+)\s/', $line, $matches) === 1) {
                $lines[] = trim($line);
            }
        }

        return $lines === [] ? 'empty' : implode(' | ', array_slice($lines, 0, 30));
    }
}
