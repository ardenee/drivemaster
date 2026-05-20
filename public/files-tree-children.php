<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Support\IgnoredFolderSql;

function ftc_link(array $params = []): string
{
    return 'files.php?' . http_build_query($params) . '#explorer-panel';
}

header('Content-Type: application/json; charset=utf-8');

try {
    $driveId = max(0, (int)($_GET['drive_id'] ?? 0));
    $parentPath = trim((string)($_GET['parent_path'] ?? ''), '/');
    $level = max(0, min(40, (int)($_GET['level'] ?? 0)));

    if ($driveId <= 0) {
        throw new RuntimeException('Missing drive ID.');
    }

    $visibleSql = IgnoredFolderSql::excludeCondition('f');
    $stmt = db()->prepare("SELECT id, name, relative_path FROM file_entries f WHERE drive_id = ? AND is_missing = 0 AND entry_type = 'folder' AND {$visibleSql} AND parent_path = ? ORDER BY name ASC LIMIT 1000");
    $stmt->execute([$driveId, $parentPath]);

    $html = '';
    foreach ($stmt->fetchAll() as $folder) {
        $folderPath = (string)$folder['relative_path'];
        $padding = 18 + (($level + 1) * 18);
        $url = ftc_link(['drive_id' => $driveId, 'path' => $folderPath, 'selected_entry_id' => null, 'explorer_page' => 1]);
        $html .= '<div class="tree-folder lazy-tree-folder selectable-row" style="padding-left: ' . e((string)$padding) . 'px" data-id="' . e((string)$folder['id']) . '" data-entry-type="folder" data-entry-name="' . e((string)$folder['name']) . '" data-size="0" data-is-file="0" data-drive-id="' . e((string)$driveId) . '" data-path="' . e($folderPath) . '" data-level="' . e((string)($level + 1)) . '" data-loaded="0" data-url="' . e($url) . '">';
        $html .= '<span class="tree-toggle">▸</span><span class="folder-icon">📁</span> ' . e((string)$folder['name']);
        $html .= '</div>';
    }

    echo json_encode(['ok' => true, 'html' => $html], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
