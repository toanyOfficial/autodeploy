<?php

namespace App\Core;

use App\Controllers\ApiController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\DeployController;
use App\Controllers\ProjectController;
use App\Controllers\ReportController;
use App\Controllers\VersionController;
use App\Middleware\AuthMiddleware;

final class Router
{
    public function dispatch(Request $request): void
    {
        $path = $request->path();
        $method = $request->method();

        if ($path === '/') {
            Response::redirect(AuthMiddleware::check() ? '/dashboard' : '/login');
            return;
        }

        $auth = new AuthController();
        if ($path === '/login' && $method === 'GET') {
            $auth->loginForm();
            return;
        }
        if ($path === '/login' && $method === 'POST') {
            $auth->login($request);
            return;
        }
        if ($path === '/logout' && $method === 'POST') {
            $auth->logout();
            return;
        }

        AuthMiddleware::requireLogin();

        if ($path === '/dashboard' && $method === 'GET') {
            (new DashboardController())->index();
            return;
        }

        if ($path === '/docs/reboot-automation.md' && $method === 'GET') {
            $doc = __DIR__ . '/../../docs/reboot-automation.md';
            if (is_readable($doc)) {
                header('Content-Type: text/plain; charset=utf-8');
                readfile($doc);
                return;
            }
            Response::json(['message' => 'Document not found.'], 404);
            return;
        }


        $projectController = new ProjectController();
        if ($path === '/projects' && $method === 'POST') {
            $projectController->store($request);
            return;
        }
        if (preg_match('#^/projects/(\d+)$#', $path, $matches) && ($method === 'POST' || $method === 'PUT' || $method === 'PATCH')) {
            $projectController->update($request, (int) $matches[1]);
            return;
        }
        if (preg_match('#^/projects/(\d+)/deactivate$#', $path, $matches) && $method === 'POST') {
            $projectController->deactivate((int) $matches[1]);
            return;
        }


        $versionController = new VersionController();
        if (preg_match('#^/projects/(\d+)/versions$#', $path, $matches) && $method === 'POST') {
            $versionController->store($request, (int) $matches[1]);
            return;
        }
        if (preg_match('#^/versions/(\d+)$#', $path, $matches) && ($method === 'POST' || $method === 'PUT' || $method === 'PATCH')) {
            $versionController->update($request, (int) $matches[1]);
            return;
        }
        if (preg_match('#^/versions/(\d+)/deactivate$#', $path, $matches) && $method === 'POST') {
            $versionController->deactivate((int) $matches[1]);
            return;
        }

        $deployController = new DeployController();
        if (preg_match('#^/projects/(\d+)/deploy/latest$#', $path, $matches) && $method === 'POST') {
            $deployController->latest((int) $matches[1]);
            return;
        }
        if (preg_match('#^/projects/(\d+)/deploy/stable$#', $path, $matches) && $method === 'POST') {
            $deployController->stable((int) $matches[1]);
            return;
        }
        if (preg_match('#^/projects/(\d+)/deploy/versions/(\d+)$#', $path, $matches) && $method === 'POST') {
            $deployController->version((int) $matches[1], (int) $matches[2]);
            return;
        }

        if (preg_match('#^/reports/(\d+)$#', $path, $matches) && $method === 'GET') {
            (new ReportController())->show((int) $matches[1]);
            return;
        }

        $api = new ApiController();
        if ($path === '/api/db/status' && $method === 'GET') {
            $api->dbStatus();
            return;
        }
        if ($path === '/api/projects') {
            $api->projects($request);
            return;
        }
        if (preg_match('#^/api/projects/(\d+)$#', $path, $matches)) {
            $api->project($request, (int) $matches[1]);
            return;
        }
        if (preg_match('#^/api/projects/(\d+)/deactivate$#', $path, $matches) && $method === 'POST') {
            $api->deactivateProject((int) $matches[1]);
            return;
        }
        if (preg_match('#^/api/projects/(\d+)/versions$#', $path, $matches)) {
            $api->versions($request, (int) $matches[1]);
            return;
        }
        if (preg_match('#^/api/versions/(\d+)$#', $path, $matches)) {
            $api->version($request, (int) $matches[1]);
            return;
        }
        if (preg_match('#^/api/versions/(\d+)/stable$#', $path, $matches) && $method === 'POST') {
            $api->markStableVersion((int) $matches[1]);
            return;
        }
        if (preg_match('#^/api/reports/(\d+)/operation$#', $path, $matches) && $method === 'POST') {
            $api->reportOperation($request, (int) $matches[1]);
            return;
        }
        if (preg_match('#^/api/reports/(\d+)$#', $path, $matches) && $method === 'GET') {
            $api->report((int) $matches[1]);
            return;
        }
        if ($path === '/api/system/reboot-and-restore/status' && $method === 'GET') {
            $api->rebootAutomationStatus();
            return;
        }
        if ($path === '/api/system/reboot-and-restore' && $method === 'POST') {
            $api->rebootAndRestore($request);
            return;
        }
        if ($path === '/api/system/reboot-and-restore/log' && $method === 'GET') {
            $api->rebootDeployLog();
            return;
        }
        if ($path === '/api/deploy/status' && $method === 'GET') {
            $api->deployStatus();
            return;
        }
        if (preg_match('#^/api/projects/(\d+)/deploy/latest$#', $path, $matches) && $method === 'POST') {
            $api->deployLatest((int) $matches[1]);
            return;
        }
        if (preg_match('#^/api/projects/(\d+)/deploy/stable$#', $path, $matches) && $method === 'POST') {
            $api->deployStable((int) $matches[1]);
            return;
        }
        if (preg_match('#^/api/projects/(\d+)/deploy/versions/(\d+)$#', $path, $matches) && $method === 'POST') {
            $api->deployVersion((int) $matches[1], (int) $matches[2]);
            return;
        }
        if (preg_match('#^/api/projects/(\d+)/histories$#', $path, $matches)) {
            $api->histories($request, (int) $matches[1]);
            return;
        }
        if (preg_match('#^/api/histories/(\d+)$#', $path, $matches)) {
            $api->history($request, (int) $matches[1]);
            return;
        }

        Response::json(['message' => 'Not found.'], 404);
    }
}
