<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Repository\CategoryWorkflowRepository;

function cs_parent(string $path): string
{
    $path = trim($path, '/');
    if ($path === '' || !str_contains($path, '/')) {
        return '';
    }
    return substr($path, 0, strrpos($path, '/')) ?: '';
}

function cs_link(array $params = []): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        }
    }
    return 'category-status.php?' . http_build_query($query) . '#category-status-explorer';
}

function cs_category_badge(array $entry): string
{
    $name = trim((string)($entry['category_name'] ?? ''));
    if ($name === '') {
        return '<span class="muted">None</span>';
    }
    $colour = trim((string)($entry['category_color_hex'] ?? ''));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
        $colour = '#d9e0e7';
    }
    return '<span class="category-badge"><span class="category-colour-chip" style="background:' . e($colour) . '"></span>' . e($name) . '</span>';
}

function cs_on_branch(string $folderPath, string $activePath): bool
{
    $folderPath = trim($folderPath, '/');
    $activePath = trim($activePath, '/');
    return $folderPath !== '' && ($folderPath === $activePath || str_starts_with($activePath, $folderPath . '/'));
}

function cs_render_tree(CategoryWorkflowRepository $repo, int $driveId, string $parentPath, string $activePath, string $mode, int $level = 0): void
{
    foreach ($repo->childFolders($driveId, $parentPath, $mode) as $folder) {
        $folderPath = (string)$folder['relative_path'];
        $isOpen = cs_on_branch($folderPath, $activePath);
        $isActive = $folderPath === $activePath;
        $padding = 18 + ($level * 18);
        ?>
        <div class="tree-folder selectable-row <?= $isActive ? 'selected selected-row' : '' ?>" style="padding-left: <?= e((string)$padding) ?>px" data-id="<?= e((string)$folder['id']) ?>" data-entry-type="folder" data-entry-name="<?= e((string)$folder['name']) ?>" data-size="0" data-is-file="0" data-drive-id="<?= e((string)$driveId) ?>" data-path="<?= e($folderPath) ?>" data-level="<?= e((string)$level) ?>" data-loaded="<?= $isOpen ? '1' : '0' ?>" data-url="<?= e(cs_link(['drive_id' => $driveId, 'path' => $folderPath, 'selected_entry_id' => null, 'page' => 1, 'mode' => $mode])) ?>">
            <span class="tree-toggle"><?= $isOpen ? '▾' : '▸' ?></span><span class="folder-icon">📁</span> <?= e((string)$folder['name']) ?>
        </div>
        <?php
        if ($isOpen) {
            cs_render_tree($repo, $driveId, $folderPath, $activePath, $mode, $level + 1);
        }
    }
}

$error = null;
$notice = null;
$children = [];
$breadcrumbs = [];
$totalItems = 0;
$fileCount = 0;
$folderCount = 0;
$totalSize = 0;
$totalPages = 1;
$offset = 0;
$drives = [];
$categories = [];
$repo = new CategoryWorkflowRepository(db());

