<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $driveId = max(0, (int)($_GET['drive_id'] ?? 0));
    if ($driveId <= 0) {
        throw new RuntimeException('Missing drive ID.');
    }

    $stmt = db()->prepare('SELECT id, friendly_name, total_bytes, free_bytes, used_bytes FROM drives WHERE id = ?');
    $stmt->execute([$driveId]);
    $drive = $stmt->fetch();
    if (!$drive) {
        throw new RuntimeException('Drive not found.');
    }

    echo json_encode([
        'ok' => true,
        'drive' => [
            'id' => (int)$drive['id'],
            'friendly_name' => (string)$drive['friendly_name'],
            'total_bytes' => (int)$drive['total_bytes'],
            'free_bytes' => (int)$drive['free_bytes'],
            'used_bytes' => (int)$drive['used_bytes'],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
