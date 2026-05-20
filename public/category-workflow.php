<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\IgnoredFolderSql;

const CATEGORY_WORKFLOW_PAGE_SIZE = 495;

function cw_parent(string $path): string
{
    $path = trim($path, '/');
    if ($path === '' || !str_contains($path, '/')) {
        return '';
    }
    return substr($path, 0, strrpos($path, '/')) ?: '';
}

function cw_link(array $params = []): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        }
    }
    return 'category-workflow.php?' . http_build_query($query) . '#workflow-explorer';
}

function cw_manual_match_sql(string $alias = 'f'): string
{
    return "EXISTS (SELECT 1 FROM category_manual_assignments cma WHERE cma.file_entry_id = {$alias}.id OR (cma.assignment_type = 'folder' AND cma.drive_id = {$alias}.drive_id AND cma.path_prefix IS NOT NULL AND ({$alias}.relative_path = cma.path_prefix OR {$alias}.relative_path LIKE CONCAT(cma.path_prefix, '/%'))))";
}

function cw_tree_children(int $driveId, string $parentPath): array
{
    $visibleSql = IgnoredFolderSql::excludeCondition('f');
    $unassignedSql = 'NOT ' . cw_manual_match_sql('f');
    $stmt = db()->prepare("SELECT f.id, f.name, f.relative_path FROM file_entries f WHERE f.drive_id = ? AND f.is_missing = 0 AND f.entry_type = 'folder' AND {$visibleSql} AND {$unassignedSql} AND f.parent_path = ? ORDER BY f.name ASC LIMIT 1000");
    $stmt->execute([$driveId, $parentPath]);
    return $stmt->fetchAll();
}

function cw_on_branch(string $folderPath, string $activePath): bool
{
    $folderPath = trim($folderPath, '/');
    $activePath = trim($activePath, '/');
    return $folderPath !== '' && ($folderPath === $activePath || str_starts_with($activePath, $folderPath . '/'));
}

