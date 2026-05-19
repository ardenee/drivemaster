<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Support\IgnoredFolderSql;

const AJAX_EXPLORER_PAGE_SIZE = 495;

function fed_parent(string $path): string
{
    $path = trim($path, '/');
    if ($path === '') {
        return '';
    }
    $parts = explode('/', $path);
    array_pop($parts);
    return implode('/', $parts);
}

function fed_parent_expr(string $alias = 'f'): string
{
    $path = $alias . '.relative_path';
    return "CASE WHEN LOCATE('/', {$path}) = 0 THEN '' ELSE SUBSTRING({$path}, 1, LENGTH({$path}) - LENGTH(SUBSTRING_INDEX({$path}, '/', -1)) - 1) END";
}

function fed_link(array $params = []): string
{
    return 'files.php?' . http_build_query($params) . '#explorer-panel';
}

function fed_rgb(?string $colorHex): ?array
{
    $colorHex = (string)$colorHex;
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colorHex)) {
        return null;
    }
    return [hexdec(substr($colorHex, 1, 2)), hexdec(substr($colorHex, 3, 2)), hexdec(substr($colorHex, 5, 2))];
}

function fed_badge_style(?string $colorHex): string
{
    $rgb = fed_rgb($colorHex);
    if (!$rgb) {
        return '';
    }
    return ' style="background-color: rgba(' . $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2] . ',0.55); border-color: rgba(' . $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2] . ',0.85);"';
}

function fed_row_style(?string $colorHex): string
{
    $rgb = fed_rgb($colorHex);
    if (!$rgb) {
        return '';
    }
    return ' style="background-color: rgba(' . $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2] . ',0.16); border-left: 4px solid rgba(' . $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2] . ',0.85);"';
}

