<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Support\CategoryMatchSql;

header('Content-Type: application/json; charset=utf-8');

try {
    $idsRaw = trim((string)($_GET['ids'] ?? ''));
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        echo json_encode(['ok' => true, 'colors' => []], JSON_THROW_ON_ERROR);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $matchSql = CategoryMatchSql::matchSql();
    $stmt = db()->prepare("SELECT cm.file_entry_id, cm.category_name, cm.color_hex FROM ({$matchSql}) cm WHERE cm.file_entry_id IN ({$placeholders}) ORDER BY cm.category_name");
    $stmt->execute($ids);

    $colors = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['file_entry_id'];
        $color = (string)($row['color_hex'] ?? '');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            continue;
        }
        $colors[$id][] = [
            'name' => (string)$row['category_name'],
            'color' => strtolower($color),
        ];
    }

    echo json_encode(['ok' => true, 'colors' => $colors], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
