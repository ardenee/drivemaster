<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$drives = db()->query('SELECT id, friendly_name, mount_path FROM drives ORDER BY friendly_name')->fetchAll();
$driveId = (int)($_GET['drive_id'] ?? ($drives[0]['id'] ?? 0));
$currentPath = trim($_GET['path'] ?? '');
$selectedEntryId = (int)($_GET['selected_entry_id'] ?? 0);
$expandedDriveId = (int)($_GET['expanded_drive_id'] ?? 0);
$sort = $_GET['sort'] ?? 'name';
$dir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$nextDir = $dir === 'asc' ? 'desc' : 'asc';

$searchText = trim($_GET['search'] ?? '');
$searchMode = $_GET['search_mode'] ?? 'all';
$searchDriveId = (int)($_GET['search_drive_id'] ?? 0);
$entryTypeFilter = $_GET['entry_type'] ?? 'any';
$extensionFilter = trim($_GET['extension'] ?? '');
$folderNameFilter = trim($_GET['folder_name'] ?? '');
$pathFilter = trim($_GET['path_contains'] ?? '');
$minSize = trim($_GET['min_size'] ?? '');
$maxSize = trim($_GET['max_size'] ?? '');
$modifiedFrom = trim($_GET['modified_from'] ?? '');
$modifiedTo = trim($_GET['modified_to'] ?? '');
$hasSearch = $searchText !== '' || $extensionFilter !== '' || $folderNameFilter !== '' || $pathFilter !== '' || $minSize !== '' || $maxSize !== '' || $modifiedFrom !== '' || $modifiedTo !== '' || $entryTypeFilter !== 'any' || ($searchMode === 'drive' && $searchDriveId > 0);
$highlightTerms = array_values(array_filter([$searchText, $extensionFilter, $folderNameFilter, $pathFilter], static fn(string $term): bool => trim($term) !== ''));

