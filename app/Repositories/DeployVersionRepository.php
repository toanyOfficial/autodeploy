<?php

namespace App\Repositories;

use App\Models\DeployVersion;

final class DeployVersionRepository extends BaseRepository
{
    public function byProject(int $projectId, ?int $limit = null): array
    {
        $sql = 'SELECT v.*, MAX(h.ended_at) AS last_deployed_at'
            . ' FROM ' . DeployVersion::TABLE . ' v'
            . ' LEFT JOIN deploy_history h ON h.deploy_version_id = v.id AND h.deploy_status = \'success\''
            . ' WHERE v.project_id = :project_id AND v.is_active = 1'
            . ' GROUP BY v.id'
            . ' ORDER BY v.created_at DESC, v.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        return $this->fetchAll($sql, ['project_id' => $projectId]);
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ' . DeployVersion::TABLE . ' WHERE id = :id', ['id' => $id]);
    }

    public function findStableByProject(int $projectId): ?array
    {
        $versions = $this->fetchAll(
            'SELECT * FROM ' . DeployVersion::TABLE
            . ' WHERE project_id = :project_id AND is_stable = 1 AND is_active = 1'
            . ' ORDER BY id DESC LIMIT 2',
            ['project_id' => $projectId]
        );

        if (count($versions) > 1) {
            throw new \RuntimeException('안정화버전이 중복 등록되어 있습니다. DB/소스코드 상태를 확인해 주세요.');
        }

        return $versions[0] ?? null;
    }

    public function create(int $projectId, array $data): array
    {
        $isStable = $this->stableFlag($data['is_stable'] ?? null);

        $this->pdo->beginTransaction();
        try {
            if ($isStable) {
                $this->clearStableVersions($projectId);
            }

            $this->execute(
                'INSERT INTO ' . DeployVersion::TABLE
                . ' (project_id, version_name, git_commit_hash, memo, is_stable, is_active)'
                . ' VALUES (:project_id, :version_name, :git_commit_hash, :memo, :is_stable, :is_active)',
                [
                    'project_id' => $projectId,
                    'version_name' => $data['version_name'],
                    'git_commit_hash' => $data['git_commit_hash'] ?? null,
                    'memo' => $data['memo'] ?? null,
                    'is_stable' => $isStable ? 1 : 0,
                    'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
                ]
            );

            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }

        return $this->find($id);
    }

    public function update(int $id, array $data): ?array
    {
        $current = $this->find($id);
        if ($current === null) {
            return null;
        }

        $hasStableInput = array_key_exists('is_stable', $data);
        $isStable = $hasStableInput ? $this->stableFlag($data['is_stable']) : ((int) $current['is_stable'] === 1);

        $this->pdo->beginTransaction();
        try {
            if ($hasStableInput && $isStable) {
                $this->clearStableVersions((int) $current['project_id']);
            }

            $this->execute(
                'UPDATE ' . DeployVersion::TABLE
                . ' SET version_name = :version_name, git_commit_hash = :git_commit_hash, memo = :memo,'
                . ' is_stable = :is_stable, is_active = :is_active WHERE id = :id',
                [
                    'id' => $id,
                    'version_name' => $data['version_name'] ?? $current['version_name'],
                    'git_commit_hash' => $data['git_commit_hash'] ?? $current['git_commit_hash'],
                    'memo' => $data['memo'] ?? $current['memo'],
                    'is_stable' => $hasStableInput ? ($isStable ? 1 : 0) : (int) $current['is_stable'],
                    'is_active' => array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : (int) $current['is_active'],
                ]
            );

            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }

        return $this->find($id);
    }

    public function markStable(int $id): ?array
    {
        $version = $this->find($id);
        if ($version === null) {
            return null;
        }

        $this->pdo->beginTransaction();
        try {
            $this->clearStableVersions((int) $version['project_id']);
            $this->execute('UPDATE ' . DeployVersion::TABLE . ' SET is_stable = 1 WHERE id = :id', ['id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }

        return $this->find($id);
    }

    public function deactivate(int $id): ?array
    {
        $version = $this->find($id);
        if ($version === null) {
            return null;
        }

        $this->execute('UPDATE ' . DeployVersion::TABLE . ' SET is_active = 0, is_stable = 0 WHERE id = :id', ['id' => $id]);

        return $this->find($id);
    }

    private function clearStableVersions(int $projectId): void
    {
        $this->execute(
            'UPDATE ' . DeployVersion::TABLE . ' SET is_stable = 0 WHERE project_id = :project_id AND is_stable = 1',
            ['project_id' => $projectId]
        );
    }

    private function stableFlag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
