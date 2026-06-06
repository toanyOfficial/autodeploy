<?php

namespace App\Services;

use App\Repositories\DeployProjectRepository;

final class StableDeploymentBatchService
{
    /** @var callable|null */
    private $logger;

    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger;
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

            $this->log(sprintf('[START] #%d %s', $projectId, $projectName));
            try {
                $startedAt = time();
                $history = (new DeployService())->deployStable($projectId);
                $elapsed = time() - $startedAt;
                $status = (string) ($history['deploy_status'] ?? 'failed');

                if ($status === 'success') {
                    $message = '안정화버전 배포가 완료되었습니다. elapsed=' . $elapsed . 's';
                    $summary['success']++;
                    $summary['results'][] = $this->result($projectId, $projectName, 'success', $message, $history);
                    $this->log(sprintf('[SUCCESS] #%d %s: %s', $projectId, $projectName, $message));
                    continue;
                }

                $message = $this->failureMessage($history, '안정화버전 배포가 실패 상태로 종료되었습니다. elapsed=' . $elapsed . 's');
                $summary['failed']++;
                $summary['results'][] = $this->result($projectId, $projectName, 'failed', $message, $history);
                $this->log(sprintf('[FAILED] #%d %s: %s', $projectId, $projectName, $message));
            } catch (\Throwable $throwable) {
                $message = $throwable->getMessage();
                $status = $this->isSkippableFailure($message) ? 'skipped' : 'failed';
                $summary[$status]++;
                $summary['results'][] = $this->result($projectId, $projectName, $status, $message, null);
                $label = $status === 'skipped' ? 'SKIP' : 'FAILED';
                $this->log(sprintf('[%s] #%d %s: %s', $label, $projectId, $projectName, $message));
            }
        }

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
}
