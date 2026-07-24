<?php

namespace App\Controllers;

use App\Config\Env;
use App\Core\Request;
use App\Core\Response;
use App\Security\AdminPassword;
use App\Services\AppVersionService;

final class AuthController
{
    public function loginForm(): void
    {
        if (($_SESSION['authenticated'] ?? false) === true) {
            Response::redirect('/dashboard');
            return;
        }

        Response::view('auth/login', [
            'error' => $_SESSION['login_error'] ?? null,
            'appCommitHash' => (new AppVersionService())->currentCommitHash(),
        ]);
        unset($_SESSION['login_error']);
    }

    public function login(Request $request): void
    {
        $input = $request->input();
        $adminId = Env::required('ADMIN_ID');
        $adminPassword = Env::required('ADMIN_PASSWORD');
        $sessionSecret = Env::get('SESSION_SECRET', '') ?? '';

        $idMatches = hash_equals($adminId, (string) ($input['admin_id'] ?? ''));
        $passwordMatches = AdminPassword::verify(
            $adminPassword,
            (string) ($input['admin_password'] ?? ''),
            $sessionSecret
        );

        if ($idMatches && $passwordMatches) {
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['admin_id'] = $adminId;
            Response::redirect('/dashboard');
            return;
        }

        $_SESSION['login_error'] = '아이디 또는 비밀번호를 다시 확인해 주세요.';
        Response::redirect('/login');
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        Response::redirect('/login');
    }
}
