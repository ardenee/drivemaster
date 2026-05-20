<?php

declare(strict_types=1);

namespace DriveMaster\Repository;

use DriveMaster\Support\IgnoredFolderSql;
use DriveMaster\Support\ManualCategorySql;
use PDO;

final class CategoryWorkflowRepository
{
    public const PAGE_SIZE = 495;
    public const TREE_CHILD_LIMIT = 1000;

    public function __construct(private readonly PDO $db)
    {
    }

    public function childFolders(int $driveId, string $parentPath, int $limit = self::TREE_CHILD_LIMIT): array
    {
        $visibleSql = IgnoredFolderSql::excludeCondition('f');
        $unassignedSql = ManualCategorySql::unassignedCondition('f');
        $stmt = $this->db->prepare("SELECT f.id, f.name, f.relative_path FROM file_entries f WHERE f.drive_id = ? AND f.is_missing = 0 AND f.entry_type = 'folder' AND {$visibleSql} AND {$unassignedSql} AND f.parent_path = ? ORDER BY f.name ASC LIMIT {$limit}");
        $stmt->execute([$driveId, trim($parentPath, '/')]);
        return $stmt->fetchAll();
    }

    public function folderSummary(int $driveId, string $parentPath): array
    {
        $visibleSql = IgnoredFolderSql::excludeCondition('f');
        $unassignedSql = ManualCategorySql::unassignedCondition('f');
        $stmt = $this->db->prepare("SELECT COUNT(*) AS total_items, SUM(f.entry_type = 'file') AS file_count, SUM(f.entry_type = 'folder') AS folder_count, COALESCE(SUM(CASE WHEN f.entry_type = 'file' THEN f.size_bytes ELSE 0 END), 0) AS total_size FROM file_entries f WHERE f.drive_id = ? AND f.is_missing = 0 AND {$visibleSql} AND {$unassignedSql} AND f.parent_path = ?");
        $stmt->execute([$driveId, trim($parentPath, '/')]);
        $row = $stmt->fetch() ?: [];
        return [
            'total_items' => (int)($row['total_items'] ?? 0),
            'file_count' => (int)($row['file_count'] ?? 0),
            'folder_count' => (int)($row['folder_count'] ?? 0),
            'total_size' => (int)($row['total_size'] ?? 0),
        ];
    }

    public function folderChildren(int $driveId, string $parentPath, string $sort, string $dir, int $page, int $pageSize = self::PAGE_SIZE): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $pageSize;
        $dir = strtolower($dir) === 'desc' ? 'desc' : 'asc';
        $sortSql = match ($sort) {
            'type' => "CASE WHEN f.entry_type = 'folder' THEN 'Folder' ELSE COALESCE(f.extension, '') END",
            'size' => 'f.size_bytes',
            'modified' => 'f.modified_at',
            default => 'f.name',
        };
        $visibleSql = IgnoredFolderSql::excludeCondition('f');
        $unassignedSql = ManualCategorySql::unassignedCondition('f');
        $stmt = $this->db->prepare("SELECT f.id, f.drive_id, f.relative_path, f.name, f.entry_type, f.extension, f.size_bytes, f.modified_at FROM file_entries f WHERE f.drive_id = ? AND f.is_missing = 0 AND {$visibleSql} AND {$unassignedSql} AND f.parent_path = ? ORDER BY CASE WHEN f.entry_type = 'folder' THEN 0 ELSE 1 END ASC, {$sortSql} {$dir}, f.name ASC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $driveId, PDO::PARAM_INT);
        $stmt->bindValue(2, trim($parentPath, '/'));
        $stmt->bindValue(3, $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(4, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function driveCoverageSummary(): array
    {
        $visibleSql = IgnoredFolderSql::excludeCondition('f');

        $rows = [];
        $totals = $this->db->query("SELECT d.id AS drive_id, d.friendly_name, COUNT(f.id) AS total_items, COALESCE(SUM(f.entry_type = 'file'), 0) AS total_files, COALESCE(SUM(f.entry_type = 'folder'), 0) AS total_folders FROM drives d LEFT JOIN file_entries f ON f.drive_id = d.id AND f.is_missing = 0 AND {$visibleSql} GROUP BY d.id, d.friendly_name ORDER BY d.friendly_name")->fetchAll();
        foreach ($totals as $row) {
            $driveId = (int)$row['drive_id'];
            $rows[$driveId] = [
                'drive_id' => $driveId,
                'friendly_name' => (string)$row['friendly_name'],
                'total_items' => (int)($row['total_items'] ?? 0),
                'total_files' => (int)($row['total_files'] ?? 0),
                'total_folders' => (int)($row['total_folders'] ?? 0),
                'assigned_files' => 0,
                'assigned_folders' => 0,
                'unassigned_files' => 0,
                'unassigned_folders' => 0,
            ];
        }

        $fileStmt = $this->db->query("SELECT f.drive_id, COUNT(DISTINCT f.id) AS assigned_files FROM category_manual_assignments cma JOIN file_entries f ON f.id = cma.file_entry_id AND f.is_missing = 0 AND f.entry_type = 'file' AND {$visibleSql} WHERE cma.assignment_type = 'file' AND cma.file_entry_id IS NOT NULL GROUP BY f.drive_id");
        foreach ($fileStmt->fetchAll() as $row) {
            $driveId = (int)$row['drive_id'];
            if (isset($rows[$driveId])) {
                $rows[$driveId]['assigned_files'] = (int)($row['assigned_files'] ?? 0);
            }
        }

        $folderStmt = $this->db->query("SELECT cma.drive_id, COUNT(DISTINCT cma.path_prefix) AS assigned_folders FROM category_manual_assignments cma JOIN file_entries f ON f.drive_id = cma.drive_id AND f.relative_path = cma.path_prefix AND f.is_missing = 0 AND f.entry_type = 'folder' AND {$visibleSql} WHERE cma.assignment_type = 'folder' AND cma.drive_id IS NOT NULL AND cma.path_prefix IS NOT NULL GROUP BY cma.drive_id");
        foreach ($folderStmt->fetchAll() as $row) {
            $driveId = (int)$row['drive_id'];
            if (isset($rows[$driveId])) {
                $rows[$driveId]['assigned_folders'] = (int)($row['assigned_folders'] ?? 0);
            }
        }

        foreach ($rows as &$row) {
            $row['unassigned_files'] = max(0, (int)$row['total_files'] - (int)$row['assigned_files']);
            $row['unassigned_folders'] = max(0, (int)$row['total_folders'] - (int)$row['assigned_folders']);
        }
        unset($row);

        return array_values($rows);
    }
}
