<?php

namespace App\Services;

use App\Repositories\DeployProjectRepository;

final class StableDeploymentBatchService
{
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

            try {
                $history = (new DeployService())->deployStable($projectId);
                $status = (string) ($history['deploy_status'] ?? 'failed');

                if ($status === 'success') {
                    $summary['success']++;
                    $summary['results'][] = $this->result($projectId, $projectName, 'success', '안정화버전 배포가 완료되었습니다.', $history);
                    continue;
                }

                $summary['failed']++;
                $summary['results'][] = $this->result($projectId, $projectName, 'failed', '안정화버전 배포가 실패 상태로 종료되었습니다.', $history);
            } catch (\Throwable $throwable) {
                $message = $throwable->getMessage();
                $status = $this->isSkippableFailure($message) ? 'skipped' : 'failed';
                $summary[$status]++;
                $summary['results'][] = $this->result($projectId, $projectName, $status, $message, null);
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

    private function isSkippableFailure(string $message): bool
    {
        return str_contains($message, '안정화버전이 등록되어 있지 않습니다.')
            || str_contains($message, '안정화버전에 Commit Hash가 등록되어 있지 않습니다.');
    }
}
