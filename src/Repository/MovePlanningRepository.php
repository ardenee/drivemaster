<?php

declare(strict_types=1);

namespace DriveMaster\Repository;

use DriveMaster\Support\IgnoredFolderSql;
use DriveMaster\Support\MovePlanningSafety;
use PDO;

final class MovePlanningRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function blockerSummary(): array
    {
        $visibleSql = IgnoredFolderSql::excludeCondition('f');
        $effectiveSql = MovePlanningSafety::effectiveCategorySql();
        $targetExistsSql = MovePlanningSafety::targetExistsSql('ec');
        $configuredCurrentSql = MovePlanningSafety::configuredTargetSql('f', 'ec');
        $activeConflictSql = MovePlanningSafety::activeConflictSql();
        $acceptedConflictSql = MovePlanningSafety::acceptedConflictSql();

        $sql = "
            SELECT reason, COUNT(*) AS item_count, COALESCE(SUM(CASE WHEN entry_type = 'file' THEN size_bytes ELSE 0 END), 0) AS total_size
            FROM (
                SELECT f.id, f.entry_type, f.size_bytes,
                    CASE
                        WHEN ec.file_entry_id IS NULL THEN 'no_category'
                        WHEN ec.category_count > 1 THEN 'multiple_categories'
                        WHEN ac.file_entry_id IS NOT NULL THEN 'active_conflict'
                        WHEN ic.file_entry_id IS NOT NULL THEN 'accepted_conflict'
                        WHEN NOT {$targetExistsSql} THEN 'category_has_no_target_drive'
                        WHEN {$configuredCurrentSql} THEN 'already_on_target_drive'
                        ELSE 'safe_candidate'
                    END AS reason
                FROM file_entries f
                LEFT JOIN ({$effectiveSql}) ec ON ec.file_entry_id = f.id
                LEFT JOIN ({$activeConflictSql}) ac ON ac.file_entry_id = f.id
                LEFT JOIN ({$acceptedConflictSql}) ic ON ic.file_entry_id = f.id
                WHERE f.is_missing = 0 AND {$visibleSql}
            ) x
            GROUP BY reason
            ORDER BY reason
        ";
        $rows = [];
        foreach ($this->db->query($sql)->fetchAll() as $row) {
            $rows[(string)$row['reason']] = [
                'reason' => (string)$row['reason'],
                'item_count' => (int)($row['item_count'] ?? 0),
                'total_size' => (int)($row['total_size'] ?? 0),
            ];
        }
        foreach (['safe_candidate', 'no_category', 'multiple_categories', 'active_conflict', 'accepted_conflict', 'category_has_no_target_drive', 'already_on_target_drive'] as $reason) {
            $rows[$reason] ??= ['reason' => $reason, 'item_count' => 0, 'total_size' => 0];
        }
        return $rows;
    }

    public function candidateSummaryByCategory(bool $useCategoryTargets, array $selectedCategoryIds = []): array
    {
        $effectiveSql = MovePlanningSafety::effectiveCategorySql();
        $activeConflictSql = MovePlanningSafety::activeConflictSql();
        $acceptedConflictSql = MovePlanningSafety::acceptedConflictSql();
        $targetExistsSql = MovePlanningSafety::targetExistsSql('ec');
        $configuredCurrentSql = MovePlanningSafety::configuredTargetSql('f', 'ec');
        $where = ["ec.category_count = 1", "f.is_missing = 0", "f.entry_type = 'file'", "ac.file_entry_id IS NULL", "ic.file_entry_id IS NULL", $targetExistsSql, "NOT {$configuredCurrentSql}"];
        $params = [];
        if ($selectedCategoryIds) {
            $where[] = 'ec.category_id IN (' . implode(',', array_fill(0, count($selectedCategoryIds), '?')) . ')';
            $params = array_values($selectedCategoryIds);
        }
        if ($useCategoryTargets) {
            $where[] = 'EXISTS (SELECT 1 FROM category_drive_targets cdt WHERE cdt.category_id = ec.category_id AND cdt.is_enabled = 1)';
        }
        $stmt = $this->db->prepare("SELECT ec.category_id, ec.category_name, ec.color_hex, COUNT(f.id) AS file_count, COALESCE(SUM(f.size_bytes), 0) AS move_size FROM ({$effectiveSql}) ec JOIN file_entries f ON f.id = ec.file_entry_id LEFT JOIN ({$activeConflictSql}) ac ON ac.file_entry_id = f.id LEFT JOIN ({$acceptedConflictSql}) ic ON ic.file_entry_id = f.id WHERE " . implode(' AND ', $where) . " GROUP BY ec.category_id, ec.category_name, ec.color_hex ORDER BY move_size DESC, ec.category_name");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
