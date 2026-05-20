<?php

declare(strict_types=1);

namespace DriveMaster\Support;

final class MovePlanningSafety
{
    public static function effectiveCategorySql(): string
    {
        $matchSql = CategoryMatchSql::matchSql();
        return "
            SELECT m.file_entry_id,
                COUNT(DISTINCT m.category_id) AS category_count,
                MIN(m.category_id) AS category_id,
                MAX(m.category_name) AS category_name,
                MAX(m.color_hex) AS color_hex,
                MAX(m.is_manual) AS is_manual,
                MAX(m.is_rule) AS is_rule
            FROM ({$matchSql}) m
            GROUP BY m.file_entry_id
        ";
    }

    public static function activeConflictSql(): string
    {
        return CategoryMatchSql::activeConflictSql();
    }

    public static function acceptedConflictSql(): string
    {
        return CategoryMatchSql::acceptedConflictSql();
    }

    public static function configuredTargetSql(string $entryAlias = 'f', string $effectiveAlias = 'ec'): string
    {
        return "EXISTS (SELECT 1 FROM category_drive_targets cdt WHERE cdt.category_id = {$effectiveAlias}.category_id AND cdt.drive_id = {$entryAlias}.drive_id AND cdt.is_enabled = 1)";
    }

    public static function targetExistsSql(string $effectiveAlias = 'ec'): string
    {
        return "EXISTS (SELECT 1 FROM category_drive_targets cdt JOIN drives td ON td.id = cdt.drive_id AND td.is_active = 1 WHERE cdt.category_id = {$effectiveAlias}.category_id AND cdt.is_enabled = 1)";
    }

    public static function safeCandidateWhereSql(string $entryAlias = 'f', string $effectiveAlias = 'ec', string $targetDriveAlias = 'td'): string
    {
        return "{$entryAlias}.is_missing = 0 AND {$entryAlias}.entry_type = 'file' AND {$effectiveAlias}.category_count = 1 AND {$targetDriveAlias}.id IS NOT NULL AND {$targetDriveAlias}.is_active = 1 AND {$entryAlias}.drive_id <> {$targetDriveAlias}.id AND EXISTS (SELECT 1 FROM category_drive_targets cdt WHERE cdt.category_id = {$effectiveAlias}.category_id AND cdt.drive_id = {$targetDriveAlias}.id AND cdt.is_enabled = 1)";
    }
}
