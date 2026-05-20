<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Support\IgnoredFolderSql;

const CATEGORY_WORKFLOW_AJAX_PAGE_SIZE = 495;

function cwd_parent(string $path): string
{
    $path = trim($path, '/');
    if ($path === '') {
        return '';
    }
    $parts = explode('/', $path);
    array_pop($parts);
    return implode('/', $parts);
}

function cwd_link(array $params = []): string
{
    return 'category-workflow.php?' . http_build_query($params) . '#workflow-explorer';
}

function cwd_manual_match_sql(string $alias = 'f'): string
{
    return "EXISTS (SELECT 1 FROM category_manual_assignments cma WHERE cma.file_entry_id = {$alias}.id OR (cma.assignment_type = 'folder' AND cma.drive_id = {$alias}.drive_id AND cma.path_prefix IS NOT NULL AND ({$alias}.relative_path = cma.path_prefix OR {$alias}.relative_path LIKE CONCAT(cma.path_prefix, '/%'))))";
}

function cwd_format_type(array $entry): string
{
    if ($entry['entry_type'] === 'folder') {
        return 'Folder';
    }
    $extension = strtoupper((string)($entry['extension'] ?? ''));
    return ($extension !== '' ? $extension : 'Unknown') . ' File';
}

header('Content-Type: application/json; charset=utf-8');

