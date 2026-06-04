<?php

namespace App\Core;

use App\Controllers\ApiController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\ProjectController;
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
