<?php

declare(strict_types=1);

namespace DriveMaster\Support;

use PDO;

final class IgnoredFolderSql
{
    public static function names(PDO $db): array
    {
        try {
            $rows = $db->query('SELECT folder_name FROM ignored_folder_names WHERE is_enabled = 1 ORDER BY folder_name')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            $rows = ['@eaDir', '@tmp', '$RECYCLE.BIN', 'System Volume Information'];
        }
        return array_values(array_filter(array_map('strval', $rows), static fn(string $name): bool => trim($name) !== ''));
    }

    public static function excludeCondition(string $alias = 'f'): string
    {
        return "NOT EXISTS (SELECT 1 FROM ignored_folder_names ifn WHERE ifn.is_enabled = 1 AND ({$alias}.name = ifn.folder_name OR {$alias}.relative_path = ifn.folder_name OR {$alias}.relative_path LIKE CONCAT(ifn.folder_name, '/%') OR {$alias}.relative_path LIKE CONCAT('%/', ifn.folder_name, '/%') OR {$alias}.relative_path LIKE CONCAT('%/', ifn.folder_name)))";
    }
}