$sortSql = match ($sort) {
    'type' => "CASE WHEN entry_type = 'folder' THEN 'Folder' ELSE COALESCE(extension, '') END",
    'size' => 'size_bytes',
    'modified' => 'modified_at',
    default => 'name',
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
            <span class="tree-toggle"><?= $isOpen ? '▾' : '▸' ?></span> <?= e($folder['name']) ?>
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

if ($driveId > 0) {
    $parentExpr = immediate_parent_expr();
    $sql = "SELECT id, relative_path, name, extension, entry_type, size_bytes, modified_at FROM file_entries WHERE drive_id = ? AND is_missing = 0 AND {$parentExpr} = ? ORDER BY CASE WHEN entry_type = 'folder' THEN 0 ELSE 1 END ASC, {$sortSql} {$dir}, name ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute([$driveId, $currentPath]);
    $children = $stmt->fetchAll();

    $running = '';
    foreach (array_filter(explode('/', $currentPath), static fn(string $part): bool => $part !== '') as $part) {
        $running = $running === '' ? $part : $running . '/' . $part;
        $breadcrumbs[] = ['name' => $part, 'path' => $running];
    }
}

if ($hasSearch) {
    $where = ['f.is_missing = 0'];
    $params = [];

    if ($searchMode === 'drive' && $searchDriveId > 0) {
        $where[] = 'f.drive_id = ?';
        $params[] = $searchDriveId;
    }
    if ($searchText !== '') {
        $where[] = '(f.name LIKE ? OR f.relative_path LIKE ?)';
        $params[] = '%' . $searchText . '%';
        $params[] = '%' . $searchText . '%';
    }
    if ($entryTypeFilter === 'file' || $entryTypeFilter === 'folder') {
        $where[] = 'f.entry_type = ?';
        $params[] = $entryTypeFilter;
    }
    if ($extensionFilter !== '') {
        $where[] = 'f.extension = ?';
        $params[] = strtolower(ltrim($extensionFilter, '.'));
    }
    if ($folderNameFilter !== '') {
        $where[] = 'f.relative_path LIKE ?';
        $params[] = '%' . $folderNameFilter . '%';
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

    $stmt = db()->prepare('SELECT f.*, d.friendly_name FROM file_entries f JOIN drives d ON d.id = f.drive_id WHERE ' . implode(' AND ', $where) . ' ORDER BY d.friendly_name ASC, f.entry_type ASC, f.relative_path ASC LIMIT 1000');
    $stmt->execute($params);
    $searchResults = $stmt->fetchAll();
}

render_header('Files');
?>
<div class="card">
    <h2>File Search</h2>
    <form method="get">
        <div class="form-grid compact-search">
            <label>Search text <input type="text" name="search" value="<?= e($searchText) ?>" placeholder="file or path text"></label>
            <label>Search scope
                <select name="search_mode">
                    <option value="all" <?= $searchMode === 'all' ? 'selected' : '' ?>>All drives</option>
                    <option value="drive" <?= $searchMode === 'drive' ? 'selected' : '' ?>>Selected drive</option>
                </select>
            </label>
            <label>Drive for selected-drive search
                <select name="search_drive_id">
                    <option value="0">Current/any</option>
                    <?php foreach ($drives as $drive): ?>
                        <option value="<?= e((string)$drive['id']) ?>" <?= $searchDriveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name']) ?></option>
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
            <label>File extension <input type="text" name="extension" value="<?= e($extensionFilter) ?>" placeholder="mp3, jpg, zip"></label>
            <label>Folder/path contains <input type="text" name="folder_name" value="<?= e($folderNameFilter) ?>" placeholder="Music, Photos, Backup"></label>
            <label>Full path contains <input type="text" name="path_contains" value="<?= e($pathFilter) ?>"></label>
            <label>Min size bytes <input type="number" name="min_size" min="0" value="<?= e($minSize) ?>"></label>
            <label>Max size bytes <input type="number" name="max_size" min="0" value="<?= e($maxSize) ?>"></label>
            <label>Modified from <input type="date" name="modified_from" value="<?= e($modifiedFrom) ?>"></label>
            <label>Modified to <input type="date" name="modified_to" value="<?= e($modifiedTo) ?>"></label>
        </div>
        <p><button type="submit">Search</button> <a class="button" href="files.php">Clear</a></p>
    </form>
</div>

<?php if ($hasSearch): ?>
<div class="card search-results-card">
    <h2>Search results</h2>
    <p class="muted">Showing up to 1000 matches. Highlighted text shows why a result matched. Double-click a result to open/select it below.</p>
    <div class="search-results-scroll">
        <table class="search-results-table">
            <thead><tr><th>Drive</th><th>Name</th><th>Type</th><th>Path</th><th>Size</th><th>Modified</th></tr></thead>
            <tbody>
                <?php foreach ($searchResults as $result): ?>
                    <?php
                    $targetPath = $result['entry_type'] === 'folder' ? path_parent($result['relative_path']) : path_parent($result['relative_path']);
                    $targetUrl = explorer_link([
                        'drive_id' => $result['drive_id'],
                        'path' => $targetPath,
                        'expanded_drive_id' => $result['drive_id'],
                        'selected_entry_id' => $result['id'],
                    ]);
                    ?>
                    <tr class="search-result-row" data-url="<?= e($targetUrl) ?>">
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
</div>
<?php endif; ?>

<div class="card" id="explorer-panel">
    <h2>File Explorer</h2>
    <p class="muted">Left tree is collapsed by default. Single-click selects. Double-click drives/folders/results to open.</p>
</div>

<?php if (!$selectedDrive): ?>
    <div class="card"><p>No drives have been scanned yet.</p></div>
<?php else: ?>
    <div class="card explorer-wrap">
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
                    <span class="tree-toggle"><?= $isExpandedDrive ? '▾' : '▸' ?></span> <?= e($drive['friendly_name']) ?>
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

            <table class="explorer-table">
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
                        <tr class="explorer-row" data-url="<?= e(explorer_link(['path' => path_parent($currentPath), 'expanded_drive_id' => $driveId, 'selected_entry_id' => null])) ?>">
                            <td>↩ ..</td>
                            <td>Folder</td>
                            <td></td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($children as $entry): ?>
                        <?php $entryUrl = $entry['entry_type'] === 'folder' ? explorer_link(['path' => $entry['relative_path'], 'expanded_drive_id' => $driveId, 'selected_entry_id' => null]) : ''; ?>
                        <tr id="entry-<?= e((string)$entry['id']) ?>" class="explorer-row <?= $selectedEntryId === (int)$entry['id'] ? 'selected-row target-selected-row' : '' ?>" data-url="<?= e($entryUrl) ?>">
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
        </div>
    </div>
<?php endif; ?>
<script>
document.querySelectorAll('.tree-drive, .tree-folder, .explorer-row, .search-result-row').forEach(function (row) {
    row.addEventListener('click', function () {
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
    target.scrollIntoView({ block: 'center', inline: 'nearest' });
}
</script>
<?php render_footer(); ?>
