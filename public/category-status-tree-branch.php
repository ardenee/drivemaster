<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Repository\CategoryWorkflowRepository;

function cstb_link(array $params = []): string
{
    return 'category-status.php?' . http_build_query($params) . '#category-status-explorer';
}

function cstb_is_branch_path(string $folderPath, string $activePath): bool
{
    $folderPath = trim($folderPath, '/');
    $activePath = trim($activePath, '/');
    return $folderPath !== '' && ($folderPath === $activePath || str_starts_with($activePath, $folderPath . '/'));
}

function cstb_render_branch(CategoryWorkflowRepository $repo, int $driveId, string $parentPath, string $activePath, string $mode, int $level): string
{
    $html = '';
    foreach ($repo->childFolders($driveId, $parentPath, $mode) as $folder) {
        $folderId = (int)$folder['id'];
        $folderPath = (string)$folder['relative_path'];
        $isOpen = cstb_is_branch_path($folderPath, $activePath);
        $isActive = trim($folderPath, '/') === trim($activePath, '/');
        $padding = 18 + ($level * 18);
        $url = cstb_link(['drive_id' => $driveId, 'path' => $folderPath, 'selected_entry_id' => null, 'page' => 1, 'mode' => $mode]);
        $html .= '<div class="tree-folder lazy-tree-folder selectable-row' . ($isActive ? ' selected selected-row' : '') . '" style="padding-left: ' . e((string)$padding) . 'px" data-id="' . e((string)$folderId) . '" data-entry-type="folder" data-entry-name="' . e((string)$folder['name']) . '" data-size="0" data-is-file="0" data-drive-id="' . e((string)$driveId) . '" data-path="' . e($folderPath) . '" data-level="' . e((string)$level) . '" data-loaded="' . ($isOpen ? '1' : '0') . '" data-url="' . e($url) . '">';
        $html .= '<span class="tree-toggle">' . ($isOpen ? '▾' : '▸') . '</span><span class="folder-icon">📁</span> ' . e((string)$folder['name']);
        $html .= '</div>';
        if ($isOpen) {
            $html .= cstb_render_branch($repo, $driveId, $folderPath, $activePath, $mode, $level + 1);
        }
    }
    return $html;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $driveId = max(0, (int)($_GET['drive_id'] ?? 0));
    $path = trim((string)($_GET['path'] ?? ''), '/');
    $mode = (string)($_GET['mode'] ?? 'unassigned');
    $mode = $mode === 'assigned' ? 'assigned' : 'unassigned';
    if ($driveId <= 0) {
        throw new RuntimeException('Missing drive ID.');
    }
    $repo = new CategoryWorkflowRepository(db());
    echo json_encode(['ok' => true, 'drive_id' => $driveId, 'path' => $path, 'mode' => $mode, 'html' => cstb_render_branch($repo, $driveId, '', $path, $mode, 0)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
