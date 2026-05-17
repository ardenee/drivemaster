<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$drives = db()->query('SELECT id, friendly_name, mount_path FROM drives ORDER BY friendly_name')->fetchAll();
$categories = db()->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$driveId = (int)($_GET['drive_id'] ?? ($drives[0]['id'] ?? 0));
$currentPath = trim($_GET['path'] ?? '');
$selectedEntryId = (int)($_GET['selected_entry_id'] ?? 0);
$expandedDriveId = (int)($_GET['expanded_drive_id'] ?? 0);
$sort = $_GET['sort'] ?? 'name';
$dir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$nextDir = $dir === 'asc' ? 'desc' : 'asc';

$searchText = trim($_GET['search'] ?? '');
$scopeDriveId = (int)($_GET['scope_drive_id'] ?? 0);
$entryTypeFilter = $_GET['entry_type'] ?? 'any';
$extensionFilter = trim($_GET['extension'] ?? '');
$categoryId = (int)($_GET['category_id'] ?? 0);
$pathFilter = trim($_GET['path_contains'] ?? '');
$minSize = trim($_GET['min_size'] ?? '');
$maxSize = trim($_GET['max_size'] ?? '');
$modifiedFrom = trim($_GET['modified_from'] ?? '');
$modifiedTo = trim($_GET['modified_to'] ?? '');
$resultLimit = $_GET['result_limit'] ?? '1000';
$resultSort = $_GET['result_sort'] ?? 'drive';
$resultDir = strtolower($_GET['result_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$resultNextDir = $resultDir === 'asc' ? 'desc' : 'asc';
$hasSearch = $searchText !== '' || $extensionFilter !== '' || $categoryId > 0 || $pathFilter !== '' || $minSize !== '' || $maxSize !== '' || $modifiedFrom !== '' || $modifiedTo !== '' || $entryTypeFilter !== 'any' || $scopeDriveId > 0;

$searchTerms = array_values(array_filter(array_map('trim', explode(',', $searchText)), static fn(string $term): bool => $term !== ''));
$extensionTerms = array_values(array_filter(array_map(static fn(string $term): string => strtolower(ltrim(trim($term), '.')), explode(',', $extensionFilter)), static fn(string $term): bool => $term !== ''));
$highlightTerms = array_values(array_filter(array_merge($searchTerms, $extensionTerms, [$pathFilter]), static fn(string $term): bool => trim($term) !== ''));

$sortSql = match ($sort) {
    'type' => "CASE WHEN entry_type = 'folder' THEN 'Folder' ELSE COALESCE(extension, '') END",
    'size' => 'size_bytes',
    'modified' => 'modified_at',
    default => 'name',
};

$resultSortSql = match ($resultSort) {
    'name' => 'f.name',
    'type' => "CASE WHEN f.entry_type = 'folder' THEN 'Folder' ELSE COALESCE(f.extension, '') END",
    'path' => 'f.relative_path',
    'size' => 'f.size_bytes',
    'modified' => 'f.modified_at',
    default => 'd.friendly_name',
};

function files_link(array $params): string
{
    $merged = array_merge($_GET, $params);
    foreach ($merged as $key => $value) {
        if ($value === null) {
            unset($merged[$key]);
        }
    }
    return 'files.php?' . http_build_query($merged);
}

function explorer_link(array $params): string
{
    return files_link($params) . '#explorer-panel';
}

function path_parent(string $path): string
{
    $path = trim($path, '/');
    if ($path === '') {
        return '';
    }
    $parts = explode('/', $path);
    array_pop($parts);
    return implode('/', $parts);
}

function immediate_parent_expr(): string
{
    return "CASE WHEN LOCATE('/', relative_path) = 0 THEN '' ELSE SUBSTRING(relative_path, 1, LENGTH(relative_path) - LENGTH(SUBSTRING_INDEX(relative_path, '/', -1)) - 1) END";
}

function folder_children(int $driveId, string $parentPath): array
{
    $parentExpr = immediate_parent_expr();
    $stmt = db()->prepare("SELECT id, relative_path, name FROM file_entries WHERE drive_id = ? AND is_missing = 0 AND entry_type = 'folder' AND {$parentExpr} = ? ORDER BY name ASC");
    $stmt->execute([$driveId, $parentPath]);
    return $stmt->fetchAll();
}

function path_is_on_branch(string $candidate, string $activePath): bool
{
    $candidate = trim($candidate, '/');
    $activePath = trim($activePath, '/');
    return $candidate !== '' && ($candidate === $activePath || str_starts_with($activePath, $candidate . '/'));
}

function highlight_match(?string $value, array $terms): string
{
    $escaped = e($value ?? '');
    foreach ($terms as $term) {
        $term = trim($term);
        if ($term === '') {
            continue;
        }
        $escapedTerm = preg_quote(e($term), '/');
        $escaped = preg_replace('/(' . $escapedTerm . ')/iu', '<mark>$1</mark>', $escaped) ?? $escaped;
    }
    return $escaped;
}

function render_folder_tree(int $driveId, string $parentPath, string $activePath, int $level = 0): void
{
    foreach (folder_children($driveId, $parentPath) as $folder) {
        $folderPath = $folder['relative_path'];
        $isActive = $folderPath === $activePath;
        $isOpen = path_is_on_branch($folderPath, $activePath);
        $padding = 18 + ($level * 18);
        ?>
        <div class="tree-folder <?= $isActive ? 'selected selected-row' : '' ?>" style="padding-left: <?= e((string)$padding) ?>px" data-url="<?= e(explorer_link(['drive_id' => $driveId, 'path' => $folderPath, 'expanded_drive_id' => $driveId, 'selected_entry_id' => null])) ?>">
            <span class="tree-toggle"><?= $isOpen ? '▾' : '▸' ?></span><span class="folder-icon">📁</span> <?= e($folder['name']) ?>
        </div>
        <?php
        if ($isOpen) {
            render_folder_tree($driveId, $folderPath, $activePath, $level + 1);
        }
    }
}

$selectedDrive = null;
foreach ($drives as $drive) {
    if ((int)$drive['id'] === $driveId) {
        $selectedDrive = $drive;
        break;
    }
}

$children = [];
$breadcrumbs = [];
$searchResults = [];
$explorerTotalSize = 0;
$explorerFileCount = 0;
$explorerFolderCount = 0;
$searchTotalSize = 0;
$searchFileCount = 0;
$searchFolderCount = 0;

if ($driveId > 0) {
    $parentExpr = immediate_parent_expr();
    $sql = "SELECT id, relative_path, name, extension, entry_type, size_bytes, modified_at FROM file_entries WHERE drive_id = ? AND is_missing = 0 AND {$parentExpr} = ? ORDER BY CASE WHEN entry_type = 'folder' THEN 0 ELSE 1 END ASC, {$sortSql} {$dir}, name ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute([$driveId, $currentPath]);
    $children = $stmt->fetchAll();

    foreach ($children as $entry) {
        if ($entry['entry_type'] === 'file') {
            $explorerFileCount++;
            $explorerTotalSize += (int)$entry['size_bytes'];
        } else {
            $explorerFolderCount++;
        }
    }

    $running = '';
    foreach (array_filter(explode('/', $currentPath), static fn(string $part): bool => $part !== '') as $part) {
        $running = $running === '' ? $part : $running . '/' . $part;
        $breadcrumbs[] = ['name' => $part, 'path' => $running];
    }
}

if ($hasSearch) {
    $where = ['f.is_missing = 0'];
    $params = [];

    if ($scopeDriveId > 0) {
        $where[] = 'f.drive_id = ?';
        $params[] = $scopeDriveId;
    }
    if ($searchTerms) {
        $termClauses = [];
        foreach ($searchTerms as $term) {
            $termClauses[] = '(f.name LIKE ? OR f.relative_path LIKE ?)';
            $params[] = '%' . $term . '%';
            $params[] = '%' . $term . '%';
        }
        $where[] = '(' . implode(' OR ', $termClauses) . ')';
    }
    if ($entryTypeFilter === 'file' || $entryTypeFilter === 'folder') {
        $where[] = 'f.entry_type = ?';
        $params[] = $entryTypeFilter;
    }
    if ($extensionTerms) {
        $placeholders = implode(',', array_fill(0, count($extensionTerms), '?'));
        $where[] = "f.extension IN ({$placeholders})";
        array_push($params, ...$extensionTerms);
    }
    if ($categoryId > 0) {
        $where[] = "EXISTS (
            SELECT 1 FROM category_assignments ca
            WHERE ca.category_id = ?
            AND (
                ca.file_entry_id = f.id
                OR (ca.assignment_type = 'extension_rule' AND ca.extension = f.extension)
                OR (ca.assignment_type = 'folder_rule' AND ca.drive_id = f.drive_id AND ca.path_prefix IS NOT NULL AND f.relative_path LIKE CONCAT(ca.path_prefix, '%'))
            )
        )";
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

    $limitSql = '';
    if ($resultLimit !== 'none') {
        $limitSql = ' LIMIT ' . max(1, min(10000, (int)$resultLimit));
    }

    $stmt = db()->prepare('SELECT f.*, d.friendly_name FROM file_entries f JOIN drives d ON d.id = f.drive_id WHERE ' . implode(' AND ', $where) . " ORDER BY {$resultSortSql} {$resultDir}, f.relative_path ASC" . $limitSql);
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

render_header('Files');
?>
<div class="card">
    <h2>File Search</h2>
    <form method="get">
        <div class="form-grid compact-search">
            <label>Search text <input type="text" name="search" value="<?= e($searchText) ?>" placeholder="comma separated, e.g. invoice, photo"></label>
            <label>Search scope
                <select name="scope_drive_id">
                    <option value="0" <?= $scopeDriveId === 0 ? 'selected' : '' ?>>All drives</option>
                    <?php foreach ($drives as $drive): ?>
                        <option value="<?= e((string)$drive['id']) ?>" <?= $scopeDriveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Entry type
                <select name="entry_type">
                    <option value="any" <?= $entryTypeFilter === 'any' ? 'selected' : '' ?>>Files and folders</option>
                    <option value="file" <?= $entryTypeFilter === 'file' ? 'selected' : '' ?>>Files only</option>
                    <option value="folder" <?= $entryTypeFilter === 'folder' ? 'selected' : '' ?>>Folders only</option>
                </select>
            </label>
            <label>Category
                <select name="category_id">
                    <option value="0">Any category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string)$category['id']) ?>" <?= $categoryId === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>File extensions <input type="text" name="extension" value="<?= e($extensionFilter) ?>" placeholder="comma separated, e.g. mp3, jpg, zip"></label>
            <label>Full path contains <input type="text" name="path_contains" value="<?= e($pathFilter) ?>"></label>
            <label>Min size bytes <input type="number" name="min_size" min="0" value="<?= e($minSize) ?>"></label>
            <label>Max size bytes <input type="number" name="max_size" min="0" value="<?= e($maxSize) ?>"></label>
            <label>Modified from <input type="date" name="modified_from" value="<?= e($modifiedFrom) ?>"></label>
            <label>Modified to <input type="date" name="modified_to" value="<?= e($modifiedTo) ?>"></label>
            <label>Result limit
                <select name="result_limit">
                    <?php foreach (['100' => '100', '500' => '500', '1000' => '1000', '5000' => '5000', '10000' => '10000', 'none' => 'No limit'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $resultLimit === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <input type="hidden" name="result_sort" value="<?= e($resultSort) ?>">
        <input type="hidden" name="result_dir" value="<?= e($resultDir) ?>">
        <p><button type="submit">Search</button> <a class="button" href="files.php">Clear</a></p>
    </form>
</div>

<?php if ($hasSearch): ?>
<div class="card search-results-card">
    <h2>Search results</h2>
    <p class="muted">Highlighted text shows why a result matched. Double-click a result to open/select it below. Drag the lower edge of the frame to resize.</p>
    <div class="search-results-scroll">
        <table class="search-results-table selectable-table" data-status-target="search-status">
            <thead><tr>
                <th><a href="<?= e(files_link(['result_sort' => 'drive', 'result_dir' => $resultSort === 'drive' ? $resultNextDir : 'asc'])) ?>">Drive<?= $resultSort === 'drive' ? e($resultDir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                <th><a href="<?= e(files_link(['result_sort' => 'name', 'result_dir' => $resultSort === 'name' ? $resultNextDir : 'asc'])) ?>">Name<?= $resultSort === 'name' ? e($resultDir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                <th><a href="<?= e(files_link(['result_sort' => 'type', 'result_dir' => $resultSort === 'type' ? $resultNextDir : 'asc'])) ?>">Type<?= $resultSort === 'type' ? e($resultDir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                <th><a href="<?= e(files_link(['result_sort' => 'path', 'result_dir' => $resultSort === 'path' ? $resultNextDir : 'asc'])) ?>">Path<?= $resultSort === 'path' ? e($resultDir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                <th><a href="<?= e(files_link(['result_sort' => 'size', 'result_dir' => $resultSort === 'size' ? $resultNextDir : 'asc'])) ?>">Size<?= $resultSort === 'size' ? e($resultDir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                <th><a href="<?= e(files_link(['result_sort' => 'modified', 'result_dir' => $resultSort === 'modified' ? $resultNextDir : 'asc'])) ?>">Modified<?= $resultSort === 'modified' ? e($resultDir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
            </tr></thead>
            <tbody>
                <?php foreach ($searchResults as $result): ?>
                    <?php
                    $targetPath = path_parent($result['relative_path']);
                    $targetUrl = explorer_link([
                        'drive_id' => $result['drive_id'],
                        'path' => $targetPath,
                        'expanded_drive_id' => $result['drive_id'],
                        'selected_entry_id' => $result['id'],
                    ]);
                    ?>
                    <tr class="search-result-row selectable-row" data-url="<?= e($targetUrl) ?>" data-size="<?= e((string)(int)$result['size_bytes']) ?>" data-is-file="<?= $result['entry_type'] === 'file' ? '1' : '0' ?>">
                        <td class="wrap-cell"><?= e($result['friendly_name']) ?></td>
                        <td class="wrap-cell"><?= $result['entry_type'] === 'folder' ? '📁' : '📄' ?> <?= highlight_match($result['name'], $highlightTerms) ?></td>
                        <td class="wrap-cell"><?= e($result['entry_type'] === 'folder' ? 'Folder' : (($result['extension'] ?? '') !== '' ? strtoupper($result['extension']) . ' File' : 'File')) ?></td>
                        <td class="wrap-cell path-cell"><?= highlight_match($result['relative_path'], $highlightTerms) ?></td>
                        <td><?= $result['entry_type'] === 'file' ? e(format_bytes((int)$result['size_bytes'])) : '' ?></td>
                        <td><?= e($result['modified_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$searchResults): ?>
                    <tr><td colspan="6">No results found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="status-bar" id="search-status" data-total-count="<?= e((string)count($searchResults)) ?>" data-total-size="<?= e((string)$searchTotalSize) ?>" data-file-count="<?= e((string)$searchFileCount) ?>" data-folder-count="<?= e((string)$searchFolderCount) ?>">
        Items: <?= e((string)count($searchResults)) ?> | Files: <?= e((string)$searchFileCount) ?> | Folders: <?= e((string)$searchFolderCount) ?> | Total file size: <?= e(format_bytes($searchTotalSize)) ?> | Selected: 0 | Selected size: 0 B
    </div>
</div>
<?php endif; ?>

<?php if (!$selectedDrive): ?>
    <div class="card"><p>No drives have been scanned yet.</p></div>
<?php else: ?>
    <div class="card explorer-wrap" id="explorer-panel">
        <div class="explorer-sidebar">
            <h3>Drives</h3>
            <?php foreach ($drives as $drive): ?>
                <?php
                $thisDriveId = (int)$drive['id'];
                $isSelectedDrive = $driveId === $thisDriveId;
                $isExpandedDrive = $expandedDriveId === $thisDriveId;
                $driveUrl = explorer_link(['drive_id' => $thisDriveId, 'path' => '', 'expanded_drive_id' => $isExpandedDrive ? 0 : $thisDriveId, 'selected_entry_id' => null]);
                ?>
                <div class="tree-drive <?= $isSelectedDrive ? 'selected' : '' ?>" data-url="<?= e($driveUrl) ?>">
                    <span class="tree-toggle"><?= $isExpandedDrive ? '▾' : '▸' ?></span> 💽 <?= e($drive['friendly_name']) ?>
                </div>
                <?php if ($isExpandedDrive): ?>
                    <?php render_folder_tree($thisDriveId, '', $isSelectedDrive ? $currentPath : '', 0); ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="explorer-main">
            <div class="breadcrumbs">
                <a href="<?= e(explorer_link(['path' => '', 'drive_id' => $driveId, 'expanded_drive_id' => $driveId, 'selected_entry_id' => null])) ?>"><?= e($selectedDrive['friendly_name']) ?></a>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    / <a href="<?= e(explorer_link(['path' => $crumb['path'], 'drive_id' => $driveId, 'expanded_drive_id' => $driveId, 'selected_entry_id' => null])) ?>"><?= e($crumb['name']) ?></a>
                <?php endforeach; ?>
            </div>

            <table class="explorer-table selectable-table" data-status-target="explorer-status">
                <thead>
                    <tr>
                        <th><a href="<?= e(explorer_link(['sort' => 'name', 'dir' => $sort === 'name' ? $nextDir : 'asc'])) ?>">Name<?= $sort === 'name' ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                        <th><a href="<?= e(explorer_link(['sort' => 'type', 'dir' => $sort === 'type' ? $nextDir : 'asc'])) ?>">Type<?= $sort === 'type' ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                        <th><a href="<?= e(explorer_link(['sort' => 'size', 'dir' => $sort === 'size' ? $nextDir : 'asc'])) ?>">Size<?= $sort === 'size' ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                        <th><a href="<?= e(explorer_link(['sort' => 'modified', 'dir' => $sort === 'modified' ? $nextDir : 'asc'])) ?>">Modified<?= $sort === 'modified' ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($currentPath !== ''): ?>
                        <tr class="explorer-row" data-url="<?= e(explorer_link(['path' => path_parent($currentPath), 'expanded_drive_id' => $driveId, 'selected_entry_id' => null])) ?>" data-size="0" data-is-file="0">
                            <td>↩ ..</td>
                            <td>Folder</td>
                            <td></td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($children as $entry): ?>
                        <?php $entryUrl = $entry['entry_type'] === 'folder' ? explorer_link(['path' => $entry['relative_path'], 'expanded_drive_id' => $driveId, 'selected_entry_id' => null]) : ''; ?>
                        <tr id="entry-<?= e((string)$entry['id']) ?>" class="explorer-row selectable-row <?= $selectedEntryId === (int)$entry['id'] ? 'selected-row target-selected-row' : '' ?>" data-url="<?= e($entryUrl) ?>" data-size="<?= e((string)(int)$entry['size_bytes']) ?>" data-is-file="<?= $entry['entry_type'] === 'file' ? '1' : '0' ?>">
                            <td class="wrap-cell"><?= $entry['entry_type'] === 'folder' ? '📁' : '📄' ?> <?= highlight_match($entry['name'], $highlightTerms) ?></td>
                            <td class="wrap-cell"><?= e($entry['entry_type'] === 'folder' ? 'Folder' : (($entry['extension'] ?? '') !== '' ? strtoupper($entry['extension']) . ' File' : 'File')) ?></td>
                            <td><?= $entry['entry_type'] === 'file' ? e(format_bytes((int)$entry['size_bytes'])) : '' ?></td>
                            <td><?= e($entry['modified_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$children): ?>
                        <tr><td colspan="4">No entries found in this folder.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="status-bar" id="explorer-status" data-total-count="<?= e((string)count($children)) ?>" data-total-size="<?= e((string)$explorerTotalSize) ?>" data-file-count="<?= e((string)$explorerFileCount) ?>" data-folder-count="<?= e((string)$explorerFolderCount) ?>">
                Items: <?= e((string)count($children)) ?> | Files: <?= e((string)$explorerFileCount) ?> | Folders: <?= e((string)$explorerFolderCount) ?> | Total file size: <?= e(format_bytes($explorerTotalSize)) ?> | Selected: 0 | Selected size: 0 B
            </div>
        </div>
    </div>
<?php endif; ?>
<script>
function formatBytes(bytes) {
    bytes = Number(bytes || 0);
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let index = 0;
    while (bytes >= 1024 && index < units.length - 1) {
        bytes /= 1024;
        index++;
    }
    return index === 0 ? Math.round(bytes) + ' ' + units[index] : bytes.toFixed(2) + ' ' + units[index];
}

function updateStatus(table) {
    const status = document.getElementById(table.getAttribute('data-status-target'));
    if (!status) {
        return;
    }
    const selected = table.querySelectorAll('.selectable-row.selected-row');
    let selectedSize = 0;
    selected.forEach(function (row) {
        if (row.getAttribute('data-is-file') === '1') {
            selectedSize += Number(row.getAttribute('data-size') || 0);
        }
    });
    status.textContent = 'Items: ' + status.getAttribute('data-total-count') +
        ' | Files: ' + status.getAttribute('data-file-count') +
        ' | Folders: ' + status.getAttribute('data-folder-count') +
        ' | Total file size: ' + formatBytes(status.getAttribute('data-total-size')) +
        ' | Selected: ' + selected.length +
        ' | Selected size: ' + formatBytes(selectedSize);
}

document.querySelectorAll('.tree-drive, .tree-folder, .explorer-row, .search-result-row').forEach(function (row) {
    row.addEventListener('click', function (event) {
        const table = row.closest('.selectable-table');
        if (table && row.classList.contains('selectable-row')) {
            if (!event.ctrlKey && !event.metaKey && !event.shiftKey) {
                table.querySelectorAll('.selected-row').forEach(function (selected) { selected.classList.remove('selected-row'); });
            }
            row.classList.toggle('selected-row', true);
            updateStatus(table);
            return;
        }
        document.querySelectorAll('.selected-row').forEach(function (selected) { selected.classList.remove('selected-row'); });
        row.classList.add('selected-row');
    });
    row.addEventListener('dblclick', function () {
        const url = row.getAttribute('data-url');
        if (url) {
            window.location.href = url;
        }
    });
});

const target = document.querySelector('.target-selected-row');
if (target) {
    target.classList.add('selected-row');
    const table = target.closest('.selectable-table');
    if (table) {
        updateStatus(table);
    }
    target.scrollIntoView({ block: 'center', inline: 'nearest' });
}

document.querySelectorAll('.selectable-table').forEach(updateStatus);
</script>
<?php render_footer(); ?>
