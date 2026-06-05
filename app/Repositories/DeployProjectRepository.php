<?php

namespace App\Repositories;

use App\Models\DeployProject;

final class DeployProjectRepository extends BaseRepository
{
    public function all(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE is_active = 1' : '';

        return $this->fetchAll(
            "SELECT * FROM " . DeployProject::TABLE . " {$where} ORDER BY created_at DESC, id DESC"
        );
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ' . DeployProject::TABLE . ' WHERE id = :id', ['id' => $id]);
    }

    public function create(array $data): array
    {
        $sql = 'INSERT INTO ' . DeployProject::TABLE . ' (project_key, project_name, server_path, port, runtime_type, branch_name, is_active)'
            . ' VALUES (:project_key, :project_name, :server_path, :port, :runtime_type, :branch_name, :is_active)';

        $this->execute($sql, [
            'project_key' => $data['project_key'],
            'project_name' => $data['project_name'],
            'server_path' => $data['server_path'],
            'port' => (int) $data['port'],
            'runtime_type' => $data['runtime_type'],
            'branch_name' => 'main',
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
        ]);

        return $this->find((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): ?array
    {
        $current = $this->find($id);
        if ($current === null) {
            return null;
        }

        $this->execute(
            'UPDATE ' . DeployProject::TABLE
            . ' SET project_key = :project_key, project_name = :project_name, server_path = :server_path,'
            . ' port = :port, runtime_type = :runtime_type, branch_name = :branch_name, is_active = :is_active'
            . ' WHERE id = :id',
            [
                'id' => $id,
                'project_key' => $data['project_key'] ?? $current['project_key'],
                'project_name' => $data['project_name'] ?? $current['project_name'],
                'server_path' => $data['server_path'] ?? $current['server_path'],
                'port' => isset($data['port']) ? (int) $data['port'] : (int) $current['port'],
                'runtime_type' => $data['runtime_type'] ?? $current['runtime_type'],
                'branch_name' => 'main',
                'is_active' => array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : (int) $current['is_active'],
            ]
        );

        return $this->find($id);
    }

    public function deactivate(int $id): ?array
    {
        $project = $this->find($id);
        if ($project === null) {
            return null;
        }

        $this->execute('UPDATE ' . DeployProject::TABLE . ' SET is_active = 0 WHERE id = :id', ['id' => $id]);

        return $this->find($id);
    }
}