try {
    $drives = db()->query('SELECT id, friendly_name FROM drives ORDER BY friendly_name')->fetchAll();
    $categories = db()->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$driveId = (int)($_GET['drive_id'] ?? ($drives[0]['id'] ?? 0));
$currentPath = trim((string)($_GET['path'] ?? ''), '/');
$selectedEntryId = (int)($_GET['selected_entry_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$sort = (string)($_GET['sort'] ?? 'name');
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$nextDir = $dir === 'asc' ? 'desc' : 'asc';
$mode = (string)($_GET['mode'] ?? 'unassigned');
$mode = $mode === 'assigned' ? 'assigned' : 'unassigned';
$modeLabel = $mode === 'assigned' ? 'Categorised' : 'Uncategorised';
$showCategoryColumn = $mode === 'assigned';

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

try {
    if ($driveId > 0) {
        $summary = $repo->folderSummary($driveId, $currentPath, $mode);
        $totalItems = (int)$summary['total_items'];
        $fileCount = (int)$summary['file_count'];
        $folderCount = (int)$summary['folder_count'];
        $totalSize = (int)$summary['total_size'];
        $totalPages = max(1, (int)ceil($totalItems / CategoryWorkflowRepository::PAGE_SIZE));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * CategoryWorkflowRepository::PAGE_SIZE;
        $children = $repo->folderChildren($driveId, $currentPath, $sort, $dir, $page, $mode);
        $running = '';
        foreach (array_filter(explode('/', $currentPath), static fn(string $v): bool => $v !== '') as $part) {
            $running = $running === '' ? $part : $running . '/' . $part;
            $breadcrumbs[] = ['name' => $part, 'path' => $running];
        }
    } else {
        $notice = 'Add or scan a drive before using Category Status.';
    }
} catch (Throwable $e) {
    $error = trim(($error ? $error . ' | ' : '') . 'Could not load category status folder: ' . $e->getMessage());
}

render_header('Category Status');
?>
<script>window.driveMasterPageMode = 'category-status';</script>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="card"><strong>Notice:</strong> <?= e($notice) ?></div><?php endif; ?>

<div class="card">
    <h2>Category Status</h2>
    <p class="muted">Browse one drive at a time and switch between uncategorised items that still need assignment and categorised items already covered by a manual category rule.</p>
    <div class="button-row">
        <a class="button <?= $mode === 'unassigned' ? 'selected-row' : '' ?>" href="<?= e(cs_link(['mode' => 'unassigned', 'page' => 1, 'selected_entry_id' => null, 'sort' => 'name'])) ?>">Show uncategorised</a>
        <a class="button <?= $mode === 'assigned' ? 'selected-row' : '' ?>" href="<?= e(cs_link(['mode' => 'assigned', 'page' => 1, 'selected_entry_id' => null])) ?>">Show categorised</a>
        <a class="button" href="category-status-summary.php">Status Summary</a>
        <a class="button" href="category-conflicts.php">Conflicts</a>
    </div>
    <div class="status-bar">Current view: <?= e($modeLabel) ?> items<?= $showCategoryColumn ? ' | Category colours shown' : ' | Category column hidden because these items have no category' ?></div>
</div>

<?php if (!$drives): ?>
<div class="card"><p>No drives have been scanned yet.</p></div>
<?php else: ?>
<div class="card explorer-wrap" id="category-status-explorer">
    <div class="explorer-sidebar">
        <h3>Drives</h3>
        <?php foreach ($drives as $drive): ?>
            <?php $thisDriveId = (int)$drive['id']; $isSelected = $driveId === $thisDriveId; ?>
            <div class="tree-drive <?= $isSelected ? 'selected selected-row' : '' ?>" data-drive-id="<?= e((string)$thisDriveId) ?>" data-loaded="<?= $isSelected ? '1' : '0' ?>" data-url="<?= e(cs_link(['drive_id' => $thisDriveId, 'path' => '', 'selected_entry_id' => null, 'page' => 1, 'mode' => $mode])) ?>">
                <span class="tree-toggle"><?= $isSelected ? '▾' : '▸' ?></span> 💽 <?= e((string)$drive['friendly_name']) ?>
            </div>
            <?php if ($isSelected): ?><?php cs_render_tree($repo, $thisDriveId, '', $currentPath, $mode); ?><?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="explorer-main">
        <div class="breadcrumbs">
            <a href="<?= e(cs_link(['drive_id' => $driveId, 'path' => '', 'page' => 1, 'mode' => $mode])) ?>" data-status-path="" data-drive-id="<?= e((string)$driveId) ?>"><?= e((string)($selectedDrive['friendly_name'] ?? 'Drive')) ?></a>
            <?php foreach ($breadcrumbs as $crumb): ?> / <a href="<?= e(cs_link(['drive_id' => $driveId, 'path' => $crumb['path'], 'page' => 1, 'mode' => $mode])) ?>" data-status-path="<?= e((string)$crumb['path']) ?>" data-drive-id="<?= e((string)$driveId) ?>"><?= e((string)$crumb['name']) ?></a><?php endforeach; ?>
        </div>

        <?php if ($totalItems > CategoryWorkflowRepository::PAGE_SIZE): ?>
            <div class="status-bar status-explorer-pager">Showing <?= e((string)($offset + 1)) ?>-<?= e((string)min($offset + count($children), $totalItems)) ?> of <?= e(number_format($totalItems)) ?> <?= e(strtolower($modeLabel)) ?> entries in this folder. <a class="button" href="<?= e(cs_link(['page' => max(1, $page - 1), 'mode' => $mode])) ?>" data-status-page="<?= e((string)max(1, $page - 1)) ?>">Previous</a> <a class="button" href="<?= e(cs_link(['page' => min($totalPages, $page + 1), 'mode' => $mode])) ?>" data-status-page="<?= e((string)min($totalPages, $page + 1)) ?>">Next</a></div>
        <?php endif; ?>

        <table class="explorer-table selectable-table" data-status-target="category-status-bar">
            <thead><tr><?php foreach (($showCategoryColumn ? ['name' => 'Name', 'type' => 'Type', 'category' => 'Category', 'size' => 'Size', 'modified' => 'Modified'] : ['name' => 'Name', 'type' => 'Type', 'size' => 'Size', 'modified' => 'Modified']) as $key => $label): ?><th><a href="<?= e(cs_link(['sort' => $key, 'dir' => $sort === $key ? $nextDir : 'asc', 'page' => 1, 'mode' => $mode])) ?>"><?= e($label) ?><?= $sort === $key ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php if ($currentPath !== ''): ?><tr class="explorer-row" data-url="<?= e(cs_link(['path' => cs_parent($currentPath), 'page' => 1, 'mode' => $mode])) ?>" data-status-path="<?= e(cs_parent($currentPath)) ?>" data-drive-id="<?= e((string)$driveId) ?>"><td>↩ ..</td><td>Folder</td><?php if ($showCategoryColumn): ?><td></td><?php endif; ?><td></td><td></td></tr><?php endif; ?>
                <?php foreach ($children as $entry): ?>
                    <?php $entryUrl = $entry['entry_type'] === 'folder' ? cs_link(['drive_id' => $driveId, 'path' => $entry['relative_path'], 'selected_entry_id' => null, 'page' => 1, 'mode' => $mode]) : ''; ?>
                    <tr id="entry-<?= e((string)$entry['id']) ?>" class="search-result-row explorer-row selectable-row <?= $selectedEntryId === (int)$entry['id'] ? 'selected-row target-selected-row' : '' ?>" data-id="<?= e((string)$entry['id']) ?>" data-entry-type="<?= e((string)$entry['entry_type']) ?>" data-entry-name="<?= e((string)$entry['name']) ?>" data-url="<?= e($entryUrl) ?>" data-status-path="<?= e($entry['entry_type'] === 'folder' ? (string)$entry['relative_path'] : '') ?>" data-drive-id="<?= e((string)$driveId) ?>" data-size="<?= e((string)(int)$entry['size_bytes']) ?>" data-is-file="<?= $entry['entry_type'] === 'file' ? '1' : '0' ?>">
                        <td><?= $entry['entry_type'] === 'folder' ? '📁' : '📄' ?> <?= e((string)$entry['name']) ?></td>
                        <td><?= e($entry['entry_type'] === 'folder' ? 'Folder' : strtoupper((string)$entry['extension']) . ' File') ?></td>
                        <?php if ($showCategoryColumn): ?><td><?= cs_category_badge($entry) ?></td><?php endif; ?>
                        <td><?= $entry['entry_type'] === 'file' ? e(format_bytes((int)$entry['size_bytes'])) : '' ?></td>
                        <td><?= e((string)$entry['modified_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$children): ?><tr><td colspan="<?= $showCategoryColumn ? '5' : '4' ?>">No <?= e(strtolower($modeLabel)) ?> entries in this folder.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalItems > CategoryWorkflowRepository::PAGE_SIZE): ?>
            <div class="status-bar status-explorer-pager">Page <?= e((string)$page) ?> of <?= e((string)$totalPages) ?>. <a class="button" href="<?= e(cs_link(['page' => max(1, $page - 1), 'mode' => $mode])) ?>" data-status-page="<?= e((string)max(1, $page - 1)) ?>">Previous</a> <a class="button" href="<?= e(cs_link(['page' => min($totalPages, $page + 1), 'mode' => $mode])) ?>" data-status-page="<?= e((string)min($totalPages, $page + 1)) ?>">Next</a></div>
        <?php endif; ?>

        <div class="status-bar" id="category-status-bar" data-mode-label="<?= e($modeLabel) ?>" data-total-count="<?= e((string)$totalItems) ?>" data-total-size="<?= e((string)$totalSize) ?>" data-file-count="<?= e((string)$fileCount) ?>" data-folder-count="<?= e((string)$folderCount) ?>"><?= e($modeLabel) ?> in this folder: <?= e(number_format($totalItems)) ?> | Files: <?= e(number_format($fileCount)) ?> | Folders: <?= e(number_format($folderCount)) ?> | File size: <?= e(format_bytes($totalSize)) ?></div>
    </div>
</div>
<?php endif; ?>

<div id="context-menu" class="context-menu"><button type="button" data-context-action="add_category">Add to category</button><button type="button" data-context-action="remove_category">Remove from category</button><hr><button type="button" data-context-action="rename">Rename...</button><button type="button" data-context-action="move">Move...</button><button type="button" data-context-action="delete">Delete...</button></div>
<div id="drive-context-menu" class="context-menu"><button type="button" data-drive-action="remove_drive">Remove drive from DB...</button></div>

<form method="post" action="files-action.php" id="context-form"><input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? 'category-status.php') ?>"><input type="hidden" name="action" id="context-action-input"><input type="hidden" name="selected_ids" id="context-selected-ids"><input type="hidden" name="drive_id" id="context-drive-id">
    <div id="context-dialog" class="context-dialog hidden"><div class="context-dialog-card"><h3 id="context-dialog-title">Action</h3><div id="context-category-area"><label>Category <select name="context_category_id"><?php foreach ($categories as $category): ?><option value="<?= e((string)$category['id']) ?>"><?= e((string)$category['name']) ?></option><?php endforeach; ?></select></label></div><div id="context-move-area"><label>Destination drive <select name="destination_drive_id"><?php foreach ($drives as $drive): ?><option value="<?= e((string)$drive['id']) ?>"><?= e((string)$drive['friendly_name']) ?></option><?php endforeach; ?></select></label></div><div id="context-rename-area"><label>New name <input type="text" name="new_name" id="context-new-name"></label></div><p id="context-dialog-note" class="muted"></p><p><button type="submit">Confirm</button> <button type="button" id="context-cancel-button">Cancel</button></p></div></div>
</form>

<script src="assets/category-status-ajax.js?ts=<?= e((string)time()) ?>" defer></script>
<?php render_footer(); ?>
