<?php

namespace App\Repositories;

use App\Models\DeployHistory;

final class DeployHistoryRepository extends BaseRepository
{
    public function byProject(int $projectId, ?int $limit = null): array
    {
        $sql = 'SELECT h.*, v.version_name, p.project_name, p.project_key'
            . ' FROM ' . DeployHistory::TABLE . ' h'
            . ' LEFT JOIN deploy_version v ON v.id = h.deploy_version_id'
            . ' INNER JOIN deploy_project p ON p.id = h.project_id'
            . ' WHERE h.project_id = :project_id'
            . ' ORDER BY COALESCE(h.ended_at, h.started_at, h.created_at) DESC, h.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        return $this->fetchAll($sql, ['project_id' => $projectId]);
    }

    public function latestSuccessByProject(int $projectId): ?array
    {
        return $this->fetchOne(
            'SELECT h.*, v.version_name, COALESCE(h.deployed_commit_hash, v.git_commit_hash) AS current_commit_hash'
            . ' FROM ' . DeployHistory::TABLE . ' h'
            . ' LEFT JOIN deploy_version v ON v.id = h.deploy_version_id'
            . ' WHERE h.project_id = :project_id AND h.deploy_status = \'success\''
            . ' ORDER BY COALESCE(h.ended_at, h.created_at) DESC, h.id DESC LIMIT 1',
            ['project_id' => $projectId]
        );
    }

    public function hasRunning(): bool
    {
        $row = $this->fetchOne(
            "SELECT id FROM " . DeployHistory::TABLE . " WHERE deploy_status = 'running' ORDER BY id DESC LIMIT 1"
        );

        return $row !== null;
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ' . DeployHistory::TABLE . ' WHERE id = :id', ['id' => $id]);
    }

    public function failStaleRunning(int $olderThanSeconds): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $olderThanSeconds);
        $endedAt = date('Y-m-d H:i:s');

        return $this->execute(
            "UPDATE " . DeployHistory::TABLE
            . " SET deploy_status = 'failed', ended_at = :ended_at"
            . " WHERE deploy_status = 'running' AND (started_at IS NULL OR started_at < :cutoff)",
            [
                'ended_at' => $endedAt,
                'cutoff' => $cutoff,
            ]
        );
    }


    public function findWithProject(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT h.*, v.version_name, p.project_name, p.project_key, p.server_path, p.port, p.runtime_type'
            . ' FROM ' . DeployHistory::TABLE . ' h'
            . ' LEFT JOIN deploy_version v ON v.id = h.deploy_version_id'
            . ' INNER JOIN deploy_project p ON p.id = h.project_id'
            . ' WHERE h.id = :id',
            ['id' => $id]
        );
    }

    public function create(int $projectId, array $data): array
    {
        $status = $data['deploy_status'] ?? 'running';
        if (!in_array($status, DeployHistory::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid deploy_status value.');
        }

        $this->execute(
            'INSERT INTO ' . DeployHistory::TABLE
            . ' (project_id, deploy_version_id, deploy_status, requested_commit_hash, deployed_commit_hash, started_at, ended_at, report_file)'
            . ' VALUES (:project_id, :deploy_version_id, :deploy_status, :requested_commit_hash, :deployed_commit_hash, :started_at, :ended_at, :report_file)',
            [
                'project_id' => $projectId,
                'deploy_version_id' => $data['deploy_version_id'] ?? null,
                'deploy_status' => $status,
                'requested_commit_hash' => $data['requested_commit_hash'] ?? null,
                'deployed_commit_hash' => $data['deployed_commit_hash'] ?? null,
                'started_at' => $data['started_at'] ?? null,
                'ended_at' => $data['ended_at'] ?? null,
                'report_file' => $data['report_file'] ?? null,
            ]
        );

        return $this->find((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $current = $this->find($id);
        if ($current === null) {
            return null;
        }

        $status = $data['deploy_status'] ?? $current['deploy_status'];
        if (!in_array($status, DeployHistory::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid deploy_status value.');
        }

        $this->execute(
            'UPDATE ' . DeployHistory::TABLE
            . ' SET deploy_version_id = :deploy_version_id, deploy_status = :deploy_status,'
            . ' requested_commit_hash = :requested_commit_hash, deployed_commit_hash = :deployed_commit_hash,'
            . ' started_at = :started_at, ended_at = :ended_at, report_file = :report_file'
            . ' WHERE id = :id',
            [
                'id' => $id,
                'deploy_version_id' => $data['deploy_version_id'] ?? $current['deploy_version_id'],
                'deploy_status' => $status,
                'requested_commit_hash' => $data['requested_commit_hash'] ?? $current['requested_commit_hash'],
                'deployed_commit_hash' => $data['deployed_commit_hash'] ?? $current['deployed_commit_hash'],
                'started_at' => $data['started_at'] ?? $current['started_at'],
                'ended_at' => $data['ended_at'] ?? $current['ended_at'],
                'report_file' => $data['report_file'] ?? $current['report_file'],
            ]
        );

        return $this->find($id);
    }
}
