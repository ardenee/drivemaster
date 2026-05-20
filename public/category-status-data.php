<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Repository\CategoryWorkflowRepository;
use DriveMaster\Support\IgnoredFolderSql;

function csd_parent(string $path): string
{
    $path = trim($path, '/');
    if ($path === '') {
        return '';
    }
    $parts = explode('/', $path);
    array_pop($parts);
    return implode('/', $parts);
}

function csd_link(array $params = []): string
{
    return 'category-status.php?' . http_build_query($params) . '#category-status-explorer';
}

function csd_format_type(array $entry): string
{
    if ($entry['entry_type'] === 'folder') {
        return 'Folder';
    }
    $extension = strtoupper((string)($entry['extension'] ?? ''));
    return ($extension !== '' ? $extension : 'Unknown') . ' File';
}

function csd_category_badges(array $map, int $entryId): string
{
    if (empty($map[$entryId])) {
        return '';
    }
    $out = '';
    foreach ($map[$entryId] as $badge) {
        $source = $badge['source'] === 'manual' ? 'manual' : 'rule';
        $out .= '<span class="category-badge category-' . e($source) . '" title="' . e($source) . '">' . e($badge['name']) . '</span> ';
    }
    return trim($out);
}

function csd_load_category_badges(array $entryIds): array
{
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn(int $id): bool => $id > 0)));
    if (!$entryIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
    $visibleSql = IgnoredFolderSql::excludeCondition('f');
    $sql = "
        SELECT f.id AS file_entry_id, c.name AS category_name, 'manual' AS source
        FROM file_entries f
        JOIN category_manual_assignments cma ON cma.category_id IS NOT NULL
        JOIN categories c ON c.id = cma.category_id AND c.is_active = 1
        WHERE f.id IN ({$placeholders})
        AND {$visibleSql}
        AND (cma.file_entry_id = f.id OR (cma.assignment_type = 'folder' AND cma.drive_id = f.drive_id AND cma.path_prefix IS NOT NULL AND (f.relative_path = cma.path_prefix OR f.relative_path LIKE CONCAT(cma.path_prefix, '/%'))))
        UNION ALL
        SELECT f.id AS file_entry_id, c.name AS category_name, 'rule' AS source
        FROM file_entries f
        JOIN category_rules cr ON cr.is_enabled = 1 AND (cr.drive_id IS NULL OR cr.drive_id = f.drive_id)
        JOIN categories c ON c.id = cr.category_id AND c.is_active = 1
        WHERE f.id IN ({$placeholders})
        AND {$visibleSql}
        AND ((cr.rule_type = 'extension' AND cr.pattern = f.extension) OR (cr.rule_type = 'folder_path' AND (f.relative_path = cr.pattern OR f.relative_path LIKE CONCAT(cr.pattern, '/%'))) OR (cr.rule_type = 'path_contains' AND f.relative_path LIKE CONCAT('%', cr.pattern, '%')) OR (cr.rule_type = 'name_contains' AND f.name LIKE CONCAT('%', cr.pattern, '%')))
        ORDER BY category_name, source
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge($entryIds, $entryIds));
    $map = [];
    $seen = [];
    foreach ($stmt->fetchAll() as $row) {
        $entryId = (int)$row['file_entry_id'];
        $key = $entryId . '|' . $row['category_name'] . '|' . $row['source'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $map[$entryId][] = ['name' => (string)$row['category_name'], 'source' => (string)$row['source']];
    }
    return $map;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $driveId = max(0, (int)($_GET['drive_id'] ?? 0));
    $path = trim((string)($_GET['path'] ?? ''), '/');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $sort = (string)($_GET['sort'] ?? 'name');
    $dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    $mode = (string)($_GET['mode'] ?? 'unassigned');
    $mode = $mode === 'assigned' ? 'assigned' : 'unassigned';
    $modeLabel = $mode === 'assigned' ? 'Categorised' : 'Uncategorised';
    if ($mode === 'unassigned' && $sort === 'category') {
        $sort = 'name';
    }

    if ($driveId <= 0) {
        throw new RuntimeException('Missing drive ID.');
    }

    $driveStmt = db()->prepare('SELECT id, friendly_name FROM drives WHERE id = ?');
    $driveStmt->execute([$driveId]);
    $drive = $driveStmt->fetch();
    if (!$drive) {
        throw new RuntimeException('Drive not found.');
    }

    $repo = new CategoryWorkflowRepository(db());
    $summary = $repo->folderSummary($driveId, $path, $mode);
    $totalItems = (int)$summary['total_items'];
    $fileCount = (int)$summary['file_count'];
    $folderCount = (int)$summary['folder_count'];
    $totalSize = (int)$summary['total_size'];
    $totalPages = max(1, (int)ceil($totalItems / CategoryWorkflowRepository::PAGE_SIZE));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * CategoryWorkflowRepository::PAGE_SIZE;
    $children = $repo->folderChildren($driveId, $path, $sort, $dir, $page, $mode);
    $categoryMap = csd_load_category_badges(array_column($children, 'id'));

    $breadcrumbs = '<a href="' . e(csd_link(['drive_id' => $driveId, 'path' => '', 'page' => 1, 'mode' => $mode])) . '" data-status-path="" data-drive-id="' . e((string)$driveId) . '">' . e((string)$drive['friendly_name']) . '</a>';
    $running = '';
    foreach (array_filter(explode('/', $path), static fn(string $v): bool => $v !== '') as $part) {
        $running = $running === '' ? $part : $running . '/' . $part;
        $breadcrumbs .= ' / <a href="' . e(csd_link(['drive_id' => $driveId, 'path' => $running, 'page' => 1, 'mode' => $mode])) . '" data-status-path="' . e($running) . '" data-drive-id="' . e((string)$driveId) . '">' . e($part) . '</a>';
    }

    $rows = '';
    if ($path !== '') {
        $parentPath = csd_parent($path);
        $rows .= '<tr class="explorer-row" data-url="' . e(csd_link(['drive_id' => $driveId, 'path' => $parentPath, 'page' => 1, 'mode' => $mode])) . '" data-status-path="' . e($parentPath) . '" data-drive-id="' . e((string)$driveId) . '"><td>↩ ..</td><td>Folder</td><td></td><td></td><td></td></tr>';
    }

    foreach ($children as $entry) {
        $entryId = (int)$entry['id'];
        $entryUrl = $entry['entry_type'] === 'folder' ? csd_link(['drive_id' => $driveId, 'path' => $entry['relative_path'], 'selected_entry_id' => null, 'page' => 1, 'mode' => $mode]) : '';
        $rows .= '<tr id="entry-' . e((string)$entryId) . '" class="search-result-row explorer-row selectable-row" data-id="' . e((string)$entryId) . '" data-entry-type="' . e((string)$entry['entry_type']) . '" data-entry-name="' . e((string)$entry['name']) . '" data-url="' . e($entryUrl) . '" data-status-path="' . e($entry['entry_type'] === 'folder' ? (string)$entry['relative_path'] : '') . '" data-drive-id="' . e((string)$driveId) . '" data-size="' . e((string)(int)$entry['size_bytes']) . '" data-is-file="' . ($entry['entry_type'] === 'file' ? '1' : '0') . '">';
        $rows .= '<td>' . ($entry['entry_type'] === 'folder' ? '📁' : '📄') . ' ' . e((string)$entry['name']) . '</td><td>' . e(csd_format_type($entry)) . '</td><td>' . csd_category_badges($categoryMap, $entryId) . '</td><td>' . ($entry['entry_type'] === 'file' ? e(format_bytes((int)$entry['size_bytes'])) : '') . '</td><td>' . e((string)$entry['modified_at']) . '</td></tr>';
    }

    if (!$children) {
        $rows .= '<tr><td colspan="5">No ' . e(strtolower($modeLabel)) . ' entries in this folder.</td></tr>';
    }

    $pagerTop = '';
    $pagerBottom = '';
    if ($totalItems > CategoryWorkflowRepository::PAGE_SIZE) {
        $from = $totalItems === 0 ? 0 : $offset + 1;
        $to = min($offset + count($children), $totalItems);
        $prev = max(1, $page - 1);
        $next = min($totalPages, $page + 1);
        $pagerTop = '<div class="status-bar status-explorer-pager">Showing ' . e((string)$from) . '-' . e((string)$to) . ' of ' . e(number_format($totalItems)) . ' ' . e(strtolower($modeLabel)) . ' entries in this folder. <a class="button" href="' . e(csd_link(['drive_id' => $driveId, 'path' => $path, 'page' => $prev, 'sort' => $sort, 'dir' => $dir, 'mode' => $mode])) . '" data-status-page="' . e((string)$prev) . '">Previous</a> <a class="button" href="' . e(csd_link(['drive_id' => $driveId, 'path' => $path, 'page' => $next, 'sort' => $sort, 'dir' => $dir, 'mode' => $mode])) . '" data-status-page="' . e((string)$next) . '">Next</a></div>';
        $pagerBottom = '<div class="status-bar status-explorer-pager">Page ' . e((string)$page) . ' of ' . e((string)$totalPages) . '. <a class="button" href="' . e(csd_link(['drive_id' => $driveId, 'path' => $path, 'page' => $prev, 'sort' => $sort, 'dir' => $dir, 'mode' => $mode])) . '" data-status-page="' . e((string)$prev) . '">Previous</a> <a class="button" href="' . e(csd_link(['drive_id' => $driveId, 'path' => $path, 'page' => $next, 'sort' => $sort, 'dir' => $dir, 'mode' => $mode])) . '" data-status-page="' . e((string)$next) . '">Next</a></div>';
    }

    echo json_encode([
        'ok' => true,
        'drive_id' => $driveId,
        'path' => $path,
        'page' => $page,
        'mode' => $mode,
        'mode_label' => $modeLabel,
        'breadcrumbs_html' => $breadcrumbs,
        'rows_html' => $rows,
        'pager_top_html' => $pagerTop,
        'pager_bottom_html' => $pagerBottom,
        'status' => ['total_count' => $totalItems, 'file_count' => $fileCount, 'folder_count' => $folderCount, 'total_size' => $totalSize],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
