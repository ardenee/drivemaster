<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $driveId = (int)($_GET['drive_id'] ?? 0);
    $path = trim((string)($_GET['path'] ?? ''), '/');

    if ($driveId < 1 || $path === '') {
        throw new RuntimeException('Invalid request.');
    }

    $stmt = db()->prepare("SELECT id, name FROM file_entries WHERE drive_id = ? AND relative_path = ? AND entry_type = 'folder' AND is_missing = 0 LIMIT 1");
    $stmt->execute([$driveId, $path]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Folder not found.');
    }

    echo json_encode(['ok' => true, 'id' => (int)$row['id'], 'name' => (string)$row['name']], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
