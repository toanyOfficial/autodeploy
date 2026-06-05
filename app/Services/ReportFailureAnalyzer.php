<?php

namespace App\Services;

final class ReportFailureAnalyzer
{
    /**
     * @return array{type:string,title:string,description:string,operation:string,button:string,confirm_message:?string}|null
     */
    public function detect(?string $content, array $history): ?array
    {
        $text = strtolower((string) $content);

        if ($this->isDependencySyncFailure($text)) {
            return $this->case(
                'dependency_sync',
                '의존성 문제로 실패한 작업입니다.',
                'package.json과 package-lock.json이 일치하지 않아 npm ci가 실패했습니다. npm install을 실행해 의존성 잠금파일을 동기화한 뒤 npm ci로 검증할 수 있습니다.',
                'sync_dependencies',
                'npm install 실행',
                '의존성 동기화를 진행합니다. npm install 실행 후 npm ci 검증을 진행합니다. 계속하시겠습니까?'
            );
        }

        if ($this->containsAny($text, [
            "fatal: could not read username for 'https://github.com'",
            'permission denied (publickey)',
            'repository not found',
            'authentication failed',
        ])) {
            return $this->case(
                'github_auth',
                'GitHub 인증 문제로 실패한 작업입니다.',
                '서버의 appuser 계정에서 GitHub 저장소에 접근하지 못했습니다. git remote가 HTTPS 방식이거나 SSH key 권한이 등록되지 않았을 수 있습니다.',
                'check_git_auth',
                'Git 설정 확인',
                null
            );
        }

        if ($this->containsAny($text, ['eacces', 'permission denied', 'operation was rejected by your operating system']) && str_contains($text, 'node_modules')) {
            return $this->case(
                'node_modules_permission',
                '파일 권한 문제로 실패한 작업입니다.',
                'node_modules 또는 프로젝트 파일 권한이 현재 실행 계정과 맞지 않아 npm 작업이 실패했습니다. 프로젝트 폴더 소유자를 appuser로 다시 맞춰야 합니다.',
                'fix_permissions',
                '권한 복구 실행',
                '프로젝트 폴더의 소유자를 appuser로 변경합니다. 계속하시겠습니까?'
            );
        }

        if ($this->containsAny($text, ['eaddrinuse', 'address already in use', 'port already in use', 'listen eaddrinuse'])) {
            return $this->case(
                'port_in_use',
                '포트가 이미 사용 중입니다.',
                '서비스가 사용하려는 포트가 이미 다른 프로세스에서 사용 중입니다. 해당 포트를 점유한 프로세스를 종료한 뒤 다시 실행해야 합니다.',
                'kill_port',
                '포트 종료 실행',
                '해당 포트를 사용 중인 프로세스를 종료합니다. 계속하시겠습니까?'
            );
        }

        if ($this->containsAny($text, ['typescript error', 'module not found', 'syntax error', 'build failed', 'failed to compile'])) {
            return $this->case(
                'source_build_failure',
                '소스코드 수정이 필요한 빌드 실패입니다.',
                '의존성이나 권한 문제가 아니라 코드 자체의 빌드 오류로 보입니다. 리포트 내용을 복사해 개발자 또는 Codex에게 전달해야 합니다.',
                'copy_report',
                '리포트 복사',
                null
            );
        }

        if (($history['runtime_type'] ?? '') === 'nextjs_bun') {
            return $this->case(
                'next_cache',
                'Next.js 캐시 초기화가 필요할 수 있습니다.',
                '화면이 이상하거나 이전 빌드 결과가 남아있는 경우 .next 폴더를 삭제한 뒤 다시 빌드할 수 있습니다.',
                'clean_next_build',
                '.next 삭제 후 재빌드',
                '.next 캐시를 삭제하고 다시 빌드합니다. 계속하시겠습니까?'
            );
        }

        return null;
    }

    private function isDependencySyncFailure(string $text): bool
    {
        return str_contains($text, 'npm ci') && $this->containsAny($text, [
            'package.json and package-lock.json',
            'package-lock.json are in sync',
            'missing:',
            'from lock file',
        ]);
    }

    /**
     * @param string[] $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{type:string,title:string,description:string,operation:string,button:string,confirm_message:?string}
     */
    private function case(string $type, string $title, string $description, string $operation, string $button, ?string $confirmMessage): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'operation' => $operation,
            'button' => $button,
            'confirm_message' => $confirmMessage,
        ];
    }
}
