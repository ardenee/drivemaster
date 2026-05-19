<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $rows = db()->query('SELECT folder_name FROM ignored_folder_names WHERE is_enabled = 1 ORDER BY folder_name')->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['ok' => true, 'folders' => array_values(array_map('strval', $rows))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => true, 'folders' => ['@eaDir', '@tmp', '$RECYCLE.BIN', 'System Volume Information']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
