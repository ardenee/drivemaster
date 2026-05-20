<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Support\IgnoredFolderSql;

function cwtb_link(array $params = []): string
{
    return 'category-workflow.php?' . http_build_query($params) . '#workflow-explorer';
}

function cwtb_manual_match_sql(string $alias = 'f'): string
{
    return "EXISTS (SELECT 1 FROM category_manual_assignments cma WHERE cma.file_entry_id = {$alias}.id OR (cma.assignment_type = 'folder' AND cma.drive_id = {$alias}.drive_id AND cma.path_prefix IS NOT NULL AND ({$alias}.relative_path = cma.path_prefix OR {$alias}.relative_path LIKE CONCAT(cma.path_prefix, '/%'))))";
}

function cwtb_children(int $driveId, string $parentPath): array
{
    $visibleSql = IgnoredFolderSql::excludeCondition('f');
    $unassignedSql = 'NOT ' . cwtb_manual_match_sql('f');
    $stmt = db()->prepare("SELECT id, name, relative_path FROM file_entries f WHERE drive_id = ? AND is_missing = 0 AND entry_type = 'folder' AND {$visibleSql} AND {$unassignedSql} AND parent_path = ? ORDER BY name ASC LIMIT 1000");
    $stmt->execute([$driveId, $parentPath]);
    return $stmt->fetchAll();
}

function cwtb_is_branch_path(string $folderPath, string $activePath): bool
{
    $folderPath = trim($folderPath, '/');
    $activePath = trim($activePath, '/');
    return $folderPath !== '' && ($folderPath === $activePath || str_starts_with($activePath, $folderPath . '/'));
}

function cwtb_render_branch(int $driveId, string $parentPath, string $activePath, int $level): string
{
    $html = '';
    foreach (cwtb_children($driveId, $parentPath) as $folder) {
        $folderId = (int)$folder['id'];
        $folderPath = (string)$folder['relative_path'];
        $isOpen = cwtb_is_branch_path($folderPath, $activePath);
        $isActive = trim($folderPath, '/') === trim($activePath, '/');
        $padding = 18 + ($level * 18);
        $url = cwtb_link(['drive_id' => $driveId, 'path' => $folderPath, 'selected_entry_id' => null, 'page' => 1]);

        $html .= '<div class="tree-folder lazy-tree-folder selectable-row' . ($isActive ? ' selected selected-row' : '') . '" style="padding-left: ' . e((string)$padding) . 'px" data-id="' . e((string)$folderId) . '" data-entry-type="folder" data-entry-name="' . e((string)$folder['name']) . '" data-size="0" data-is-file="0" data-drive-id="' . e((string)$driveId) . '" data-path="' . e($folderPath) . '" data-level="' . e((string)$level) . '" data-loaded="' . ($isOpen ? '1' : '0') . '" data-url="' . e($url) . '">';
        $html .= '<span class="tree-toggle">' . ($isOpen ? '▾' : '▸') . '</span><span class="folder-icon">📁</span> ' . e((string)$folder['name']);
        $html .= '</div>';

        if ($isOpen) {
            $html .= cwtb_render_branch($driveId, $folderPath, $activePath, $level + 1);
        }
    }
    return $html;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $driveId = max(0, (int)($_GET['drive_id'] ?? 0));
    $path = trim((string)($_GET['path'] ?? ''), '/');
    if ($driveId <= 0) {
        throw new RuntimeException('Missing drive ID.');
    }

    echo json_encode([
        'ok' => true,
        'drive_id' => $driveId,
        'path' => $path,
        'html' => cwtb_render_branch($driveId, '', $path, 0),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
