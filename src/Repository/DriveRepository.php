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
            $stmt = $this->db->prepare('UPDATE drives SET friendly_name = ?, hardware_serial = ?, filesystem_uuid = ?, volume_label = ?, model = ?, vendor = ?, filesystem = ?, mount_path = ?, device_name = ?, identity_source = ?, total_bytes = ?, free_bytes = ?, used_bytes = ?, notes = ?, is_active = ?, last_seen_at = NOW() WHERE id = ?');
            $stmt->execute([
                $data['friendly_name'],
                $data['hardware_serial'] ?? null,
                $data['filesystem_uuid'] ?? null,
                $data['volume_label'] ?? null,
                $data['model'] ?? null,
                $data['vendor'] ?? null,
                $data['filesystem'] ?? null,
                $data['mount_path'] ?? null,
                $data['device_name'] ?? null,
                $data['identity_source'] ?? null,
                $data['total_bytes'] ?? 0,
                $data['free_bytes'] ?? 0,
                $data['used_bytes'] ?? 0,
                $data['notes'] ?? $existing['notes'] ?? null,
                $data['is_active'] ?? 1,
                $existing['id'],
            ]);
            return (int)$existing['id'];
        }

        $stmt = $this->db->prepare('INSERT INTO drives (friendly_name, serial_number, hardware_serial, filesystem_uuid, volume_label, model, vendor, filesystem, mount_path, device_name, identity_source, total_bytes, free_bytes, used_bytes, notes, is_active, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $data['friendly_name'],
            $data['serial_number'],
            $data['hardware_serial'] ?? null,
            $data['filesystem_uuid'] ?? null,
            $data['volume_label'] ?? null,
            $data['model'] ?? null,
            $data['vendor'] ?? null,
            $data['filesystem'] ?? null,
            $data['mount_path'] ?? null,
            $data['device_name'] ?? null,
            $data['identity_source'] ?? null,
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
        $stmt = $this->db->prepare('UPDATE drives SET friendly_name = ?, notes = ?, is_active = ? WHERE id = ?');
        $stmt->execute([
            $data['friendly_name'],
            $data['notes'] ?: null,
            isset($data['is_active']) ? 1 : 0,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $drive = $this->find($id);
        if (!$drive) {
            return;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM jobs WHERE job_type = 'scan_drive' AND (JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.serial_number')) = ? OR JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.path')) = ?)");
            $stmt->execute([$drive['serial_number'], $drive['mount_path']]);

            $stmt = $this->db->prepare('DELETE FROM drives WHERE id = ?');
            $stmt->execute([$id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function markScanned(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE drives SET last_scanned_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }
}
