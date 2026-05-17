<?php

declare(strict_types=1);

namespace DriveMaster\Repository;

use PDO;

final class JobRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(string $jobType, array $payload): int
    {
        $stmt = $this->db->prepare('INSERT INTO jobs (job_type, payload_json, progress_message) VALUES (?, ?, ?)');
        $stmt->execute([$jobType, json_encode($payload, JSON_THROW_ON_ERROR), 'Queued']);
        return (int)$this->db->lastInsertId();
    }

    public function all(int $limit = 100): array
    {
        $stmt = $this->db->prepare('SELECT * FROM jobs ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM jobs WHERE id = ?');
        $stmt->execute([$id]);
        $job = $stmt->fetch();
        return $job ?: null;
    }

    public function claimNext(string $workerId): ?array
    {
        $this->db->beginTransaction();

        $stmt = $this->db->query("SELECT * FROM jobs WHERE status = 'queued' ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $job = $stmt->fetch();

        if (!$job) {
            $this->db->commit();
            return null;
        }

        $update = $this->db->prepare("UPDATE jobs SET status = 'running', locked_at = NOW(), locked_by = ?, started_at = COALESCE(started_at, NOW()), progress_message = 'Starting' WHERE id = ?");
        $update->execute([$workerId, $job['id']]);

        $this->db->commit();

        return $this->find((int)$job['id']);
    }

    public function progress(int $id, int $current, ?int $total, string $message): void
    {
        $stmt = $this->db->prepare('UPDATE jobs SET progress_current = ?, progress_total = ?, progress_message = ? WHERE id = ?');
        $stmt->execute([$current, $total, $message, $id]);
    }

    public function complete(int $id, array $result = []): void
    {
        $stmt = $this->db->prepare("UPDATE jobs SET status = 'complete', result_json = ?, progress_message = 'Complete', finished_at = NOW() WHERE id = ?");
        $stmt->execute([json_encode($result, JSON_THROW_ON_ERROR), $id]);
    }

    public function fail(int $id, string $message): void
    {
        $stmt = $this->db->prepare("UPDATE jobs SET status = 'failed', error_message = ?, progress_message = 'Failed', finished_at = NOW() WHERE id = ?");
        $stmt->execute([$message, $id]);
    }
}
