<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Repository\DriveRepository;
use DriveMaster\Repository\JobRepository;

const EXPLORER_PAGE_SIZE = 1000;
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

function fp_parent_expr(): string
{
    return "CASE WHEN LOCATE('/', relative_path) = 0 THEN '' ELSE SUBSTRING(relative_path, 1, LENGTH(relative_path) - LENGTH(SUBSTRING_INDEX(relative_path, '/', -1)) - 1) END";
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

function fp_selected_ids(): array
{
    $raw = trim((string)($_POST['selected_ids'] ?? ''));
    if ($raw === '') {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn(int $id): bool => $id > 0)));
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
    $expr = fp_parent_expr();
    $stmt = db()->prepare("SELECT id, name, relative_path FROM file_entries WHERE drive_id = ? AND is_missing = 0 AND entry_type = 'folder' AND {$expr} = ? ORDER BY name ASC LIMIT " . TREE_CHILD_LIMIT);
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
        <div class="tree-folder <?= $isActive ? 'selected selected-row' : '' ?>" style="padding-left: <?= e((string)$padding) ?>px" data-url="<?= e(fp_link(['drive_id' => $driveId, 'path' => $folderPath, 'expanded_drive_id' => $driveId, 'selected_entry_id' => null, 'explorer_page' => 1], true)) ?>">
            <span class="tree-toggle"><?= $isOpen ? '▾' : '▸' ?></span><span class="folder-icon">📁</span> <?= e((string)$folder['name']) ?>
        </div>
        <?php
        if ($isOpen) {
            fp_render_tree($driveId, $folderPath, $activePath, $level + 1);
        }
    }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $returnUrl = (string)($_POST['return_url'] ?? 'files.php');

    try {
        if ($action === 'remove_drive') {
            $driveIdPost = (int)($_POST['drive_id'] ?? 0);
            if ($driveIdPost <= 0) {
                throw new RuntimeException('No drive was selected.');
            }
            (new DriveRepository(db()))->delete($driveIdPost);
            header('Location: files.php');
            exit;
        }

        $selectedIds = fp_selected_ids();
        if (!$selectedIds) {
            throw new RuntimeException('No file/folder selection was supplied.');
        }

        if ($action === 'add_category' || $action === 'remove_category') {
            $categoryId = (int)($_POST['context_category_id'] ?? 0);
            if ($categoryId <= 0) {
                throw new RuntimeException('No category was selected.');
            }
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $stmt = db()->prepare("SELECT id, drive_id, relative_path FROM file_entries WHERE id IN ({$placeholders}) AND entry_type = 'folder'");
            $stmt->execute($selectedIds);
            foreach ($stmt->fetchAll() as $folder) {
                if ($action === 'add_category') {
                    $insert = db()->prepare("INSERT INTO category_assignments (category_id, drive_id, path_prefix, assignment_type) SELECT ?, ?, ?, 'folder_rule' WHERE NOT EXISTS (SELECT 1 FROM category_assignments WHERE category_id = ? AND drive_id = ? AND path_prefix = ? AND assignment_type = 'folder_rule')");
                    $insert->execute([$categoryId, $folder['drive_id'], $folder['relative_path'], $categoryId, $folder['drive_id'], $folder['relative_path']]);
                } else {
                    $delete = db()->prepare("DELETE FROM category_assignments WHERE category_id = ? AND drive_id = ? AND path_prefix = ? AND assignment_type = 'folder_rule'");
                    $delete->execute([$categoryId, $folder['drive_id'], $folder['relative_path']]);
                }
            }
        }

        if (in_array($action, ['queue_move', 'queue_delete', 'queue_rename'], true)) {
            $payload = ['file_entry_ids' => $selectedIds];
            if ($action === 'queue_move') {
                $payload['destination_drive_id'] = (int)($_POST['destination_drive_id'] ?? 0);
            }
            if ($action === 'queue_rename') {
                $payload['new_name'] = trim((string)($_POST['new_name'] ?? ''));
            }
            (new JobRepository(db()))->create(match ($action) {
                'queue_move' => 'file_move',
                'queue_delete' => 'file_delete',
                'queue_rename' => 'file_rename',
            }, $payload);
        }

        header('Location: ' . $returnUrl);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
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
$pathFilter = trim((string)($_GET['path_contains'] ?? ''));
$minSize = trim((string)($_GET['min_size'] ?? ''));
$maxSize = trim((string)($_GET['max_size'] ?? ''));
$modifiedFrom = trim((string)($_GET['modified_from'] ?? ''));
$modifiedTo = trim((string)($_GET['modified_to'] ?? ''));
$resultLimit = (string)($_GET['result_limit'] ?? '1000');
$resultLimitOptions = [['100','100'], ['500','500'], ['1000','1000'], ['5000','5000'], ['10000','10000'], ['none','No limit']];
$validLimits = array_map(static fn(array $row): string => $row[0], $resultLimitOptions);
if (!in_array($resultLimit, $validLimits, true)) {
    $resultLimit = '1000';
}
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

try {
    if ($driveId > 0) {
        $expr = fp_parent_expr();
        $sortSql = match ($sort) {
            'type' => "CASE WHEN entry_type = 'folder' THEN 'Folder' ELSE COALESCE(extension, '') END",
            'size' => 'size_bytes',
            'modified' => 'modified_at',
            default => 'name',
        };

        $summaryStmt = db()->prepare("SELECT COUNT(*) AS total_items, SUM(entry_type = 'file') AS file_count, SUM(entry_type = 'folder') AS folder_count, COALESCE(SUM(CASE WHEN entry_type = 'file' THEN size_bytes ELSE 0 END), 0) AS total_size FROM file_entries WHERE drive_id = ? AND is_missing = 0 AND {$expr} = ?");
        $summaryStmt->execute([$driveId, $currentPath]);
        $summary = $summaryStmt->fetch() ?: [];
        $explorerTotalItems = (int)($summary['total_items'] ?? 0);
        $explorerFileCount = (int)($summary['file_count'] ?? 0);
        $explorerFolderCount = (int)($summary['folder_count'] ?? 0);
        $explorerTotalSize = (int)($summary['total_size'] ?? 0);

        $stmt = db()->prepare("SELECT id, relative_path, name, extension, entry_type, size_bytes, modified_at FROM file_entries WHERE drive_id = ? AND is_missing = 0 AND {$expr} = ? ORDER BY CASE WHEN entry_type = 'folder' THEN 0 ELSE 1 END ASC, {$sortSql} {$dir}, name ASC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $driveId, PDO::PARAM_INT);
        $stmt->bindValue(2, $currentPath);
        $stmt->bindValue(3, EXPLORER_PAGE_SIZE, PDO::PARAM_INT);
        $stmt->bindValue(4, $explorerOffset, PDO::PARAM_INT);
        $stmt->execute();
        $children = $stmt->fetchAll();
        $explorerDisplayedCount = count($children);

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

try {
    if ($hasSearch) {
        $where = ['f.is_missing = 0'];
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
            $where[] = "EXISTS (SELECT 1 FROM category_assignments ca WHERE ca.category_id = ? AND (ca.file_entry_id = f.id OR (ca.assignment_type = 'extension_rule' AND ca.extension = f.extension) OR (ca.assignment_type = 'folder_rule' AND ca.drive_id = f.drive_id AND ca.path_prefix IS NOT NULL AND f.relative_path LIKE CONCAT(ca.path_prefix, '%'))))";
            $params[] = $categoryId;
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
        $limitSql = $resultLimit === 'none' ? ' LIMIT 50000' : ' LIMIT ' . max(1, min(10000, (int)$resultLimit));
        $stmt = db()->prepare('SELECT f.id, f.drive_id, f.relative_path, f.name, f.extension, f.entry_type, f.size_bytes, f.modified_at, d.friendly_name FROM file_entries f JOIN drives d ON d.id = f.drive_id WHERE ' . implode(' AND ', $where) . " ORDER BY {$resultSortSql} {$resultDir}, f.relative_path ASC" . $limitSql);
        $stmt->execute($params);
        $searchResults = $stmt->fetchAll();
        foreach ($searchResults as $result) {
            if ($result['entry_type'] === 'file') {
                $searchFileCount++;
                $searchTotalSize += (int)$result['size_bytes'];
            } else {
                $searchFolderCount++;
            }
        }
    }
} catch (Throwable $e) {
    $error = trim(($error ? $error . ' | ' : '') . 'Search failed: ' . $e->getMessage());
}

$explorerTotalPages = max(1, (int)ceil($explorerTotalItems / EXPLORER_PAGE_SIZE));
if ($explorerPage > $explorerTotalPages) {
    $explorerPage = $explorerTotalPages;
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
            <label>File extensions <input type="text" name="extension" value="<?= e($extensionFilter) ?>" placeholder="comma separated, e.g. mp3, jpg, zip"></label>
            <label>Full path contains <input type="text" name="path_contains" value="<?= e($pathFilter) ?>"></label>
            <label>Min size bytes <input type="number" name="min_size" min="0" value="<?= e($minSize) ?>"></label>
            <label>Max size bytes <input type="number" name="max_size" min="0" value="<?= e($maxSize) ?>"></label>
            <label>Modified from <input type="date" name="modified_from" value="<?= e($modifiedFrom) ?>"></label>
            <label>Modified to <input type="date" name="modified_to" value="<?= e($modifiedTo) ?>"></label>
            <label>Result limit <select name="result_limit"><?php foreach ($resultLimitOptions as $option): ?><option value="<?= e((string)$option[0]) ?>" <?= $resultLimit === $option[0] ? 'selected' : '' ?>><?= e((string)$option[1]) ?></option><?php endforeach; ?></select></label>
        </div>
        <input type="hidden" name="result_sort" value="<?= e($resultSort) ?>"><input type="hidden" name="result_dir" value="<?= e($resultDir) ?>">
        <p><button type="submit">Search</button> <a class="button" href="files.php">Clear</a></p>
    </form>
</div>

<?php if ($hasSearch): ?>
<div class="card search-results-card">
    <h2>Search results</h2>
    <?php if ($resultLimit === 'none'): ?><p class="muted">No limit is capped at 50,000 visible rows to prevent the browser/PHP worker from exhausting memory.</p><?php endif; ?>
    <div class="search-results-scroll">
        <table class="search-results-table selectable-table" data-status-target="search-status">
            <thead><tr><?php foreach (['drive' => 'Drive', 'name' => 'Name', 'type' => 'Type', 'path' => 'Path', 'size' => 'Size', 'modified' => 'Modified'] as $key => $label): ?><th><a href="<?= e(fp_link(['result_sort' => $key, 'result_dir' => $resultSort === $key ? $resultNextDir : 'asc'])) ?>"><?= e($label) ?><?= $resultSort === $key ? e($resultDir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php foreach ($searchResults as $result): ?>
                    <?php $targetUrl = fp_link(['drive_id' => $result['drive_id'], 'path' => fp_parent((string)$result['relative_path']), 'expanded_drive_id' => $result['drive_id'], 'selected_entry_id' => $result['id'], 'explorer_page' => 1], true); ?>
                    <tr class="search-result-row selectable-row" data-id="<?= e((string)$result['id']) ?>" data-entry-type="<?= e((string)$result['entry_type']) ?>" data-entry-name="<?= e((string)$result['name']) ?>" data-url="<?= e($targetUrl) ?>" data-size="<?= e((string)(int)$result['size_bytes']) ?>" data-is-file="<?= $result['entry_type'] === 'file' ? '1' : '0' ?>">
                        <td><?= e((string)$result['friendly_name']) ?></td><td><?= $result['entry_type'] === 'folder' ? '📁' : '📄' ?> <?= fp_highlight((string)$result['name'], $highlightTerms) ?></td><td><?= e($result['entry_type'] === 'folder' ? 'Folder' : strtoupper((string)$result['extension']) . ' File') ?></td><td><?= fp_highlight((string)$result['relative_path'], $highlightTerms) ?></td><td><?= $result['entry_type'] === 'file' ? e(format_bytes((int)$result['size_bytes'])) : '' ?></td><td><?= e((string)$result['modified_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$searchResults): ?><tr><td colspan="6">No results found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="status-bar" id="search-status" data-total-count="<?= e((string)count($searchResults)) ?>" data-total-size="<?= e((string)$searchTotalSize) ?>" data-file-count="<?= e((string)$searchFileCount) ?>" data-folder-count="<?= e((string)$searchFolderCount) ?>"></div>
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
            <div class="status-bar">Showing <?= e((string)($explorerOffset + 1)) ?>-<?= e((string)min($explorerOffset + $explorerDisplayedCount, $explorerTotalItems)) ?> of <?= e((string)$explorerTotalItems) ?> items in this folder. <a class="button" href="<?= e(fp_link(['explorer_page' => max(1, $explorerPage - 1)], true)) ?>">Previous</a> <a class="button" href="<?= e(fp_link(['explorer_page' => min($explorerTotalPages, $explorerPage + 1)], true)) ?>">Next</a></div>
        <?php endif; ?>
        <table class="explorer-table selectable-table" data-status-target="explorer-status">
            <thead><tr><?php foreach (['name' => 'Name', 'type' => 'Type', 'size' => 'Size', 'modified' => 'Modified'] as $key => $label): ?><th><a href="<?= e(fp_link(['sort' => $key, 'dir' => $sort === $key ? $nextDir : 'asc', 'explorer_page' => 1], true)) ?>"><?= e($label) ?><?= $sort === $key ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php if ($currentPath !== ''): ?><tr class="explorer-row" data-url="<?= e(fp_link(['path' => fp_parent($currentPath), 'expanded_drive_id' => $driveId, 'explorer_page' => 1], true)) ?>"><td>↩ ..</td><td>Folder</td><td></td><td></td></tr><?php endif; ?>
                <?php foreach ($children as $entry): ?>
                    <?php $entryUrl = $entry['entry_type'] === 'folder' ? fp_link(['path' => $entry['relative_path'], 'expanded_drive_id' => $driveId, 'selected_entry_id' => null, 'explorer_page' => 1], true) : ''; ?>
                    <tr id="entry-<?= e((string)$entry['id']) ?>" class="explorer-row selectable-row <?= $selectedEntryId === (int)$entry['id'] ? 'selected-row target-selected-row' : '' ?>" data-id="<?= e((string)$entry['id']) ?>" data-entry-type="<?= e((string)$entry['entry_type']) ?>" data-entry-name="<?= e((string)$entry['name']) ?>" data-url="<?= e($entryUrl) ?>" data-size="<?= e((string)(int)$entry['size_bytes']) ?>" data-is-file="<?= $entry['entry_type'] === 'file' ? '1' : '0' ?>">
                        <td><?= $entry['entry_type'] === 'folder' ? '📁' : '📄' ?> <?= fp_highlight((string)$entry['name'], $highlightTerms) ?></td><td><?= e($entry['entry_type'] === 'folder' ? 'Folder' : strtoupper((string)$entry['extension']) . ' File') ?></td><td><?= $entry['entry_type'] === 'file' ? e(format_bytes((int)$entry['size_bytes'])) : '' ?></td><td><?= e((string)$entry['modified_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$children): ?><tr><td colspan="4">No entries found in this folder.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php if ($explorerTotalItems > EXPLORER_PAGE_SIZE): ?>
            <div class="status-bar">Page <?= e((string)$explorerPage) ?> of <?= e((string)$explorerTotalPages) ?>. <a class="button" href="<?= e(fp_link(['explorer_page' => max(1, $explorerPage - 1)], true)) ?>">Previous</a> <a class="button" href="<?= e(fp_link(['explorer_page' => min($explorerTotalPages, $explorerPage + 1)], true)) ?>">Next</a></div>
        <?php endif; ?>
        <div class="status-bar" id="explorer-status" data-total-count="<?= e((string)$explorerTotalItems) ?>" data-total-size="<?= e((string)$explorerTotalSize) ?>" data-file-count="<?= e((string)$explorerFileCount) ?>" data-folder-count="<?= e((string)$explorerFolderCount) ?>"></div>
    </div>
</div>
<?php endif; ?>

<div id="context-menu" class="context-menu"><button type="button" data-context-action="add_category">Add folder to category</button><button type="button" data-context-action="remove_category">Remove folder from category</button><hr><button type="button" data-context-action="rename">Rename...</button><button type="button" data-context-action="move">Move...</button><button type="button" data-context-action="delete">Delete...</button></div>
<div id="drive-context-menu" class="context-menu"><button type="button" data-drive-action="remove_drive">Remove drive from DB...</button></div>

<form method="post" id="context-form"><input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? 'files.php') ?>"><input type="hidden" name="action" id="context-action-input"><input type="hidden" name="selected_ids" id="context-selected-ids"><input type="hidden" name="drive_id" id="context-drive-id">
    <div id="context-dialog" class="context-dialog hidden"><div class="context-dialog-card"><h3 id="context-dialog-title">Action</h3><div id="context-category-area"><label>Category <select name="context_category_id"><?php foreach ($categories as $category): ?><option value="<?= e((string)$category['id']) ?>"><?= e((string)$category['name']) ?></option><?php endforeach; ?></select></label></div><div id="context-move-area"><label>Destination drive <select name="destination_drive_id"><?php foreach ($drives as $drive): ?><option value="<?= e((string)$drive['id']) ?>"><?= e((string)$drive['friendly_name']) ?></option><?php endforeach; ?></select></label></div><div id="context-rename-area"><label>New name <input type="text" name="new_name" id="context-new-name"></label></div><p id="context-dialog-note" class="muted"></p><p><button type="submit">Confirm</button> <button type="button" id="context-cancel-button">Cancel</button></p></div></div>
</form>

<script>
function formatBytes(bytes){bytes=Number(bytes||0);const u=['B','KB','MB','GB','TB','PB'];let i=0;while(bytes>=1024&&i<u.length-1){bytes/=1024;i++;}return i===0?Math.round(bytes)+' '+u[i]:bytes.toFixed(2)+' '+u[i];}
function updateStatus(table){const s=document.getElementById(table.getAttribute('data-status-target'));if(!s)return;const rows=table.querySelectorAll('.selectable-row.selected-row');let size=0;rows.forEach(r=>{if(r.dataset.isFile==='1')size+=Number(r.dataset.size||0);});s.textContent='Items: '+s.dataset.totalCount+' | Files: '+s.dataset.fileCount+' | Folders: '+s.dataset.folderCount+' | Total file size: '+formatBytes(s.dataset.totalSize)+' | Selected: '+rows.length+' | Selected size: '+formatBytes(size);}
function selectRow(row,event){const table=row.closest('.selectable-table');if(!table)return;if(!event.ctrlKey&&!event.metaKey){table.querySelectorAll('.selected-row').forEach(r=>r.classList.remove('selected-row'));}row.classList.add('selected-row');updateStatus(table);}
function selectedIds(row){const table=row.closest('.selectable-table');const selected=table?Array.from(table.querySelectorAll('.selectable-row.selected-row')):[row];const rows=selected.includes(row)?selected:[row];return rows.map(r=>r.dataset.id).filter(Boolean);}
function selectedTypes(row){const table=row.closest('.selectable-table');const selected=table?Array.from(table.querySelectorAll('.selectable-row.selected-row')):[row];const rows=selected.includes(row)?selected:[row];return rows.map(r=>r.dataset.entryType);}
document.querySelectorAll('.explorer-row,.search-result-row').forEach(row=>{row.addEventListener('click',e=>{if(row.classList.contains('selectable-row'))selectRow(row,e);});row.addEventListener('dblclick',()=>{if(row.dataset.url)location.href=row.dataset.url;});});
document.querySelectorAll('.tree-drive,.tree-folder').forEach(row=>{row.addEventListener('click',()=>{document.querySelectorAll('.tree-drive,.tree-folder').forEach(r=>r.classList.remove('selected-row'));row.classList.add('selected-row');});row.addEventListener('dblclick',()=>{if(row.dataset.url)location.href=row.dataset.url;});});
const menu=document.getElementById('context-menu'),driveMenu=document.getElementById('drive-context-menu'),dialog=document.getElementById('context-dialog');let contextRow=null,contextDriveId=null;
document.querySelectorAll('.explorer-row.selectable-row').forEach(row=>{row.addEventListener('contextmenu',e=>{e.preventDefault();contextRow=row;selectRow(row,{ctrlKey:true,metaKey:false});const allFolders=selectedTypes(row).every(t=>t==='folder');const selectedCount=selectedIds(row).length;menu.querySelector('[data-context-action="add_category"]').disabled=!allFolders;menu.querySelector('[data-context-action="remove_category"]').disabled=!allFolders;menu.querySelector('[data-context-action="rename"]').disabled=selectedCount!==1;menu.style.left=e.pageX+'px';menu.style.top=e.pageY+'px';menu.classList.add('visible');driveMenu.classList.remove('visible');});});
document.querySelectorAll('.tree-drive').forEach(row=>{row.addEventListener('contextmenu',e=>{e.preventDefault();contextDriveId=row.dataset.driveId;driveMenu.style.left=e.pageX+'px';driveMenu.style.top=e.pageY+'px';driveMenu.classList.add('visible');menu.classList.remove('visible');});});
document.addEventListener('click',e=>{if(!menu.contains(e.target))menu.classList.remove('visible');if(!driveMenu.contains(e.target))driveMenu.classList.remove('visible');});
document.querySelectorAll('[data-context-action]').forEach(btn=>{btn.addEventListener('click',()=>{if(!contextRow||btn.disabled)return;const action=btn.dataset.contextAction;document.getElementById('context-selected-ids').value=selectedIds(contextRow).join(',');document.getElementById('context-drive-id').value='';document.getElementById('context-new-name').value=contextRow.dataset.entryName||'';document.getElementById('context-category-area').style.display=['add_category','remove_category'].includes(action)?'block':'none';document.getElementById('context-move-area').style.display=action==='move'?'block':'none';document.getElementById('context-rename-area').style.display=action==='rename'?'block':'none';document.getElementById('context-action-input').value={add_category:'add_category',remove_category:'remove_category',rename:'queue_rename',move:'queue_move',delete:'queue_delete'}[action];document.getElementById('context-dialog-title').textContent=btn.textContent;document.getElementById('context-dialog-note').textContent=action==='delete'?'Confirm delete job for selected item(s). This will queue a background job.':(['move','rename'].includes(action)?'This queues a background operation job. The disk-modifying executor still needs to be completed safely.':'Category changes apply to selected folders only.');menu.classList.remove('visible');dialog.classList.remove('hidden');});});
document.querySelector('[data-drive-action="remove_drive"]').addEventListener('click',()=>{if(!contextDriveId)return;document.getElementById('context-action-input').value='remove_drive';document.getElementById('context-selected-ids').value='1';document.getElementById('context-drive-id').value=contextDriveId;document.getElementById('context-category-area').style.display='none';document.getElementById('context-move-area').style.display='none';document.getElementById('context-rename-area').style.display='none';document.getElementById('context-dialog-title').textContent='Remove drive from DB';document.getElementById('context-dialog-note').textContent='This removes the drive record, file inventory, scan runs, and related scan jobs from the database. It does not delete files from disk.';driveMenu.classList.remove('visible');dialog.classList.remove('hidden');});
document.getElementById('context-form').addEventListener('submit',e=>{const action=document.getElementById('context-action-input').value;if(['queue_delete','remove_drive'].includes(action)&&!confirm('Are you sure?'))e.preventDefault();});
document.getElementById('context-cancel-button').addEventListener('click',()=>dialog.classList.add('hidden'));
const target=document.querySelector('.target-selected-row');if(target){target.classList.add('selected-row');const table=target.closest('.selectable-table');if(table)updateStatus(table);target.scrollIntoView({block:'center',inline:'nearest'});}document.querySelectorAll('.selectable-table').forEach(updateStatus);
</script>
<?php render_footer(); ?>
