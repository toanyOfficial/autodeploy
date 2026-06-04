<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\DeployProjectRepository;

final class ProjectController
{
    public function store(Request $request): void
    {
        (new DeployProjectRepository())->create($request->input());
        Response::redirect('/dashboard');
    }

    public function update(Request $request, int $id): void
    {
        (new DeployProjectRepository())->update($id, $request->input());
        Response::redirect('/dashboard');
    }

    public function deactivate(int $id): void
    {
        (new DeployProjectRepository())->deactivate($id);
        Response::redirect('/dashboard');
    }
}
