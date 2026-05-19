<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Support\CategoryMatchSql;
use DriveMaster\Support\IgnoredFolderSql;

function ftb_parent_expr(): string
{
    return "CASE WHEN LOCATE('/', relative_path) = 0 THEN '' ELSE SUBSTRING(relative_path, 1, LENGTH(relative_path) - LENGTH(SUBSTRING_INDEX(relative_path, '/', -1)) - 1) END";
}

function ftb_link(array $params = []): string
{
    return 'files.php?' . http_build_query($params) . '#explorer-panel';
}

function ftb_hex_to_rgba(?string $hex, float $alpha): ?string
{
    $hex = (string)$hex;
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
        return null;
    }
    return 'rgba(' . hexdec(substr($hex, 1, 2)) . ',' . hexdec(substr($hex, 3, 2)) . ',' . hexdec(substr($hex, 5, 2)) . ',' . $alpha . ')';
}

function ftb_category_map(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $matchSql = CategoryMatchSql::matchSql();
    $stmt = db()->prepare("SELECT cm.file_entry_id, cm.category_name, cm.color_hex FROM ({$matchSql}) cm WHERE cm.file_entry_id IN ({$placeholders}) ORDER BY cm.category_name");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['file_entry_id'];
        if (!isset($map[$id])) {
            $map[$id] = [
                'name' => (string)$row['category_name'],
                'color' => (string)$row['color_hex'],
            ];
        }
    }
    return $map;
}

function ftb_children(int $driveId, string $parentPath): array
{
    $expr = ftb_parent_expr();
    $visibleSql = IgnoredFolderSql::excludeCondition('f');
    $stmt = db()->prepare("SELECT id, name, relative_path FROM file_entries f WHERE drive_id = ? AND is_missing = 0 AND entry_type = 'folder' AND {$visibleSql} AND {$expr} = ? ORDER BY name ASC LIMIT 1000");
    $stmt->execute([$driveId, $parentPath]);
    return $stmt->fetchAll();
}

function ftb_is_branch_path(string $folderPath, string $activePath): bool
{
    $folderPath = trim($folderPath, '/');
    $activePath = trim($activePath, '/');
    return $folderPath !== '' && ($folderPath === $activePath || str_starts_with($activePath, $folderPath . '/'));
}

function ftb_render_branch(int $driveId, string $parentPath, string $activePath, int $level): string
{
    $children = ftb_children($driveId, $parentPath);
    $categoryMap = ftb_category_map(array_column($children, 'id'));
    $html = '';

    foreach ($children as $folder) {
        $folderId = (int)$folder['id'];
        $folderPath = (string)$folder['relative_path'];
        $isOpen = ftb_is_branch_path($folderPath, $activePath);
        $isActive = trim($folderPath, '/') === trim($activePath, '/');
        $padding = 18 + ($level * 18);
        $url = ftb_link(['drive_id' => $driveId, 'path' => $folderPath, 'selected_entry_id' => null, 'explorer_page' => 1]);
        $styleParts = ['padding-left: ' . $padding . 'px'];
        $title = '';
        if (isset($categoryMap[$folderId])) {
            $bg = ftb_hex_to_rgba($categoryMap[$folderId]['color'], 0.18);
            $border = ftb_hex_to_rgba($categoryMap[$folderId]['color'], 0.85);
            if ($bg && $border) {
                $styleParts[] = 'background-color: ' . $bg;
                $styleParts[] = 'border-left: 4px solid ' . $border;
                $title = ' title="Category: ' . e((string)$categoryMap[$folderId]['name']) . '"';
            }
        }

        $html .= '<div class="tree-folder lazy-tree-folder selectable-row' . ($isActive ? ' selected selected-row' : '') . '" style="' . e(implode('; ', $styleParts)) . '" data-id="' . e((string)$folderId) . '" data-entry-type="folder" data-entry-name="' . e((string)$folder['name']) . '" data-size="0" data-is-file="0" data-drive-id="' . e((string)$driveId) . '" data-path="' . e($folderPath) . '" data-level="' . e((string)$level) . '" data-loaded="' . ($isOpen ? '1' : '0') . '" data-url="' . e($url) . '"' . $title . '>';
        $html .= '<span class="tree-toggle">' . ($isOpen ? '▾' : '▸') . '</span><span class="folder-icon">📁</span> ' . e((string)$folder['name']);
        $html .= '</div>';

        if ($isOpen) {
            $html .= ftb_render_branch($driveId, $folderPath, $activePath, $level + 1);
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
        'html' => ftb_render_branch($driveId, '', $path, 0),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
