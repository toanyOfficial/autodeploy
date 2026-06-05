<?php

namespace App\Models;

use App\Core\Model;

final class DeployHistory extends Model
{
    public const TABLE = 'deploy_history';
    public const PRIMARY_KEY = 'id';

    public const STATUSES = ['running', 'success', 'failed'];

    public const COLUMNS = [
        'id',
        'project_id',
        'deploy_version_id',
        'deploy_status',
        'requested_commit_hash',
        'deployed_commit_hash',
        'started_at',
        'ended_at',
        'report_file',
        'created_at',
    ];
}
