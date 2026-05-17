<?php

declare(strict_types=1);

namespace DriveMaster\Repository;

use PDO;

final class DriveRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        return $this->db->query('SELECT * FROM drives ORDER BY friendly_name')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM drives WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findBySerial(string $serialNumber): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM drives WHERE serial_number = ?');
        $stmt->execute([$serialNumber]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function upsert(array $data): int
    {
        $existing = $this->findBySerial($data['serial_number']);

        if ($existing) {
            $stmt = $this->db->prepare('UPDATE drives SET friendly_name = ?, volume_label = ?, filesystem = ?, mount_path = ?, total_bytes = ?, free_bytes = ?, used_bytes = ?, notes = ?, is_active = ?, last_seen_at = NOW() WHERE id = ?');
            $stmt->execute([
                $data['friendly_name'],
                $data['volume_label'] ?? null,
                $data['filesystem'] ?? null,
                $data['mount_path'] ?? null,
                $data['total_bytes'] ?? 0,
                $data['free_bytes'] ?? 0,
                $data['used_bytes'] ?? 0,
                $data['notes'] ?? null,
                $data['is_active'] ?? 1,
                $existing['id'],
            ]);
            return (int)$existing['id'];
        }

        $stmt = $this->db->prepare('INSERT INTO drives (friendly_name, serial_number, volume_label, filesystem, mount_path, total_bytes, free_bytes, used_bytes, notes, is_active, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $data['friendly_name'],
            $data['serial_number'],
            $data['volume_label'] ?? null,
            $data['filesystem'] ?? null,
            $data['mount_path'] ?? null,
            $data['total_bytes'] ?? 0,
            $data['free_bytes'] ?? 0,
            $data['used_bytes'] ?? 0,
            $data['notes'] ?? null,
            $data['is_active'] ?? 1,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function updateManual(int $id, array $data): void
    {
        $stmt = $this->db->prepare('UPDATE drives SET friendly_name = ?, serial_number = ?, volume_label = ?, filesystem = ?, mount_path = ?, total_bytes = ?, free_bytes = ?, used_bytes = ?, notes = ?, is_active = ? WHERE id = ?');
        $stmt->execute([
            $data['friendly_name'],
            $data['serial_number'],
            $data['volume_label'] ?: null,
            $data['filesystem'] ?: null,
            $data['mount_path'] ?: null,
            (int)$data['total_bytes'],
            (int)$data['free_bytes'],
            (int)$data['used_bytes'],
            $data['notes'] ?: null,
            isset($data['is_active']) ? 1 : 0,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM drives WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function markScanned(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE drives SET last_scanned_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }
}
