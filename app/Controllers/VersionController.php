<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\DeployVersionRepository;

final class VersionController
{
    public function store(Request $request, int $projectId): void
    {
        (new DeployVersionRepository())->create($projectId, $request->input());
        Response::redirect('/dashboard');
    }

    public function update(Request $request, int $id): void
    {
        (new DeployVersionRepository())->update($id, $request->input());
        Response::redirect('/dashboard');
    }

    public function deactivate(int $id): void
    {
        (new DeployVersionRepository())->deactivate($id);
        Response::redirect('/dashboard');
    }
}