function fed_category_badges(array $entryIds): array
{
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn(int $id): bool => $id > 0)));
    if (!$entryIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
    $visibleSql = IgnoredFolderSql::excludeCondition('f');
    $sql = "
        SELECT f.id AS file_entry_id, c.name AS category_name, c.color_hex, 'manual' AS source
        FROM file_entries f
        JOIN category_manual_assignments cma ON cma.category_id IS NOT NULL
        JOIN categories c ON c.id = cma.category_id AND c.is_active = 1
        WHERE f.id IN ({$placeholders}) AND {$visibleSql}
        AND (cma.file_entry_id = f.id OR (cma.assignment_type = 'folder' AND cma.drive_id = f.drive_id AND cma.path_prefix IS NOT NULL AND (f.relative_path = cma.path_prefix OR f.relative_path LIKE CONCAT(cma.path_prefix, '/%'))))
        UNION ALL
        SELECT f.id AS file_entry_id, c.name AS category_name, c.color_hex, 'rule' AS source
        FROM file_entries f
        JOIN category_rules cr ON cr.is_enabled = 1 AND (cr.drive_id IS NULL OR cr.drive_id = f.drive_id)
        JOIN categories c ON c.id = cr.category_id AND c.is_active = 1
        WHERE f.id IN ({$placeholders}) AND {$visibleSql}
        AND ((cr.rule_type = 'extension' AND cr.pattern = f.extension) OR (cr.rule_type = 'folder_path' AND (f.relative_path = cr.pattern OR f.relative_path LIKE CONCAT(cr.pattern, '/%'))) OR (cr.rule_type = 'path_contains' AND f.relative_path LIKE CONCAT('%', cr.pattern, '%')) OR (cr.rule_type = 'name_contains' AND f.name LIKE CONCAT('%', cr.pattern, '%')))
        ORDER BY source ASC, category_name ASC
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
        $source = $row['source'] === 'manual' ? 'manual' : 'rule';
        if (!isset($map[$entryId])) {
            $map[$entryId] = ['badges' => [], 'row_color' => (string)($row['color_hex'] ?? '')];
        }
        $map[$entryId]['badges'][] = '<span class="category-badge category-' . e($source) . '" title="' . e($source) . ': ' . e((string)$row['category_name']) . '"' . fed_badge_style((string)($row['color_hex'] ?? '')) . '>' . e((string)$row['category_name']) . '</span>';
    }
    return $map;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $driveId = max(0, (int)($_GET['drive_id'] ?? 0));
    $path = trim((string)($_GET['path'] ?? ''), '/');
    $page = max(1, (int)($_GET['explorer_page'] ?? 1));
    $sort = (string)($_GET['sort'] ?? 'name');
    $dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    $offset = ($page - 1) * AJAX_EXPLORER_PAGE_SIZE;

    if ($driveId <= 0) {
        throw new RuntimeException('Missing drive ID.');
    }

    $driveStmt = db()->prepare('SELECT id, friendly_name FROM drives WHERE id = ?');
    $driveStmt->execute([$driveId]);
    $drive = $driveStmt->fetch();
    if (!$drive) {
        throw new RuntimeException('Drive not found.');
    }

    $expr = fed_parent_expr('f');
    $visibleSql = IgnoredFolderSql::excludeCondition('f');
    $sortSql = match ($sort) {
        'type' => "CASE WHEN f.entry_type = 'folder' THEN 'Folder' ELSE COALESCE(f.extension, '') END",
        'size' => 'f.size_bytes',
        'modified' => 'f.modified_at',
        default => 'f.name',
    };

    $summaryStmt = db()->prepare("SELECT COUNT(*) AS total_items, SUM(f.entry_type = 'file') AS file_count, SUM(f.entry_type = 'folder') AS folder_count, COALESCE(SUM(CASE WHEN f.entry_type = 'file' THEN f.size_bytes ELSE 0 END), 0) AS total_size FROM file_entries f WHERE f.drive_id = ? AND f.is_missing = 0 AND {$visibleSql} AND {$expr} = ?");
    $summaryStmt->execute([$driveId, $path]);
    $summary = $summaryStmt->fetch() ?: [];
    $totalItems = (int)($summary['total_items'] ?? 0);
    $fileCount = (int)($summary['file_count'] ?? 0);
    $folderCount = (int)($summary['folder_count'] ?? 0);
    $totalSize = (int)($summary['total_size'] ?? 0);
    $totalPages = max(1, (int)ceil($totalItems / AJAX_EXPLORER_PAGE_SIZE));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * AJAX_EXPLORER_PAGE_SIZE;
    }

    $stmt = db()->prepare("SELECT f.id, f.relative_path, f.name, f.extension, f.entry_type, f.size_bytes, f.modified_at FROM file_entries f WHERE f.drive_id = ? AND f.is_missing = 0 AND {$visibleSql} AND {$expr} = ? ORDER BY CASE WHEN f.entry_type = 'folder' THEN 0 ELSE 1 END ASC, {$sortSql} {$dir}, f.name ASC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $driveId, PDO::PARAM_INT);
    $stmt->bindValue(2, $path);
    $stmt->bindValue(3, AJAX_EXPLORER_PAGE_SIZE, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $children = $stmt->fetchAll();
    $categoryMap = fed_category_badges(array_column($children, 'id'));

    $breadcrumbs = '<a href="' . e(fed_link(['drive_id' => $driveId, 'path' => '', 'expanded_drive_id' => $driveId, 'explorer_page' => 1])) . '" data-ajax-path="" data-drive-id="' . e((string)$driveId) . '">' . e((string)$drive['friendly_name']) . '</a>';
    $running = '';
    foreach (array_filter(explode('/', $path), static fn(string $v): bool => $v !== '') as $part) {
        $running = $running === '' ? $part : $running . '/' . $part;
        $breadcrumbs .= ' / <a href="' . e(fed_link(['drive_id' => $driveId, 'path' => $running, 'expanded_drive_id' => $driveId, 'explorer_page' => 1])) . '" data-ajax-path="' . e($running) . '" data-drive-id="' . e((string)$driveId) . '">' . e($part) . '</a>';
    }

    $rows = '';
    if ($path !== '') {
        $rows .= '<tr class="explorer-row" data-url="' . e(fed_link(['drive_id' => $driveId, 'path' => fed_parent($path), 'expanded_drive_id' => $driveId, 'explorer_page' => 1])) . '" data-ajax-path="' . e(fed_parent($path)) . '" data-drive-id="' . e((string)$driveId) . '"><td>↩ ..</td><td>Folder</td><td></td><td></td><td></td></tr>';
    }
    foreach ($children as $entry) {
        $entryId = (int)$entry['id'];
        $entryCategory = $categoryMap[$entryId] ?? ['badges' => [], 'row_color' => ''];
        $entryUrl = $entry['entry_type'] === 'folder' ? fed_link(['drive_id' => $driveId, 'path' => $entry['relative_path'], 'expanded_drive_id' => $driveId, 'selected_entry_id' => null, 'explorer_page' => 1]) : '';
        $badges = implode(' ', $entryCategory['badges'] ?? []);
        $rows .= '<tr id="entry-' . e((string)$entryId) . '" class="explorer-row selectable-row"' . fed_row_style((string)($entryCategory['row_color'] ?? '')) . ' data-id="' . e((string)$entryId) . '" data-entry-type="' . e((string)$entry['entry_type']) . '" data-entry-name="' . e((string)$entry['name']) . '" data-url="' . e($entryUrl) . '" data-ajax-path="' . e($entry['entry_type'] === 'folder' ? (string)$entry['relative_path'] : '') . '" data-drive-id="' . e((string)$driveId) . '" data-size="' . e((string)(int)$entry['size_bytes']) . '" data-is-file="' . ($entry['entry_type'] === 'file' ? '1' : '0') . '">';
        $rows .= '<td>' . ($entry['entry_type'] === 'folder' ? '📁' : '📄') . ' ' . e((string)$entry['name']) . '</td><td>' . e($entry['entry_type'] === 'folder' ? 'Folder' : strtoupper((string)$entry['extension']) . ' File') . '</td><td>' . $badges . '</td><td>' . ($entry['entry_type'] === 'file' ? e(format_bytes((int)$entry['size_bytes'])) : '') . '</td><td>' . e((string)$entry['modified_at']) . '</td></tr>';
    }
    if (!$children) {
        $rows .= '<tr><td colspan="5">No entries found in this folder.</td></tr>';
    }

    $pagerTop = '';
    $pagerBottom = '';
    if ($totalItems > AJAX_EXPLORER_PAGE_SIZE) {
        $from = $totalItems === 0 ? 0 : $offset + 1;
        $to = min($offset + count($children), $totalItems);
        $prev = max(1, $page - 1);
        $next = min($totalPages, $page + 1);
        $pagerTop = '<div class="status-bar ajax-explorer-pager">Showing ' . e((string)$from) . '-' . e((string)$to) . ' of ' . e(number_format($totalItems)) . ' items in this folder. <a class="button" href="' . e(fed_link(['drive_id' => $driveId, 'path' => $path, 'explorer_page' => $prev])) . '" data-ajax-page="' . e((string)$prev) . '">Previous</a> <a class="button" href="' . e(fed_link(['drive_id' => $driveId, 'path' => $path, 'explorer_page' => $next])) . '" data-ajax-page="' . e((string)$next) . '">Next</a></div>';
        $pagerBottom = '<div class="status-bar ajax-explorer-pager">Page ' . e((string)$page) . ' of ' . e((string)$totalPages) . '. <a class="button" href="' . e(fed_link(['drive_id' => $driveId, 'path' => $path, 'explorer_page' => $prev])) . '" data-ajax-page="' . e((string)$prev) . '">Previous</a> <a class="button" href="' . e(fed_link(['drive_id' => $driveId, 'path' => $path, 'explorer_page' => $next])) . '" data-ajax-page="' . e((string)$next) . '">Next</a></div>';
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
