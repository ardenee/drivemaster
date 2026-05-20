<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\IgnoredFolderSql;

const EXPLORER_PAGE_SIZE = 495;
const SEARCH_PAGE_SIZE = 495;
const TREE_CHILD_LIMIT = 1000;

$error = null;
$debug = isset($_GET['debug']);

function fp_parent(string $path): string
{
    $path = trim($path, '/');
    if ($path === '') {
        return '';
    }
    $parts = explode('/', $path);
    array_pop($parts);
    return implode('/', $parts);
}

function fp_parent_expr(string $alias = ''): string
{
    $path = $alias === '' ? 'relative_path' : $alias . '.relative_path';
    return "CASE WHEN LOCATE('/', {$path}) = 0 THEN '' ELSE SUBSTRING({$path}, 1, LENGTH({$path}) - LENGTH(SUBSTRING_INDEX({$path}, '/', -1)) - 1) END";
}

function fp_link(array $params = [], bool $explorer = false): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        }
    }
    return 'files.php?' . http_build_query($query) . ($explorer ? '#explorer-panel' : '');
}

function fp_highlight(?string $value, array $terms): string
{
    $out = e($value ?? '');
    foreach ($terms as $term) {
        $term = trim((string)$term);
        if ($term === '') {
            continue;
        }
        $out = preg_replace('/(' . preg_quote(e($term), '/') . ')/iu', '<mark>$1</mark>', $out) ?? $out;
    }
    return $out;
}

function fp_folder_children(int $driveId, string $parentPath): array
{
    $expr = fp_parent_expr('f');
    $visibleSql = IgnoredFolderSql::excludeCondition('f');
    $stmt = db()->prepare("SELECT f.id, f.name, f.relative_path FROM file_entries f WHERE f.drive_id = ? AND f.is_missing = 0 AND f.entry_type = 'folder' AND {$visibleSql} AND {$expr} = ? ORDER BY f.name ASC LIMIT " . TREE_CHILD_LIMIT);
    $stmt->execute([$driveId, $parentPath]);
    return $stmt->fetchAll();
}

function fp_on_branch(string $folderPath, string $activePath): bool
{
    $folderPath = trim($folderPath, '/');
    $activePath = trim($activePath, '/');
    return $folderPath !== '' && ($folderPath === $activePath || str_starts_with($activePath, $folderPath . '/'));
}

function fp_render_tree(int $driveId, string $parentPath, string $activePath, int $level = 0): void
{
    foreach (fp_folder_children($driveId, $parentPath) as $folder) {
        $folderPath = (string)$folder['relative_path'];
        $isOpen = fp_on_branch($folderPath, $activePath);
        $isActive = $folderPath === $activePath;
        $padding = 18 + ($level * 18);
        ?>
        <div class="tree-folder selectable-row <?= $isActive ? 'selected selected-row' : '' ?>" style="padding-left: <?= e((string)$padding) ?>px" data-id="<?= e((string)$folder['id']) ?>" data-entry-type="folder" data-entry-name="<?= e((string)$folder['name']) ?>" data-size="0" data-is-file="0" data-drive-id="<?= e((string)$driveId) ?>" data-path="<?= e($folderPath) ?>" data-level="<?= e((string)$level) ?>" data-url="<?= e(fp_link(['drive_id' => $driveId, 'path' => $folderPath, 'expanded_drive_id' => $driveId, 'selected_entry_id' => null, 'explorer_page' => 1], true)) ?>">
            <span class="tree-toggle"><?= $isOpen ? '▾' : '▸' ?></span><span class="folder-icon">📁</span> <?= e((string)$folder['name']) ?>
        </div>
        <?php
        if ($isOpen) {
            fp_render_tree($driveId, $folderPath, $activePath, $level + 1);
        }
    }
}