function cw_render_tree(int $driveId, string $parentPath, string $activePath, int $level = 0): void
{
    foreach (cw_tree_children($driveId, $parentPath) as $folder) {
        $folderPath = (string)$folder['relative_path'];
        $isOpen = cw_on_branch($folderPath, $activePath);
        $isActive = $folderPath === $activePath;
        $padding = 18 + ($level * 18);
        ?>
        <div class="tree-folder selectable-row <?= $isActive ? 'selected selected-row' : '' ?>" style="padding-left: <?= e((string)$padding) ?>px" data-id="<?= e((string)$folder['id']) ?>" data-entry-type="folder" data-entry-name="<?= e((string)$folder['name']) ?>" data-size="0" data-is-file="0" data-drive-id="<?= e((string)$driveId) ?>" data-path="<?= e($folderPath) ?>" data-level="<?= e((string)$level) ?>" data-loaded="<?= $isOpen ? '1' : '0' ?>" data-url="<?= e(cw_link(['drive_id' => $driveId, 'path' => $folderPath, 'selected_entry_id' => null, 'page' => 1])) ?>">
            <span class="tree-toggle"><?= $isOpen ? '▾' : '▸' ?></span><span class="folder-icon">📁</span> <?= e((string)$folder['name']) ?>
        </div>
        <?php
        if ($isOpen) {
            cw_render_tree($driveId, $folderPath, $activePath, $level + 1);
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
$drives = [];
$categories = [];

try {
    $drives = db()->query('SELECT id, friendly_name FROM drives ORDER BY friendly_name')->fetchAll();
} catch (Throwable $e) {
    $error = 'Could not load drives: ' . $e->getMessage();
}

try {
    $categories = db()->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $error = trim(($error ? $error . ' | ' : '') . 'Could not load categories: ' . $e->getMessage());
}

$driveId = (int)($_GET['drive_id'] ?? ($drives[0]['id'] ?? 0));
$currentPath = trim((string)($_GET['path'] ?? ''), '/');
$selectedEntryId = (int)($_GET['selected_entry_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * CATEGORY_WORKFLOW_PAGE_SIZE;
$sort = (string)($_GET['sort'] ?? 'name');
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$nextDir = $dir === 'asc' ? 'desc' : 'asc';

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
        $visibleSql = IgnoredFolderSql::excludeCondition('f');
        $unassignedSql = 'NOT ' . cw_manual_match_sql('f');
        $sortSql = match ($sort) {
            'type' => "CASE WHEN f.entry_type = 'folder' THEN 'Folder' ELSE COALESCE(f.extension, '') END",
            'size' => 'f.size_bytes',
            'modified' => 'f.modified_at',
            default => 'f.name',
        };

        $summaryStmt = db()->prepare("SELECT COUNT(*) AS total_items, SUM(f.entry_type = 'file') AS file_count, SUM(f.entry_type = 'folder') AS folder_count, COALESCE(SUM(CASE WHEN f.entry_type = 'file' THEN f.size_bytes ELSE 0 END), 0) AS total_size FROM file_entries f WHERE f.drive_id = ? AND f.is_missing = 0 AND {$visibleSql} AND {$unassignedSql} AND f.parent_path = ?");
        $summaryStmt->execute([$driveId, $currentPath]);
        $summary = $summaryStmt->fetch() ?: [];
        $totalItems = (int)($summary['total_items'] ?? 0);
        $fileCount = (int)($summary['file_count'] ?? 0);
        $folderCount = (int)($summary['folder_count'] ?? 0);
        $totalSize = (int)($summary['total_size'] ?? 0);

        $stmt = db()->prepare("SELECT f.id, f.drive_id, f.relative_path, f.name, f.entry_type, f.extension, f.size_bytes, f.modified_at FROM file_entries f WHERE f.drive_id = ? AND f.is_missing = 0 AND {$visibleSql} AND {$unassignedSql} AND f.parent_path = ? ORDER BY CASE WHEN f.entry_type = 'folder' THEN 0 ELSE 1 END ASC, {$sortSql} {$dir}, f.name ASC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $driveId, PDO::PARAM_INT);
        $stmt->bindValue(2, $currentPath);
        $stmt->bindValue(3, CATEGORY_WORKFLOW_PAGE_SIZE, PDO::PARAM_INT);
        $stmt->bindValue(4, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $children = $stmt->fetchAll();

        $running = '';
        foreach (array_filter(explode('/', $currentPath), static fn(string $v): bool => $v !== '') as $part) {
            $running = $running === '' ? $part : $running . '/' . $part;
            $breadcrumbs[] = ['name' => $part, 'path' => $running];
        }
    } else {
        $notice = 'Add or scan a drive before using Category Workflow.';
    }
} catch (Throwable $e) {
    $error = trim(($error ? $error . ' | ' : '') . 'Could not load workflow folder: ' . $e->getMessage());
}

$totalPages = max(1, (int)ceil($totalItems / CATEGORY_WORKFLOW_PAGE_SIZE));
if ($page > $totalPages) {
    $page = $totalPages;
}

render_header('Category Workflow');
?>
<script>window.driveMasterPageMode = 'category-workflow';</script>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="card"><strong>Notice:</strong> <?= e($notice) ?></div><?php endif; ?>

<div class="card">
    <h2>Category Workflow</h2>
    <p class="muted">Browse one drive at a time. This view only shows files/folders in the current folder that do not have a manual category assignment. Assign a category and the item disappears from this workflow after refresh.</p>
</div>

<?php if (!$drives): ?>
<div class="card"><p>No drives have been scanned yet.</p></div>
<?php else: ?>
<div class="card explorer-wrap" id="workflow-explorer">
    <div class="explorer-sidebar">
        <h3>Drives</h3>
        <?php foreach ($drives as $drive): ?>
            <?php $thisDriveId = (int)$drive['id']; $isSelected = $driveId === $thisDriveId; ?>
            <div class="tree-drive <?= $isSelected ? 'selected selected-row' : '' ?>" data-drive-id="<?= e((string)$thisDriveId) ?>" data-loaded="<?= $isSelected ? '1' : '0' ?>" data-url="<?= e(cw_link(['drive_id' => $thisDriveId, 'path' => '', 'selected_entry_id' => null, 'page' => 1])) ?>">
                <span class="tree-toggle"><?= $isSelected ? '▾' : '▸' ?></span> 💽 <?= e((string)$drive['friendly_name']) ?>
            </div>
            <?php if ($isSelected): ?><?php cw_render_tree($thisDriveId, '', $currentPath); ?><?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="explorer-main">
        <div class="breadcrumbs">
            <a href="<?= e(cw_link(['drive_id' => $driveId, 'path' => '', 'page' => 1])) ?>" data-workflow-path="" data-drive-id="<?= e((string)$driveId) ?>"><?= e((string)($selectedDrive['friendly_name'] ?? 'Drive')) ?></a>
            <?php foreach ($breadcrumbs as $crumb): ?> / <a href="<?= e(cw_link(['drive_id' => $driveId, 'path' => $crumb['path'], 'page' => 1])) ?>" data-workflow-path="<?= e((string)$crumb['path']) ?>" data-drive-id="<?= e((string)$driveId) ?>"><?= e((string)$crumb['name']) ?></a><?php endforeach; ?>
        </div>

        <?php if ($totalItems > CATEGORY_WORKFLOW_PAGE_SIZE): ?>
            <div class="status-bar workflow-explorer-pager">Showing <?= e((string)($offset + 1)) ?>-<?= e((string)min($offset + count($children), $totalItems)) ?> of <?= e(number_format($totalItems)) ?> unassigned entries in this folder. <a class="button" href="<?= e(cw_link(['page' => max(1, $page - 1)])) ?>" data-workflow-page="<?= e((string)max(1, $page - 1)) ?>">Previous</a> <a class="button" href="<?= e(cw_link(['page' => min($totalPages, $page + 1)])) ?>" data-workflow-page="<?= e((string)min($totalPages, $page + 1)) ?>">Next</a></div>
        <?php endif; ?>

        <table class="explorer-table selectable-table" data-status-target="workflow-status">
            <thead><tr><?php foreach (['name' => 'Name', 'type' => 'Type', 'size' => 'Size', 'modified' => 'Modified'] as $key => $label): ?><th><a href="<?= e(cw_link(['sort' => $key, 'dir' => $sort === $key ? $nextDir : 'asc', 'page' => 1])) ?>"><?= e($label) ?><?= $sort === $key ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php if ($currentPath !== ''): ?><tr class="explorer-row" data-url="<?= e(cw_link(['path' => cw_parent($currentPath), 'page' => 1])) ?>" data-workflow-path="<?= e(cw_parent($currentPath)) ?>" data-drive-id="<?= e((string)$driveId) ?>"><td>↩ ..</td><td>Folder</td><td></td><td></td></tr><?php endif; ?>
                <?php foreach ($children as $entry): ?>
                    <?php $entryUrl = $entry['entry_type'] === 'folder' ? cw_link(['drive_id' => $driveId, 'path' => $entry['relative_path'], 'selected_entry_id' => null, 'page' => 1]) : ''; ?>
                    <tr id="entry-<?= e((string)$entry['id']) ?>" class="search-result-row explorer-row selectable-row <?= $selectedEntryId === (int)$entry['id'] ? 'selected-row target-selected-row' : '' ?>" data-id="<?= e((string)$entry['id']) ?>" data-entry-type="<?= e((string)$entry['entry_type']) ?>" data-entry-name="<?= e((string)$entry['name']) ?>" data-url="<?= e($entryUrl) ?>" data-workflow-path="<?= e($entry['entry_type'] === 'folder' ? (string)$entry['relative_path'] : '') ?>" data-drive-id="<?= e((string)$driveId) ?>" data-size="<?= e((string)(int)$entry['size_bytes']) ?>" data-is-file="<?= $entry['entry_type'] === 'file' ? '1' : '0' ?>">
                        <td><?= $entry['entry_type'] === 'folder' ? '📁' : '📄' ?> <?= e((string)$entry['name']) ?></td>
                        <td><?= e($entry['entry_type'] === 'folder' ? 'Folder' : strtoupper((string)$entry['extension']) . ' File') ?></td>
                        <td><?= $entry['entry_type'] === 'file' ? e(format_bytes((int)$entry['size_bytes'])) : '' ?></td>
                        <td><?= e((string)$entry['modified_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$children): ?><tr><td colspan="4">No unassigned entries in this folder.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalItems > CATEGORY_WORKFLOW_PAGE_SIZE): ?>
            <div class="status-bar workflow-explorer-pager">Page <?= e((string)$page) ?> of <?= e((string)$totalPages) ?>. <a class="button" href="<?= e(cw_link(['page' => max(1, $page - 1)])) ?>" data-workflow-page="<?= e((string)max(1, $page - 1)) ?>">Previous</a> <a class="button" href="<?= e(cw_link(['page' => min($totalPages, $page + 1)])) ?>" data-workflow-page="<?= e((string)min($totalPages, $page + 1)) ?>">Next</a></div>
        <?php endif; ?>

        <div class="status-bar" id="workflow-status" data-total-count="<?= e((string)$totalItems) ?>" data-total-size="<?= e((string)$totalSize) ?>" data-file-count="<?= e((string)$fileCount) ?>" data-folder-count="<?= e((string)$folderCount) ?>">Unassigned in this folder: <?= e(number_format($totalItems)) ?> | Files: <?= e(number_format($fileCount)) ?> | Folders: <?= e(number_format($folderCount)) ?> | File size: <?= e(format_bytes($totalSize)) ?></div>
    </div>
</div>
<?php endif; ?>

<div id="context-menu" class="context-menu"><button type="button" data-context-action="add_category">Add to category</button><button type="button" data-context-action="remove_category">Remove from category</button><hr><button type="button" data-context-action="rename">Rename...</button><button type="button" data-context-action="move">Move...</button><button type="button" data-context-action="delete">Delete...</button></div>
<div id="drive-context-menu" class="context-menu"><button type="button" data-drive-action="remove_drive">Remove drive from DB...</button></div>

<form method="post" action="files-action.php" id="context-form"><input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? 'category-workflow.php') ?>"><input type="hidden" name="action" id="context-action-input"><input type="hidden" name="selected_ids" id="context-selected-ids"><input type="hidden" name="drive_id" id="context-drive-id">
    <div id="context-dialog" class="context-dialog hidden"><div class="context-dialog-card"><h3 id="context-dialog-title">Action</h3><div id="context-category-area"><label>Category <select name="context_category_id"><?php foreach ($categories as $category): ?><option value="<?= e((string)$category['id']) ?>"><?= e((string)$category['name']) ?></option><?php endforeach; ?></select></label></div><div id="context-move-area"><label>Destination drive <select name="destination_drive_id"><?php foreach ($drives as $drive): ?><option value="<?= e((string)$drive['id']) ?>"><?= e((string)$drive['friendly_name']) ?></option><?php endforeach; ?></select></label></div><div id="context-rename-area"><label>New name <input type="text" name="new_name" id="context-new-name"></label></div><p id="context-dialog-note" class="muted"></p><p><button type="submit">Confirm</button> <button type="button" id="context-cancel-button">Cancel</button></p></div></div>
</form>

<script src="assets/category-workflow-ajax.js?ts=<?= e((string)time()) ?>" defer></script>

<?php render_footer(); ?>
