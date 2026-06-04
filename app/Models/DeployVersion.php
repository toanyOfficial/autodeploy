<?php

namespace App\Models;

use App\Core\Model;

final class DeployVersion extends Model
{
    public const TABLE = 'deploy_version';
    public const PRIMARY_KEY = 'id';

    public const COLUMNS = [
        'id',
        'project_id',
        'version_name',
        'git_commit_hash',
        'memo',
        'is_stable',
        'is_active',
        'created_at',
    ];
}
