<?php

namespace App\Services;

final class RebootAutomationStatusService
{
    private const AUTO_REBOOT_SCRIPT = '/usr/local/sbin/auto-reboot-deploy.sh';
    private const POST_REBOOT_SCRIPT = '/usr/local/sbin/dandorak-post-reboot.sh';
    private const SYSTEMD_SERVICE = '/etc/systemd/system/dandorak-post-reboot.service';
    private const AUTO_DEPLOY_WEB_SERVICE = '/etc/systemd/system/auto-deploy-web.service';
    private const PROJECT_SYSTEMD_TEMPLATE = '/etc/systemd/system/auto-deploy-project@.service';
    private const PROJECT_RUNNER = '/usr/local/bin/auto-deploy-project-runner';
    private const PROJECT_CONTROL = '/usr/local/sbin/auto-deploy-project-control';
    private const AUTO_DEPLOY_WEB_CONTROL = '/usr/local/sbin/auto-deploy-web-control';
    private const STATE_DIR = '/var/lib/auto_deploy';
    private const LOG_DIR = '/var/log/auto_deploy';
    private const LOG_FILE = '/var/log/auto_deploy/reboot-deploy.log';

    /**
     * @return array{installed:bool,checks:array<int,array<string,mixed>>,missing:array<int,string>,guide:string}
     */
    public function status(): array
    {
        $checks = [
            $this->pathCheck('auto_reboot_script', 'auto-reboot-deploy.sh', self::AUTO_REBOOT_SCRIPT, 'file', true),
            $this->pathCheck('post_reboot_script', 'dandorak-post-reboot.sh', self::POST_REBOOT_SCRIPT, 'file', true),
            $this->pathCheck('systemd_service', 'dandorak-post-reboot.service', self::SYSTEMD_SERVICE, 'file', false),
            $this->pathCheck('auto_deploy_web_service', 'auto-deploy-web.service', self::AUTO_DEPLOY_WEB_SERVICE, 'file', false),
            $this->pathCheck('project_systemd_template', 'auto-deploy-project@.service', self::PROJECT_SYSTEMD_TEMPLATE, 'file', false),
            $this->pathCheck('project_runner', 'auto-deploy-project-runner', self::PROJECT_RUNNER, 'file', true),
            $this->pathCheck('project_control', 'auto-deploy-project-control', self::PROJECT_CONTROL, 'file', true),
            $this->pathCheck('auto_deploy_web_control', 'auto-deploy-web-control', self::AUTO_DEPLOY_WEB_CONTROL, 'file', true),
            $this->sudoCommandCheck('project_systemd_sudo_permission', 'auto-deploy-project-control sudo permission', [self::PROJECT_CONTROL, 'check-permission']),
            $this->sudoCommandCheck('web_systemd_sudo_permission', 'auto-deploy-web-control sudo permission', [self::AUTO_DEPLOY_WEB_CONTROL, 'check-permission']),
            $this->pathCheck('state_dir', 'auto deploy state directory', self::STATE_DIR, 'dir', false),
            $this->pathCheck('log_dir', 'reboot deploy log directory', self::LOG_DIR, 'dir', false),
            $this->pathCheck('log_file', 'reboot deploy log file', self::LOG_FILE, 'file', false),
            $this->sudoCheck(),
        ];

        $missing = [];
        foreach ($checks as $check) {
            if (!($check['ok'] ?? false)) {
                $missing[] = (string) ($check['message'] ?? $check['path'] ?? $check['label']);
            }
        }

        return [
            'installed' => empty($missing),
            'checks' => $checks,
            'missing' => $missing,
            'guide' => '/docs/reboot-automation.md',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function pathCheck(string $key, string $label, string $path, string $type, bool $mustBeExecutable): array
    {
        $exists = $type === 'dir' ? is_dir($path) : is_file($path);
        $executable = !$mustBeExecutable || ($exists && is_executable($path));
        $ok = $exists && $executable;

        $message = $ok ? 'OK' : $path;
        if ($exists && !$executable) {
            $message = $path . ' 파일에 실행 권한이 없습니다.';
        }

        return [
            'key' => $key,
            'label' => $label,
            'path' => $path,
            'type' => $type,
            'required' => true,
            'must_be_executable' => $mustBeExecutable,
            'exists' => $exists,
            'executable' => $executable,
            'ok' => $ok,
            'message' => $message,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sudoCheck(): array
    {
        return $this->sudoListCheck(
            'sudo_permission',
            'sudo permission',
            self::AUTO_REBOOT_SCRIPT,
            'sudo 권한 확인 전 auto-reboot-deploy.sh 설치가 필요합니다.'
        );
    }

    /**
     * @param array<int,string> $commandParts
     * @return array<string,mixed>
     */
    private function sudoCommandCheck(string $key, string $label, array $commandParts): array
    {
        $target = $commandParts[0] ?? '';
        if ($target === '' || !is_file($target) || !is_executable($target)) {
            return [
                'key' => $key,
                'label' => $label,
                'path' => $target,
                'type' => 'sudo',
                'required' => true,
                'command' => 'sudo -n ' . implode(' ', $commandParts),
                'ok' => false,
                'exit_code' => null,
                'stdout' => '',
                'stderr' => 'sudo 권한 확인 전 wrapper 설치가 필요합니다.',
                'message' => 'sudo 권한 확인 전 ' . $target . ' 설치가 필요합니다.',
            ];
        }

        return $this->runSudoCheck($key, $label, array_merge(['sudo', '-n'], $commandParts), $target);
    }

    /**
     * @return array<string,mixed>
     */
    private function sudoListCheck(string $key, string $label, string $target, string $missingMessage): array
    {
        if (!is_file($target)) {
            return [
                'key' => $key,
                'label' => $label,
                'path' => $target,
                'type' => 'sudo',
                'required' => true,
                'command' => 'sudo -n -l ' . $target,
                'ok' => false,
                'exit_code' => null,
                'stdout' => '',
                'stderr' => $missingMessage,
                'message' => 'sudo 권한 확인 전 ' . $target . ' 설치가 필요합니다.',
            ];
        }

        return $this->runSudoCheck($key, $label, ['sudo', '-n', '-l', $target], $target);
    }

    /**
     * @param array<int,string> $command
     * @return array<string,mixed>
     */
    private function runSudoCheck(string $key, string $label, array $command, string $path): array
    {
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptor, $pipes);
        if (!is_resource($process)) {
            return [
                'key' => $key,
                'label' => $label,
                'path' => $path,
                'type' => 'sudo',
                'required' => true,
                'command' => implode(' ', $command),
                'ok' => false,
                'exit_code' => null,
                'stdout' => '',
                'stderr' => 'proc_open returned a non-resource process handle.',
                'message' => 'sudo 권한 확인 프로세스를 시작할 수 없습니다.',
            ];
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $ok = $code === 0;

        return [
            'key' => $key,
            'label' => $label,
            'path' => $path,
            'type' => 'sudo',
            'required' => true,
            'command' => implode(' ', $command),
            'ok' => $ok,
            'exit_code' => $code,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
            'message' => $ok ? 'OK' : 'sudoers 설정이 없거나 비밀번호 없이 실행할 수 없습니다.',
        ];
    }
}
