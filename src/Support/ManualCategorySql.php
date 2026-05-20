<?php

declare(strict_types=1);

namespace DriveMaster\Support;

final class ManualCategorySql
{
    public static function assignmentMatchesEntry(string $entryAlias = 'f', string $manualAlias = 'm'): string
    {
        return "({$manualAlias}.file_entry_id = {$entryAlias}.id OR ({$manualAlias}.assignment_type = 'folder' AND {$manualAlias}.drive_id = {$entryAlias}.drive_id AND {$manualAlias}.path_prefix IS NOT NULL AND ({$entryAlias}.relative_path = {$manualAlias}.path_prefix OR {$entryAlias}.relative_path LIKE CONCAT({$manualAlias}.path_prefix, '/%'))))";
    }

    public static function existsCondition(string $entryAlias = 'f', string $manualAlias = 'm'): string
    {
        return "EXISTS (SELECT 1 FROM category_manual_assignments {$manualAlias} WHERE " . self::assignmentMatchesEntry($entryAlias, $manualAlias) . ')';
    }

    public static function categoryExistsCondition(string $entryAlias = 'f', string $manualAlias = 'm'): string
    {
        return "EXISTS (SELECT 1 FROM category_manual_assignments {$manualAlias} WHERE {$manualAlias}.category_id = ? AND " . self::assignmentMatchesEntry($entryAlias, $manualAlias) . ')';
    }

    public static function unassignedCondition(string $entryAlias = 'f', string $manualAlias = 'm'): string
    {
        return 'NOT ' . self::existsCondition($entryAlias, $manualAlias);
    }
}