function fp_manual_exists_sql(string $alias): string
{
    return "EXISTS (SELECT 1 FROM category_manual_assignments cma WHERE cma.category_id = ? AND (cma.file_entry_id = {$alias}.id OR (cma.assignment_type = 'folder' AND cma.drive_id = {$alias}.drive_id AND cma.path_prefix IS NOT NULL AND ({$alias}.relative_path = cma.path_prefix OR {$alias}.relative_path LIKE CONCAT(cma.path_prefix, '/%')))))";
}

function fp_rule_exists_sql(string $alias): string
{
    return "EXISTS (SELECT 1 FROM category_rules cr WHERE cr.category_id = ? AND cr.is_enabled = 1 AND (cr.drive_id IS NULL OR cr.drive_id = {$alias}.drive_id) AND ((cr.rule_type = 'extension' AND cr.pattern = {$alias}.extension) OR (cr.rule_type = 'folder_path' AND ({$alias}.relative_path = cr.pattern OR {$alias}.relative_path LIKE CONCAT(cr.pattern, '/%'))) OR (cr.rule_type = 'path_contains' AND {$alias}.relative_path LIKE CONCAT('%', cr.pattern, '%')) OR (cr.rule_type = 'name_contains' AND {$alias}.name LIKE CONCAT('%', cr.pattern, '%'))))";
}

function fp_category_filter_sql(string $alias, string $source): string
{
    return match ($source) {
        'manual' => fp_manual_exists_sql($alias),
        'rules' => fp_rule_exists_sql($alias),
        default => '(' . fp_manual_exists_sql($alias) . ' OR ' . fp_rule_exists_sql($alias) . ')',
    };
}

function fp_category_filter_param_count(string $source): int
{
    return $source === 'both' ? 2 : 1;
}

function fp_category_badges(array $map, int $entryId): string
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

function fp_load_category_badges(array $entryIds): array
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

try {
    $drives = db()->query('SELECT id, friendly_name, mount_path FROM drives ORDER BY friendly_name')->fetchAll();
} catch (Throwable $e) {
    $drives = [];
    $error = 'Could not load drives: ' . $e->getMessage();
}

try {
    $categories = db()->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $categories = [];
    $error = trim(($error ? $error . ' | ' : '') . 'Could not load categories: ' . $e->getMessage());
}

$driveId = (int)($_GET['drive_id'] ?? ($drives[0]['id'] ?? 0));
$currentPath = trim((string)($_GET['path'] ?? ''));
$expandedDriveId = (int)($_GET['expanded_drive_id'] ?? 0);
$selectedEntryId = (int)($_GET['selected_entry_id'] ?? 0);
$explorerPage = max(1, (int)($_GET['explorer_page'] ?? 1));
$explorerOffset = ($explorerPage - 1) * EXPLORER_PAGE_SIZE;
$sort = (string)($_GET['sort'] ?? 'name');
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$nextDir = $dir === 'asc' ? 'desc' : 'asc';

