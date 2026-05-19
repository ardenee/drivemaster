<?php

declare(strict_types=1);

namespace DriveMaster\Support;

final class CategoryMatchSql
{
    public static function matchSql(): string
    {
        $visible = IgnoredFolderSql::excludeCondition('f');
        return "
            SELECT category_id, category_name, color_hex, file_entry_id, drive_id, entry_type, size_bytes, MAX(is_manual) AS is_manual, MAX(is_rule) AS is_rule
            FROM (
                SELECT cma.category_id, c.name AS category_name, c.color_hex, f.id AS file_entry_id, f.drive_id, f.entry_type, f.size_bytes, 1 AS is_manual, 0 AS is_rule
                FROM category_manual_assignments cma
                JOIN categories c ON c.id = cma.category_id AND c.is_active = 1
                JOIN file_entries f ON f.is_missing = 0
                    AND {$visible}
                    AND (cma.file_entry_id = f.id OR (cma.assignment_type = 'folder' AND cma.drive_id = f.drive_id AND cma.path_prefix IS NOT NULL AND (f.relative_path = cma.path_prefix OR f.relative_path LIKE CONCAT(cma.path_prefix, '/%'))))
                UNION ALL
                SELECT cr.category_id, c.name AS category_name, c.color_hex, f.id AS file_entry_id, f.drive_id, f.entry_type, f.size_bytes, 0 AS is_manual, 1 AS is_rule
                FROM category_rules cr
                JOIN categories c ON c.id = cr.category_id AND c.is_active = 1
                JOIN file_entries f ON f.is_missing = 0
                    AND {$visible}
                    AND cr.is_enabled = 1
                    AND (cr.drive_id IS NULL OR cr.drive_id = f.drive_id)
                    AND ((cr.rule_type = 'extension' AND cr.pattern = f.extension) OR (cr.rule_type = 'folder_path' AND (f.relative_path = cr.pattern OR f.relative_path LIKE CONCAT(cr.pattern, '/%'))) OR (cr.rule_type = 'path_contains' AND f.relative_path LIKE CONCAT('%', cr.pattern, '%')) OR (cr.rule_type = 'name_contains' AND f.name LIKE CONCAT('%', cr.pattern, '%')))
            ) raw_matches
            GROUP BY category_id, category_name, color_hex, file_entry_id, drive_id, entry_type, size_bytes
        ";
    }

    public static function activeConflictSql(): string
    {
        $matchSql = self::matchSql();
        return "SELECT x.file_entry_id FROM (SELECT file_entry_id FROM ({$matchSql}) cm GROUP BY file_entry_id HAVING COUNT(DISTINCT category_id) > 1) x LEFT JOIN category_conflict_ignores cci ON cci.file_entry_id = x.file_entry_id WHERE cci.id IS NULL";
    }

    public static function acceptedConflictSql(): string
    {
        $matchSql = self::matchSql();
        return "SELECT x.file_entry_id FROM (SELECT file_entry_id FROM ({$matchSql}) cm GROUP BY file_entry_id HAVING COUNT(DISTINCT category_id) > 1) x JOIN category_conflict_ignores cci ON cci.file_entry_id = x.file_entry_id";
    }
}
