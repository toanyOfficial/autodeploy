<?php

namespace App\Models;

use App\Core\Model;

final class DeployProject extends Model
{
    public const TABLE = 'deploy_project';
    public const PRIMARY_KEY = 'id';

    public const COLUMNS = [
        'id',
        'project_key',
        'project_name',
        'server_path',
        'port',
        'runtime_type',
        'branch_name',
        'is_active',
        'created_at',
        'updated_at',
    ];
}