$searchText = trim((string)($_GET['search'] ?? ''));
$scopeDriveId = (int)($_GET['scope_drive_id'] ?? 0);
$entryTypeFilter = (string)($_GET['entry_type'] ?? 'any');
$extensionFilter = trim((string)($_GET['extension'] ?? ''));
$categoryId = (int)($_GET['category_id'] ?? 0);
$categorySource = (string)($_GET['category_source'] ?? 'both');
if (!in_array($categorySource, ['both', 'manual', 'rules'], true)) {
    $categorySource = 'both';
}
$pathFilter = trim((string)($_GET['path_contains'] ?? ''));
$minSize = trim((string)($_GET['min_size'] ?? ''));
$maxSize = trim((string)($_GET['max_size'] ?? ''));
$modifiedFrom = trim((string)($_GET['modified_from'] ?? ''));
$modifiedTo = trim((string)($_GET['modified_to'] ?? ''));
$resultLimit = (string)($_GET['result_limit'] ?? '1000');
$resultLimitOptions = [['495','495 per page'], ['1000','1000 per page'], ['5000','5000 per page'], ['10000','10000 per page'], ['none','No limit, paged']];
$validLimits = array_map(static fn(array $row): string => $row[0], $resultLimitOptions);
if (!in_array($resultLimit, $validLimits, true)) {
    $resultLimit = '1000';
}
$searchPage = max(1, (int)($_GET['search_page'] ?? 1));
$searchPageSize = $resultLimit === 'none' ? SEARCH_PAGE_SIZE : max(1, min(10000, (int)$resultLimit));
$searchOffset = ($searchPage - 1) * $searchPageSize;
$resultSort = (string)($_GET['result_sort'] ?? 'drive');
$resultDir = strtolower((string)($_GET['result_dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$resultNextDir = $resultDir === 'asc' ? 'desc' : 'asc';

$selectedDrive = null;
foreach ($drives as $drive) {
    if ((int)$drive['id'] === $driveId) {
        $selectedDrive = $drive;
        break;
    }
}
if (!$selectedDrive && $drives) {
    $selectedDrive = $drives[0];
    $driveId = (int)$selectedDrive['id'];
}

$searchTerms = array_values(array_filter(array_map('trim', explode(',', $searchText)), static fn(string $v): bool => $v !== ''));
$extensionTerms = array_values(array_filter(array_map(static fn(string $v): string => strtolower(ltrim(trim($v), '.')), explode(',', $extensionFilter)), static fn(string $v): bool => $v !== ''));
$highlightTerms = array_values(array_filter(array_merge($searchTerms, $extensionTerms, [$pathFilter]), static fn(string $v): bool => $v !== ''));
$hasSearch = $searchText !== '' || $scopeDriveId > 0 || $entryTypeFilter !== 'any' || $extensionFilter !== '' || $categoryId > 0 || $pathFilter !== '' || $minSize !== '' || $maxSize !== '' || $modifiedFrom !== '' || $modifiedTo !== '';

$children = [];
$breadcrumbs = [];
$explorerFileCount = 0;
$explorerFolderCount = 0;
$explorerTotalSize = 0;
$explorerTotalItems = 0;
$explorerDisplayedCount = 0;
$explorerCategoryMap = [];

try {
    if ($driveId > 0) {
        $expr = fp_parent_expr('f');
        $visibleSql = IgnoredFolderSql::excludeCondition('f');
        $sortSql = match ($sort) {
            'type' => "CASE WHEN f.entry_type = 'folder' THEN 'Folder' ELSE COALESCE(f.extension, '') END",
            'size' => 'f.size_bytes',
            'modified' => 'f.modified_at',
            default => 'f.name',
        };

        $summaryStmt = db()->prepare("SELECT COUNT(*) AS total_items, SUM(f.entry_type = 'file') AS file_count, SUM(f.entry_type = 'folder') AS folder_count, COALESCE(SUM(CASE WHEN f.entry_type = 'file' THEN f.size_bytes ELSE 0 END), 0) AS total_size FROM file_entries f WHERE f.drive_id = ? AND f.is_missing = 0 AND {$visibleSql} AND {$expr} = ?");
        $summaryStmt->execute([$driveId, $currentPath]);
        $summary = $summaryStmt->fetch() ?: [];
        $explorerTotalItems = (int)($summary['total_items'] ?? 0);
        $explorerFileCount = (int)($summary['file_count'] ?? 0);
        $explorerFolderCount = (int)($summary['folder_count'] ?? 0);
        $explorerTotalSize = (int)($summary['total_size'] ?? 0);

        $stmt = db()->prepare("SELECT f.id, f.relative_path, f.name, f.extension, f.entry_type, f.size_bytes, f.modified_at FROM file_entries f WHERE f.drive_id = ? AND f.is_missing = 0 AND {$visibleSql} AND {$expr} = ? ORDER BY CASE WHEN f.entry_type = 'folder' THEN 0 ELSE 1 END ASC, {$sortSql} {$dir}, f.name ASC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $driveId, PDO::PARAM_INT);
        $stmt->bindValue(2, $currentPath);
        $stmt->bindValue(3, EXPLORER_PAGE_SIZE, PDO::PARAM_INT);
        $stmt->bindValue(4, $explorerOffset, PDO::PARAM_INT);
        $stmt->execute();
        $children = $stmt->fetchAll();
        $explorerDisplayedCount = count($children);
        $explorerCategoryMap = fp_load_category_badges(array_column($children, 'id'));

        $running = '';
        foreach (array_filter(explode('/', $currentPath), static fn(string $v): bool => $v !== '') as $part) {
            $running = $running === '' ? $part : $running . '/' . $part;
            $breadcrumbs[] = ['name' => $part, 'path' => $running];
        }
    }
} catch (Throwable $e) {
    $error = trim(($error ? $error . ' | ' : '') . 'Could not load explorer entries: ' . $e->getMessage());
}

$searchResults = [];
$searchFileCount = 0;
$searchFolderCount = 0;
$searchTotalSize = 0;
$searchTotalItems = 0;
$searchCategoryMap = [];

try {
    if ($hasSearch) {
        $where = ['f.is_missing = 0', IgnoredFolderSql::excludeCondition('f')];
        $params = [];
        if ($scopeDriveId > 0) {
            $where[] = 'f.drive_id = ?';
            $params[] = $scopeDriveId;
        }
        if ($searchTerms) {
            $clauses = [];
            foreach ($searchTerms as $term) {
                $clauses[] = '(f.name LIKE ? OR f.relative_path LIKE ?)';
                $params[] = '%' . $term . '%';
                $params[] = '%' . $term . '%';
            }
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }
        if ($entryTypeFilter === 'file' || $entryTypeFilter === 'folder') {
            $where[] = 'f.entry_type = ?';
            $params[] = $entryTypeFilter;
        }
        if ($extensionTerms) {
            $where[] = 'f.extension IN (' . implode(',', array_fill(0, count($extensionTerms), '?')) . ')';
            array_push($params, ...$extensionTerms);
        }
        if ($categoryId > 0) {
            $where[] = fp_category_filter_sql('f', $categorySource);
            for ($i = 0; $i < fp_category_filter_param_count($categorySource); $i++) {
                $params[] = $categoryId;
            }
        }
        if ($pathFilter !== '') {
            $where[] = 'f.relative_path LIKE ?';
            $params[] = '%' . $pathFilter . '%';
        }
        if ($minSize !== '') {
            $where[] = 'f.size_bytes >= ?';
            $params[] = (int)$minSize;
        }
        if ($maxSize !== '') {
            $where[] = 'f.size_bytes <= ?';
            $params[] = (int)$maxSize;
        }
        if ($modifiedFrom !== '') {
            $where[] = 'f.modified_at >= ?';
            $params[] = $modifiedFrom . ' 00:00:00';
        }
        if ($modifiedTo !== '') {
            $where[] = 'f.modified_at <= ?';
            $params[] = $modifiedTo . ' 23:59:59';
        }
        $resultSortSql = match ($resultSort) {
            'name' => 'f.name',
            'type' => "CASE WHEN f.entry_type = 'folder' THEN 'Folder' ELSE COALESCE(f.extension, '') END",
            'path' => 'f.relative_path',
            'size' => 'f.size_bytes',
            'modified' => 'f.modified_at',
            default => 'd.friendly_name',
        };
        $whereSql = implode(' AND ', $where);
        $summaryStmt = db()->prepare("SELECT COUNT(*) AS total_items, SUM(f.entry_type = 'file') AS file_count, SUM(f.entry_type = 'folder') AS folder_count, COALESCE(SUM(CASE WHEN f.entry_type = 'file' THEN f.size_bytes ELSE 0 END), 0) AS total_size FROM file_entries f JOIN drives d ON d.id = f.drive_id WHERE {$whereSql}");
        $summaryStmt->execute($params);
        $summary = $summaryStmt->fetch() ?: [];
        $searchTotalItems = (int)($summary['total_items'] ?? 0);
        $searchFileCount = (int)($summary['file_count'] ?? 0);
        $searchFolderCount = (int)($summary['folder_count'] ?? 0);
        $searchTotalSize = (int)($summary['total_size'] ?? 0);

        $stmt = db()->prepare("SELECT f.id, f.drive_id, f.relative_path, f.name, f.extension, f.entry_type, f.size_bytes, f.modified_at, d.friendly_name FROM file_entries f JOIN drives d ON d.id = f.drive_id WHERE {$whereSql} ORDER BY {$resultSortSql} {$resultDir}, f.relative_path ASC LIMIT ? OFFSET ?");
        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value);
        }
        $stmt->bindValue(count($params) + 1, $searchPageSize, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $searchOffset, PDO::PARAM_INT);
        $stmt->execute();
        $searchResults = $stmt->fetchAll();
        $searchCategoryMap = fp_load_category_badges(array_column($searchResults, 'id'));
    }
} catch (Throwable $e) {
    $error = trim(($error ? $error . ' | ' : '') . 'Search failed: ' . $e->getMessage());
}

$explorerTotalPages = max(1, (int)ceil($explorerTotalItems / EXPLORER_PAGE_SIZE));
if ($explorerPage > $explorerTotalPages) {
    $explorerPage = $explorerTotalPages;
}
$searchTotalPages = max(1, (int)ceil($searchTotalItems / max(1, $searchPageSize)));
if ($searchPage > $searchTotalPages) {
    $searchPage = $searchTotalPages;
}

render_header('Files');
?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
<?php if ($debug): ?><div class="card"><strong>Debug:</strong> drives=<?= e((string)count($drives)) ?>, categories=<?= e((string)count($categories)) ?>, driveId=<?= e((string)$driveId) ?>, children=<?= e((string)count($children)) ?>, totalItems=<?= e((string)$explorerTotalItems) ?>, hasSearch=<?= $hasSearch ? 'yes' : 'no' ?></div><?php endif; ?>

<div class="card">
    <h2>File Search</h2>
    <form method="get">
        <div class="form-grid compact-search">
            <label>Search text <input type="text" name="search" value="<?= e($searchText) ?>" placeholder="comma separated, e.g. invoice, photo"></label>
            <label>Search scope <select name="scope_drive_id"><option value="0">All drives</option><?php foreach ($drives as $drive): ?><option value="<?= e((string)$drive['id']) ?>" <?= $scopeDriveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e((string)$drive['friendly_name']) ?></option><?php endforeach; ?></select></label>
            <label>Entry type <select name="entry_type"><option value="any" <?= $entryTypeFilter === 'any' ? 'selected' : '' ?>>Files and folders</option><option value="file" <?= $entryTypeFilter === 'file' ? 'selected' : '' ?>>Files only</option><option value="folder" <?= $entryTypeFilter === 'folder' ? 'selected' : '' ?>>Folders only</option></select></label>
            <label>Category <select name="category_id"><option value="0">Any category</option><?php foreach ($categories as $category): ?><option value="<?= e((string)$category['id']) ?>" <?= $categoryId === (int)$category['id'] ? 'selected' : '' ?>><?= e((string)$category['name']) ?></option><?php endforeach; ?></select></label>
            <label>Category source <select name="category_source"><option value="both" <?= $categorySource === 'both' ? 'selected' : '' ?>>Manual + rules</option><option value="manual" <?= $categorySource === 'manual' ? 'selected' : '' ?>>Manual only</option><option value="rules" <?= $categorySource === 'rules' ? 'selected' : '' ?>>Rules only</option></select></label>
            <label>File extensions <input type="text" name="extension" value="<?= e($extensionFilter) ?>" placeholder="comma separated, e.g. mp3, jpg, zip"></label>
            <label>Full path contains <input type="text" name="path_contains" value="<?= e($pathFilter) ?>"></label>
            <label>Min size bytes <input type="number" name="min_size" min="0" value="<?= e($minSize) ?>"></label>
            <label>Max size bytes <input type="number" name="max_size" min="0" value="<?= e($maxSize) ?>"></label>
            <label>Modified from <input type="date" name="modified_from" value="<?= e($modifiedFrom) ?>"></label>
            <label>Modified to <input type="date" name="modified_to" value="<?= e($modifiedTo) ?>"></label>
            <label>Result limit <select name="result_limit"><?php foreach ($resultLimitOptions as $option): ?><option value="<?= e((string)$option[0]) ?>" <?= $resultLimit === $option[0] ? 'selected' : '' ?>><?= e((string)$option[1]) ?></option><?php endforeach; ?></select></label>
        </div>
        <input type="hidden" name="result_sort" value="<?= e($resultSort) ?>"><input type="hidden" name="result_dir" value="<?= e($resultDir) ?>"><input type="hidden" name="search_page" value="1">
        <p><button type="submit">Search</button> <a class="button" href="files.php">Clear</a></p>
    </form>
</div>

<?php if ($hasSearch): ?>
<div class="card search-results-card">
    <h2>Search results</h2>
    <p class="muted">Showing <?= e(number_format($searchTotalItems === 0 ? 0 : $searchOffset + 1)) ?>-<?= e(number_format(min($searchOffset + count($searchResults), $searchTotalItems))) ?> of <?= e(number_format($searchTotalItems)) ?> matching items.</p>
    <div class="search-results-scroll">
        <table class="search-results-table selectable-table" data-status-target="search-status">
            <thead><tr><?php foreach (['drive' => 'Drive', 'name' => 'Name', 'type' => 'Type', 'category' => 'Category', 'path' => 'Path', 'size' => 'Size', 'modified' => 'Modified'] as $key => $label): ?><th><?php if ($key === 'category'): ?><?= e($label) ?><?php else: ?><a href="<?= e(fp_link(['result_sort' => $key, 'result_dir' => $resultSort === $key ? $resultNextDir : 'asc', 'search_page' => 1])) ?>"><?= e($label) ?><?= $resultSort === $key ? e($resultDir === 'asc' ? ' ▲' : ' ▼') : '' ?></a><?php endif; ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php foreach ($searchResults as $result): ?>
                    <?php $targetUrl = fp_link(['drive_id' => $result['drive_id'], 'path' => fp_parent((string)$result['relative_path']), 'expanded_drive_id' => $result['drive_id'], 'selected_entry_id' => $result['id'], 'explorer_page' => 1], true); ?>
                    <tr class="search-result-row selectable-row" data-id="<?= e((string)$result['id']) ?>" data-entry-type="<?= e((string)$result['entry_type']) ?>" data-entry-name="<?= e((string)$result['name']) ?>" data-url="<?= e($targetUrl) ?>" data-size="<?= e((string)(int)$result['size_bytes']) ?>" data-is-file="<?= $result['entry_type'] === 'file' ? '1' : '0' ?>">
                        <td><?= e((string)$result['friendly_name']) ?></td><td><?= $result['entry_type'] === 'folder' ? '📁' : '📄' ?> <?= fp_highlight((string)$result['name'], $highlightTerms) ?></td><td><?= e($result['entry_type'] === 'folder' ? 'Folder' : strtoupper((string)$result['extension']) . ' File') ?></td><td><?= fp_category_badges($searchCategoryMap, (int)$result['id']) ?></td><td><?= fp_highlight((string)$result['relative_path'], $highlightTerms) ?></td><td><?= $result['entry_type'] === 'file' ? e(format_bytes((int)$result['size_bytes'])) : '' ?></td><td><?= e((string)$result['modified_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$searchResults): ?><tr><td colspan="7">No results found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($searchTotalItems > $searchPageSize): ?>
        <div class="status-bar">Page <?= e(number_format($searchPage)) ?> of <?= e(number_format($searchTotalPages)) ?>. <a class="button" href="<?= e(fp_link(['search_page' => max(1, $searchPage - 1)])) ?>">Previous</a> <a class="button" href="<?= e(fp_link(['search_page' => min($searchTotalPages, $searchPage + 1)])) ?>">Next</a></div>
    <?php endif; ?>
    <div class="status-bar" id="search-status" data-total-count="<?= e((string)$searchTotalItems) ?>" data-total-size="<?= e((string)$searchTotalSize) ?>" data-file-count="<?= e((string)$searchFileCount) ?>" data-folder-count="<?= e((string)$searchFolderCount) ?>"></div>
</div>
<?php endif; ?>

<?php if (!$drives): ?>
<div class="card"><p>No drives have been scanned yet.</p></div>
<?php else: ?>
<div class="card explorer-wrap" id="explorer-panel">
    <div class="explorer-sidebar">
        <h3>Drives</h3>
        <?php foreach ($drives as $drive): ?>
            <?php $thisDriveId = (int)$drive['id']; $isExpanded = $expandedDriveId === $thisDriveId; ?>
            <div class="tree-drive <?= $driveId === $thisDriveId ? 'selected' : '' ?>" data-drive-id="<?= e((string)$thisDriveId) ?>" data-url="<?= e(fp_link(['drive_id' => $thisDriveId, 'path' => '', 'expanded_drive_id' => $isExpanded ? 0 : $thisDriveId, 'selected_entry_id' => null, 'explorer_page' => 1], true)) ?>">
                <span class="tree-toggle"><?= $isExpanded ? '▾' : '▸' ?></span> 💽 <?= e((string)$drive['friendly_name']) ?>
            </div>
            <?php if ($isExpanded): ?><?php fp_render_tree($thisDriveId, '', $driveId === $thisDriveId ? $currentPath : ''); ?><?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="explorer-main">
        <div class="breadcrumbs"><a href="<?= e(fp_link(['drive_id' => $driveId, 'path' => '', 'expanded_drive_id' => $driveId, 'explorer_page' => 1], true)) ?>"><?= e((string)($selectedDrive['friendly_name'] ?? 'Drive')) ?></a><?php foreach ($breadcrumbs as $crumb): ?> / <a href="<?= e(fp_link(['drive_id' => $driveId, 'path' => $crumb['path'], 'expanded_drive_id' => $driveId, 'explorer_page' => 1], true)) ?>"><?= e((string)$crumb['name']) ?></a><?php endforeach; ?></div>
        <?php if ($explorerTotalItems > EXPLORER_PAGE_SIZE): ?>
            <div class="status-bar">Showing <?= e((string)($explorerOffset + 1)) ?>-<?= e((string)min($explorerOffset + $explorerDisplayedCount, $explorerTotalItems)) ?> of <?= e(number_format($explorerTotalItems)) ?> items in this folder. <a class="button" href="<?= e(fp_link(['explorer_page' => max(1, $explorerPage - 1)], true)) ?>">Previous</a> <a class="button" href="<?= e(fp_link(['explorer_page' => min($explorerTotalPages, $explorerPage + 1)], true)) ?>">Next</a></div>
        <?php endif; ?>
        <table class="explorer-table selectable-table" data-status-target="explorer-status">
            <thead><tr><?php foreach (['name' => 'Name', 'type' => 'Type', 'category' => 'Category', 'size' => 'Size', 'modified' => 'Modified'] as $key => $label): ?><th><?php if ($key === 'category'): ?><?= e($label) ?><?php else: ?><a href="<?= e(fp_link(['sort' => $key, 'dir' => $sort === $key ? $nextDir : 'asc', 'explorer_page' => 1], true)) ?>"><?= e($label) ?><?= $sort === $key ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a><?php endif; ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php if ($currentPath !== ''): ?><tr class="explorer-row" data-url="<?= e(fp_link(['path' => fp_parent($currentPath), 'expanded_drive_id' => $driveId, 'explorer_page' => 1], true)) ?>"><td>↩ ..</td><td>Folder</td><td></td><td></td><td></td></tr><?php endif; ?>
                <?php foreach ($children as $entry): ?>
                    <?php $entryUrl = $entry['entry_type'] === 'folder' ? fp_link(['path' => $entry['relative_path'], 'expanded_drive_id' => $driveId, 'selected_entry_id' => null, 'explorer_page' => 1], true) : ''; ?>
                    <tr id="entry-<?= e((string)$entry['id']) ?>" class="explorer-row selectable-row <?= $selectedEntryId === (int)$entry['id'] ? 'selected-row target-selected-row' : '' ?>" data-id="<?= e((string)$entry['id']) ?>" data-entry-type="<?= e((string)$entry['entry_type']) ?>" data-entry-name="<?= e((string)$entry['name']) ?>" data-url="<?= e($entryUrl) ?>" data-size="<?= e((string)(int)$entry['size_bytes']) ?>" data-is-file="<?= $entry['entry_type'] === 'file' ? '1' : '0' ?>">
                        <td><?= $entry['entry_type'] === 'folder' ? '📁' : '📄' ?> <?= fp_highlight((string)$entry['name'], $highlightTerms) ?></td><td><?= e($entry['entry_type'] === 'folder' ? 'Folder' : strtoupper((string)$entry['extension']) . ' File') ?></td><td><?= fp_category_badges($explorerCategoryMap, (int)$entry['id']) ?></td><td><?= $entry['entry_type'] === 'file' ? e(format_bytes((int)$entry['size_bytes'])) : '' ?></td><td><?= e((string)$entry['modified_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$children): ?><tr><td colspan="5">No entries found in this folder.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php if ($explorerTotalItems > EXPLORER_PAGE_SIZE): ?>
            <div class="status-bar">Page <?= e((string)$explorerPage) ?> of <?= e((string)$explorerTotalPages) ?>. <a class="button" href="<?= e(fp_link(['explorer_page' => max(1, $explorerPage - 1)], true)) ?>">Previous</a> <a class="button" href="<?= e(fp_link(['explorer_page' => min($explorerTotalPages, $explorerPage + 1)], true)) ?>">Next</a></div>
        <?php endif; ?>
        <div class="status-bar" id="explorer-status" data-total-count="<?= e((string)$explorerTotalItems) ?>" data-total-size="<?= e((string)$explorerTotalSize) ?>" data-file-count="<?= e((string)$explorerFileCount) ?>" data-folder-count="<?= e((string)$explorerFolderCount) ?>"></div>
    </div>
</div>
<?php endif; ?>

<div id="context-menu" class="context-menu"><button type="button" data-context-action="add_category">Add to category</button><button type="button" data-context-action="remove_category">Remove from category</button><hr><button type="button" data-context-action="rename">Rename...</button><button type="button" data-context-action="move">Move...</button><button type="button" data-context-action="delete">Delete...</button></div>
<div id="drive-context-menu" class="context-menu"><button type="button" data-drive-action="remove_drive">Remove drive from DB...</button></div>

<form method="post" action="files-action.php" id="context-form"><input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? 'files.php') ?>"><input type="hidden" name="action" id="context-action-input"><input type="hidden" name="selected_ids" id="context-selected-ids"><input type="hidden" name="drive_id" id="context-drive-id">
    <div id="context-dialog" class="context-dialog hidden"><div class="context-dialog-card"><h3 id="context-dialog-title">Action</h3><div id="context-category-area"><label>Category <select name="context_category_id"><?php foreach ($categories as $category): ?><option value="<?= e((string)$category['id']) ?>"><?= e((string)$category['name']) ?></option><?php endforeach; ?></select></label></div><div id="context-move-area"><label>Destination drive <select name="destination_drive_id"><?php foreach ($drives as $drive): ?><option value="<?= e((string)$drive['id']) ?>"><?= e((string)$drive['friendly_name']) ?></option><?php endforeach; ?></select></label></div><div id="context-rename-area"><label>New name <input type="text" name="new_name" id="context-new-name"></label></div><p id="context-dialog-note" class="muted"></p><p><button type="submit">Confirm</button> <button type="button" id="context-cancel-button">Cancel</button></p></div></div>
</form>

<?php render_footer(); ?>
