<?php

namespace App\Services;

use App\Config\Env;
use App\Repositories\DeployHistoryRepository;

final class ReportService
{
    private DeployHistoryRepository $histories;

    public function __construct()
    {
        $this->histories = new DeployHistoryRepository();
    }

    public function createReport(array $project, array $data): string
    {
        $projectDir = $this->projectDirectory($project);
        if (!is_dir($projectDir)) {
            mkdir($projectDir, 0755, true);
        }

        $path = $projectDir . '/' . date('Ymd_His') . '.txt';
        file_put_contents($path, $this->formatReport($project, $data));

        return $path;
    }

    public function pruneProjectReports(array $project): void
    {
        $files = glob($this->projectDirectory($project) . '/*.txt') ?: [];
        rsort($files, SORT_STRING);

        foreach (array_slice($files, 5) as $file) {
            @unlink($file);
        }
    }

    public function readByHistoryId(int $historyId): ?array
    {
        $history = $this->histories->findWithProject($historyId);
        if ($history === null || empty($history['report_file'])) {
            return null;
        }

        $path = (string) $history['report_file'];
        if (!is_file($path) || !is_readable($path)) {
            return ['history' => $history, 'content' => null, 'missing' => true];
        }

        return ['history' => $history, 'content' => file_get_contents($path), 'missing' => false];
    }

    private function projectDirectory(array $project): string
    {
        $reportDir = rtrim(Env::get('REPORT_DIR', __DIR__ . '/../../reports') ?? __DIR__ . '/../../reports', '/');
        return $reportDir . '/' . $this->safeDirectoryName((string) $project['project_key']);
    }

    private function safeDirectoryName(string $projectKey): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $projectKey);
        return $safe === '' ? 'project' : $safe;
    }

    private function formatReport(array $project, array $data): string
    {
        $stdout = trim((string) ($data['stdout'] ?? ''));
        $stderr = trim((string) ($data['stderr'] ?? ''));
        $failureReason = trim((string) ($data['failure_reason'] ?? ''));

        return implode(PHP_EOL, [
            '배포 시작 시간: ' . ($data['started_at'] ?? ''),
            '배포 종료 시간: ' . ($data['ended_at'] ?? ''),
            '프로젝트명: ' . ($project['project_name'] ?? ''),
            'project_key: ' . ($project['project_key'] ?? ''),
            '배포 종류: ' . ($data['deploy_type'] ?? ''),
            '버전명: ' . ($data['version_name'] ?? '최신 main'),
            '요청 Commit Hash: ' . ($data['requested_commit_hash'] ?? ''),
            '실제 배포 Commit Hash: ' . ($data['deployed_commit_hash'] ?? ''),
            '실행 결과: ' . ($data['result'] ?? ''),
            '',
            '[stdout]',
            $stdout === '' ? '(없음)' : $stdout,
            '',
            '[stderr]',
            $stderr === '' ? '(없음)' : $stderr,
            '',
            '실패 원인: ' . ($failureReason === '' ? '(없음)' : $failureReason),
            '',
        ]);
    }
}
