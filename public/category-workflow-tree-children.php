<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Repository\CategoryWorkflowRepository;

function cwtc_link(array $params = []): string
{
    return 'category-workflow.php?' . http_build_query($params) . '#workflow-explorer';
}

header('Content-Type: application/json; charset=utf-8');

try {
    $driveId = max(0, (int)($_GET['drive_id'] ?? 0));
    $parentPath = trim((string)($_GET['parent_path'] ?? ''), '/');
    $level = max(0, min(40, (int)($_GET['level'] ?? 0)));

    if ($driveId <= 0) {
        throw new RuntimeException('Missing drive ID.');
    }

    $workflow = new CategoryWorkflowRepository(db());
    $html = '';
    $folders = $workflow->childFolders($driveId, $parentPath);
    foreach ($folders as $folder) {
        $folderPath = (string)$folder['relative_path'];
        $padding = 18 + (($level + 1) * 18);
        $url = cwtc_link(['drive_id' => $driveId, 'path' => $folderPath, 'selected_entry_id' => null, 'page' => 1]);
        $html .= '<div class="tree-folder lazy-tree-folder selectable-row" style="padding-left: ' . e((string)$padding) . 'px" data-id="' . e((string)$folder['id']) . '" data-entry-type="folder" data-entry-name="' . e((string)$folder['name']) . '" data-size="0" data-is-file="0" data-drive-id="' . e((string)$driveId) . '" data-path="' . e($folderPath) . '" data-level="' . e((string)($level + 1)) . '" data-loaded="0" data-url="' . e($url) . '">';
        $html .= '<span class="tree-toggle">▸</span><span class="folder-icon">📁</span> ' . e((string)$folder['name']);
        $html .= '</div>';
    }

    echo json_encode(['ok' => true, 'html' => $html], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
