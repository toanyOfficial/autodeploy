<?php

namespace App\Services;

use App\Repositories\DeployHistoryRepository;

final class ReportOperationService
{
    private const OPERATIONS = [
        'sync_dependencies',
        'check_git_auth',
        'fix_permissions',
        'kill_port',
        'clean_next_build',
        'copy_report',
    ];

    private DeployHistoryRepository $histories;
    private ReportService $reports;

    public function __construct()
    {
        $this->histories = new DeployHistoryRepository();
        $this->reports = new ReportService();
    }

    public function run(int $historyId, string $operation): array
    {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException('지원하지 않는 operation 입니다.');
        }

        $history = $this->histories->findWithProject($historyId);
        if ($history === null || empty($history['report_file'])) {
            throw new \RuntimeException('리포트 이력을 찾을 수 없습니다.');
        }

        if ($operation === 'copy_report') {
            return [
                'success' => true,
                'message' => '리포트 복사는 화면에서 처리됩니다.',
                'log' => 'copy_report operation은 서버 명령을 실행하지 않습니다.',
                'content' => $this->reports->readByHistoryId($historyId)['content'] ?? null,
            ];
        }

        $projectPath = (string) $history['server_path'];
        $this->validateProjectPath($projectPath);

        $result = match ($operation) {
            'sync_dependencies' => $this->syncDependencies($projectPath),
            'check_git_auth' => $this->checkGitAuth($projectPath),
            'fix_permissions' => $this->fixPermissions($projectPath),
            'kill_port' => $this->killPort((int) $history['port']),
            'clean_next_build' => $this->cleanNextBuild($projectPath),
            default => throw new \InvalidArgumentException('지원하지 않는 operation 입니다.'),
        };

        $this->appendOperationReport((string) $history['report_file'], $operation, $result['log']);
        $freshReport = $this->reports->readByHistoryId($historyId);

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? '작업이 완료되었습니다.' : '작업에 실패했습니다. 상세 로그를 확인해주세요.',
            'log' => $result['log'],
            'content' => $freshReport['content'] ?? null,
        ];
    }

    private function syncDependencies(string $projectPath): array
    {
        return $this->runSequence([
            ['command' => 'npm install', 'cwd' => $projectPath],
            ['command' => 'npm ci', 'cwd' => $projectPath],
        ]);
    }

    private function checkGitAuth(string $projectPath): array
    {
        $result = $this->runSequence([
            ['command' => 'git remote -v', 'cwd' => $projectPath],
            ['command' => 'ssh -T git@github.com', 'cwd' => $projectPath, 'allow_failure' => true],
            ['command' => 'whoami', 'cwd' => $projectPath],
        ]);

        return ['success' => true, 'log' => $result['log']];
    }

    private function fixPermissions(string $projectPath): array
    {
        return $this->runSequence([
            ['command' => 'sudo chown -R appuser:appuser ' . escapeshellarg($projectPath), 'cwd' => null],
        ]);
    }

    private function killPort(int $port): array
    {
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('포트 값이 올바르지 않습니다.');
        }
        if ($port === 9090) {
            throw new \RuntimeException('Auto Deploy 보호 포트 9090은 종료할 수 없습니다.');
        }

        return $this->runSequence([
            ['command' => 'sudo fuser -k ' . $port . '/tcp', 'cwd' => null],
        ]);
    }

    private function cleanNextBuild(string $projectPath): array
    {
        return $this->runSequence([
            ['command' => 'rm -rf .next', 'cwd' => $projectPath],
            ['command' => 'bun run build', 'cwd' => $projectPath],
        ]);
    }

    private function validateProjectPath(string $projectPath): void
    {
        if ($projectPath === '' || !is_dir($projectPath)) {
            throw new \RuntimeException('프로젝트 경로를 찾을 수 없습니다.');
        }
    }

    /**
     * @param array<int,array{command:string,cwd:?string,allow_failure?:bool}> $steps
     * @return array{success:bool,log:string}
     */
    private function runSequence(array $steps): array
    {
        $logs = [];
        $success = true;

        foreach ($steps as $step) {
            $result = $this->runCommand($step['command'], $step['cwd']);
            $logs[] = $result['log'];

            if (!$result['success'] && empty($step['allow_failure'])) {
                $success = false;
                break;
            }
        }

        return ['success' => $success, 'log' => implode(PHP_EOL . PHP_EOL, $logs)];
    }

    /**
     * @return array{success:bool,log:string}
     */
    private function runCommand(string $command, ?string $cwd): array
    {
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptor, $pipes, $cwd ?: null);
        if (!is_resource($process)) {
            return ['success' => false, 'log' => '$ ' . $command . PHP_EOL . '명령 실행을 시작할 수 없습니다.'];
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);
        $log = [
            '$ ' . $command,
            'cwd: ' . ($cwd ?? '(none)'),
            '[stdout]',
            trim($stdout) === '' ? '(없음)' : trim($stdout),
            '[stderr]',
            trim($stderr) === '' ? '(없음)' : trim($stderr),
            'exit code: ' . $code,
        ];

        return ['success' => $code === 0, 'log' => implode(PHP_EOL, $log)];
    }

    private function appendOperationReport(string $reportFile, string $operation, string $log): void
    {
        if (!is_file($reportFile) || !is_writable($reportFile)) {
            return;
        }

        file_put_contents($reportFile, implode(PHP_EOL, [
            '',
            '---',
            '[operation: ' . $operation . ']',
            '실행 시간: ' . date('Y-m-d H:i:s'),
            $log,
            '',
        ]), FILE_APPEND);
    }
}