try {
    $driveId = max(0, (int)($_GET['drive_id'] ?? 0));
    $path = trim((string)($_GET['path'] ?? ''), '/');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $sort = (string)($_GET['sort'] ?? 'name');
    $dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    $offset = ($page - 1) * CATEGORY_WORKFLOW_AJAX_PAGE_SIZE;

    if ($driveId <= 0) {
        throw new RuntimeException('Missing drive ID.');
    }

    $driveStmt = db()->prepare('SELECT id, friendly_name FROM drives WHERE id = ?');
    $driveStmt->execute([$driveId]);
    $drive = $driveStmt->fetch();
    if (!$drive) {
        throw new RuntimeException('Drive not found.');
    }

    $visibleSql = IgnoredFolderSql::excludeCondition('f');
    $unassignedSql = 'NOT ' . cwd_manual_match_sql('f');
    $sortSql = match ($sort) {
        'type' => "CASE WHEN f.entry_type = 'folder' THEN 'Folder' ELSE COALESCE(f.extension, '') END",
        'size' => 'f.size_bytes',
        'modified' => 'f.modified_at',
        default => 'f.name',
    };

    $summaryStmt = db()->prepare("SELECT COUNT(*) AS total_items, SUM(f.entry_type = 'file') AS file_count, SUM(f.entry_type = 'folder') AS folder_count, COALESCE(SUM(CASE WHEN f.entry_type = 'file' THEN f.size_bytes ELSE 0 END), 0) AS total_size FROM file_entries f WHERE f.drive_id = ? AND f.is_missing = 0 AND {$visibleSql} AND {$unassignedSql} AND f.parent_path = ?");
    $summaryStmt->execute([$driveId, $path]);
    $summary = $summaryStmt->fetch() ?: [];
    $totalItems = (int)($summary['total_items'] ?? 0);
    $fileCount = (int)($summary['file_count'] ?? 0);
    $folderCount = (int)($summary['folder_count'] ?? 0);
    $totalSize = (int)($summary['total_size'] ?? 0);
    $totalPages = max(1, (int)ceil($totalItems / CATEGORY_WORKFLOW_AJAX_PAGE_SIZE));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * CATEGORY_WORKFLOW_AJAX_PAGE_SIZE;
    }

    $stmt = db()->prepare("SELECT f.id, f.drive_id, f.relative_path, f.name, f.entry_type, f.extension, f.size_bytes, f.modified_at FROM file_entries f WHERE f.drive_id = ? AND f.is_missing = 0 AND {$visibleSql} AND {$unassignedSql} AND f.parent_path = ? ORDER BY CASE WHEN f.entry_type = 'folder' THEN 0 ELSE 1 END ASC, {$sortSql} {$dir}, f.name ASC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $driveId, PDO::PARAM_INT);
    $stmt->bindValue(2, $path);
    $stmt->bindValue(3, CATEGORY_WORKFLOW_AJAX_PAGE_SIZE, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $children = $stmt->fetchAll();

    $breadcrumbs = '<a href="' . e(cwd_link(['drive_id' => $driveId, 'path' => '', 'page' => 1])) . '" data-workflow-path="" data-drive-id="' . e((string)$driveId) . '">' . e((string)$drive['friendly_name']) . '</a>';
    $running = '';
    foreach (array_filter(explode('/', $path), static fn(string $v): bool => $v !== '') as $part) {
        $running = $running === '' ? $part : $running . '/' . $part;
        $breadcrumbs .= ' / <a href="' . e(cwd_link(['drive_id' => $driveId, 'path' => $running, 'page' => 1])) . '" data-workflow-path="' . e($running) . '" data-drive-id="' . e((string)$driveId) . '">' . e($part) . '</a>';
    }

    $rows = '';
    if ($path !== '') {
        $parentPath = cwd_parent($path);
        $rows .= '<tr class="explorer-row" data-url="' . e(cwd_link(['drive_id' => $driveId, 'path' => $parentPath, 'page' => 1])) . '" data-workflow-path="' . e($parentPath) . '" data-drive-id="' . e((string)$driveId) . '"><td>↩ ..</td><td>Folder</td><td></td><td></td></tr>';
    }

    foreach ($children as $entry) {
        $entryId = (int)$entry['id'];
        $entryUrl = $entry['entry_type'] === 'folder' ? cwd_link(['drive_id' => $driveId, 'path' => $entry['relative_path'], 'selected_entry_id' => null, 'page' => 1]) : '';
        $rows .= '<tr id="entry-' . e((string)$entryId) . '" class="search-result-row explorer-row selectable-row" data-id="' . e((string)$entryId) . '" data-entry-type="' . e((string)$entry['entry_type']) . '" data-entry-name="' . e((string)$entry['name']) . '" data-url="' . e($entryUrl) . '" data-workflow-path="' . e($entry['entry_type'] === 'folder' ? (string)$entry['relative_path'] : '') . '" data-drive-id="' . e((string)$driveId) . '" data-size="' . e((string)(int)$entry['size_bytes']) . '" data-is-file="' . ($entry['entry_type'] === 'file' ? '1' : '0') . '">';
        $rows .= '<td>' . ($entry['entry_type'] === 'folder' ? '📁' : '📄') . ' ' . e((string)$entry['name']) . '</td><td>' . e(cwd_format_type($entry)) . '</td><td>' . ($entry['entry_type'] === 'file' ? e(format_bytes((int)$entry['size_bytes'])) : '') . '</td><td>' . e((string)$entry['modified_at']) . '</td></tr>';
    }

    if (!$children) {
        $rows .= '<tr><td colspan="4">No unassigned entries in this folder.</td></tr>';
    }

    $pagerTop = '';
    $pagerBottom = '';
    if ($totalItems > CATEGORY_WORKFLOW_AJAX_PAGE_SIZE) {
        $from = $totalItems === 0 ? 0 : $offset + 1;
        $to = min($offset + count($children), $totalItems);
        $prev = max(1, $page - 1);
        $next = min($totalPages, $page + 1);
        $pagerTop = '<div class="status-bar workflow-explorer-pager">Showing ' . e((string)$from) . '-' . e((string)$to) . ' of ' . e(number_format($totalItems)) . ' unassigned entries in this folder. <a class="button" href="' . e(cwd_link(['drive_id' => $driveId, 'path' => $path, 'page' => $prev, 'sort' => $sort, 'dir' => $dir])) . '" data-workflow-page="' . e((string)$prev) . '">Previous</a> <a class="button" href="' . e(cwd_link(['drive_id' => $driveId, 'path' => $path, 'page' => $next, 'sort' => $sort, 'dir' => $dir])) . '" data-workflow-page="' . e((string)$next) . '">Next</a></div>';
        $pagerBottom = '<div class="status-bar workflow-explorer-pager">Page ' . e((string)$page) . ' of ' . e((string)$totalPages) . '. <a class="button" href="' . e(cwd_link(['drive_id' => $driveId, 'path' => $path, 'page' => $prev, 'sort' => $sort, 'dir' => $dir])) . '" data-workflow-page="' . e((string)$prev) . '">Previous</a> <a class="button" href="' . e(cwd_link(['drive_id' => $driveId, 'path' => $path, 'page' => $next, 'sort' => $sort, 'dir' => $dir])) . '" data-workflow-page="' . e((string)$next) . '">Next</a></div>';
    }

    echo json_encode([
        'ok' => true,
        'drive_id' => $driveId,
        'path' => $path,
        'page' => $page,
        'breadcrumbs_html' => $breadcrumbs,
        'rows_html' => $rows,
        'pager_top_html' => $pagerTop,
        'pager_bottom_html' => $pagerBottom,
        'status' => [
            'total_count' => $totalItems,
            'file_count' => $fileCount,
            'folder_count' => $folderCount,
            'total_size' => $totalSize,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
