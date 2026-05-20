<?php

declare(strict_types=1);

namespace DriveMaster\Repository;

use DriveMaster\Support\MovePlanningSafety;
use PDO;

final class MoveCandidateRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    private function sqlParts(array $filters): array
    {
        $effectiveSql = MovePlanningSafety::effectiveCategorySql();
        $activeConflictSql = MovePlanningSafety::activeConflictSql();
        $acceptedConflictSql = MovePlanningSafety::acceptedConflictSql();
        $where = ['f.is_missing = 0', "f.entry_type = 'file'", 'ec.category_count = 1', 'td.id IS NOT NULL', 'td.is_active = 1', 'ac.file_entry_id IS NULL', 'ic.file_entry_id IS NULL', 'f.drive_id <> td.id'];
        $params = [];
        if (($filters['category_id'] ?? 0) > 0) {
            $where[] = 'ec.category_id = ?';
            $params[] = (int)$filters['category_id'];
        }
        if (($filters['target_drive_id'] ?? 0) > 0) {
            $where[] = 'td.id = ?';
            $params[] = (int)$filters['target_drive_id'];
        }
        if (($filters['current_drive_id'] ?? 0) > 0) {
            $where[] = 'f.drive_id = ?';
            $params[] = (int)$filters['current_drive_id'];
        }
        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(f.name LIKE ? OR f.relative_path LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $baseSql = "FROM ({$effectiveSql}) ec JOIN file_entries f ON f.id = ec.file_entry_id JOIN drives d ON d.id = f.drive_id JOIN category_drive_targets cdt ON cdt.category_id = ec.category_id AND cdt.is_enabled = 1 JOIN drives td ON td.id = cdt.drive_id LEFT JOIN ({$activeConflictSql}) ac ON ac.file_entry_id = f.id LEFT JOIN ({$acceptedConflictSql}) ic ON ic.file_entry_id = f.id WHERE " . implode(' AND ', $where);
        return [$baseSql, $params];
    }

    public function count(array $filters): int
    {
        [$baseSql, $params] = $this->sqlParts($filters);
        $stmt = $this->db->prepare("SELECT COUNT(*) {$baseSql}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function summary(array $filters): array
    {
        [$baseSql, $params] = $this->sqlParts($filters);
        $stmt = $this->db->prepare("SELECT COUNT(*) AS file_count, COALESCE(SUM(f.size_bytes), 0) AS total_size {$baseSql}");
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        return ['file_count' => (int)($row['file_count'] ?? 0), 'total_size' => (int)($row['total_size'] ?? 0)];
    }

    public function page(array $filters, string $sort, string $dir, int $limit, int $offset): array
    {
        [$baseSql, $params] = $this->sqlParts($filters);
        $dir = strtolower($dir) === 'desc' ? 'desc' : 'asc';
        $sortSql = match ($sort) {
            'drive' => 'current_drive_name',
            'name' => 'f.name',
            'type' => 'f.entry_type',
            'size' => 'f.size_bytes',
            'modified' => 'f.modified_at',
            default => 'f.relative_path',
        };
        $stmt = $this->db->prepare("SELECT f.id, f.drive_id, f.relative_path, f.name, f.extension, f.entry_type, f.size_bytes, f.modified_at, d.friendly_name AS current_drive_name, td.id AS target_drive_id, td.friendly_name AS target_drive_name, ec.category_id, ec.category_name, ec.color_hex, ec.is_manual, ec.is_rule {$baseSql} ORDER BY {$sortSql} {$dir}, f.relative_path ASC LIMIT ? OFFSET ?");
        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function unsavedBatch(int $planId, int $categoryId, int $targetDriveId, int $limit): array
    {
        [$baseSql, $params] = $this->sqlParts(['category_id' => $categoryId, 'target_drive_id' => $targetDriveId]);
        $sql = "SELECT f.id, f.drive_id, f.relative_path, f.entry_type, f.size_bytes, ec.category_id {$baseSql} AND NOT EXISTS (SELECT 1 FROM move_plan_items mpi WHERE mpi.move_plan_id = ? AND mpi.file_entry_id = f.id) ORDER BY f.id ASC LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $bind = 1;
        foreach ($params as $value) {
            $stmt->bindValue($bind++, $value);
        }
        $stmt->bindValue($bind++, $planId, PDO::PARAM_INT);
        $stmt->bindValue($bind, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
