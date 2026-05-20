<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\CategoryMatchSql;
use DriveMaster\Support\IgnoredFolderSql;

const CATEGORY_WORKFLOW_LIMIT_MAX = 1000;

function cw_parent(string $path): string
{
    $path = trim($path, '/');
    if ($path === '' || !str_contains($path, '/')) {
        return '';
    }
    return substr($path, 0, strrpos($path, '/')) ?: '';
}

function cw_badges(array $map, int $entryId): string
{
    if (empty($map[$entryId])) {
        return '<span class="muted">Uncategorised</span>';
    }

    $out = '';
    foreach ($map[$entryId] as $row) {
        $style = '';
        $hex = (string)($row['color_hex'] ?? '');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            $r = hexdec(substr($hex, 1, 2));
            $g = hexdec(substr($hex, 3, 2));
            $b = hexdec(substr($hex, 5, 2));
            $style = ' style="background-color: rgba(' . $r . ',' . $g . ',' . $b . ',0.55); border-color: rgba(' . $r . ',' . $g . ',' . $b . ',0.85);"';
        }
        $source = ((int)$row['is_manual'] === 1 && (int)$row['is_rule'] === 1) ? 'manual + rule' : (((int)$row['is_manual'] === 1) ? 'manual' : 'rule');
        $out .= '<span class="category-badge" title="' . e($source) . '"' . $style . '>' . e((string)$row['category_name']) . '</span> ';
    }
    return trim($out);
}

function cw_load_badges(array $entryIds): array
{
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn(int $id): bool => $id > 0)));
    if (!$entryIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
    $matchSql = CategoryMatchSql::matchSql();
    $stmt = db()->prepare("SELECT cm.file_entry_id, cm.category_name, cm.color_hex, cm.is_manual, cm.is_rule FROM ({$matchSql}) cm WHERE cm.file_entry_id IN ({$placeholders}) ORDER BY cm.category_name");
    $stmt->execute($entryIds);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['file_entry_id']][] = $row;
    }
    return $map;
}

$error = null;
$rows = [];
$badgeMap = [];
$totalItems = 0;
$totalFiles = 0;
$totalFolders = 0;
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

$status = (string)($_GET['status'] ?? 'uncategorised');
if (!in_array($status, ['any', 'categorised', 'uncategorised', 'conflicted'], true)) {
    $status = 'uncategorised';
}
$driveId = max(0, (int)($_GET['drive_id'] ?? 0));
$entryType = (string)($_GET['entry_type'] ?? 'file');
if (!in_array($entryType, ['any', 'file', 'folder'], true)) {
    $entryType = 'file';
}
$pathContains = trim((string)($_GET['path_contains'] ?? ''));
$nameContains = trim((string)($_GET['name_contains'] ?? ''));
$limit = max(1, min(CATEGORY_WORKFLOW_LIMIT_MAX, (int)($_GET['limit'] ?? 500)));

try {
    $visibleSql = IgnoredFolderSql::excludeCondition('f');
    $where = ['f.is_missing = 0', $visibleSql];
    $params = [];

    if ($driveId > 0) {
        $where[] = 'f.drive_id = ?';
        $params[] = $driveId;
    }
    if ($entryType !== 'any') {
        $where[] = 'f.entry_type = ?';
        $params[] = $entryType;
    }
    if ($pathContains !== '') {
        $where[] = 'f.relative_path LIKE ?';
        $params[] = '%' . $pathContains . '%';
    }
    if ($nameContains !== '') {
        $where[] = 'f.name LIKE ?';
        $params[] = '%' . $nameContains . '%';
    }

    if ($status === 'categorised') {
        $matchSql = CategoryMatchSql::matchSql();
        $where[] = "EXISTS (SELECT 1 FROM ({$matchSql}) cm WHERE cm.file_entry_id = f.id)";
    } elseif ($status === 'uncategorised') {
        $matchSql = CategoryMatchSql::matchSql();
        $where[] = "NOT EXISTS (SELECT 1 FROM ({$matchSql}) cm WHERE cm.file_entry_id = f.id)";
    } elseif ($status === 'conflicted') {
        $conflictSql = CategoryMatchSql::activeConflictSql();
        $where[] = "f.id IN ({$conflictSql})";
    }

    $whereSql = implode(' AND ', $where);

    $summaryStmt = db()->prepare("SELECT COUNT(*) AS total_items, SUM(f.entry_type = 'file') AS file_count, SUM(f.entry_type = 'folder') AS folder_count, COALESCE(SUM(CASE WHEN f.entry_type = 'file' THEN f.size_bytes ELSE 0 END), 0) AS total_size FROM file_entries f JOIN drives d ON d.id = f.drive_id WHERE {$whereSql}");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch() ?: [];
    $totalItems = (int)($summary['total_items'] ?? 0);
    $totalFiles = (int)($summary['file_count'] ?? 0);
    $totalFolders = (int)($summary['folder_count'] ?? 0);
    $totalSize = (int)($summary['total_size'] ?? 0);

    $stmt = db()->prepare("SELECT f.id, f.drive_id, f.relative_path, f.name, f.entry_type, f.extension, f.size_bytes, f.modified_at, d.friendly_name FROM file_entries f JOIN drives d ON d.id = f.drive_id WHERE {$whereSql} ORDER BY d.friendly_name ASC, f.relative_path ASC LIMIT ?");
    foreach ($params as $index => $value) {
        $stmt->bindValue($index + 1, $value);
    }
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $badgeMap = cw_load_badges(array_column($rows, 'id'));
} catch (Throwable $e) {
    $error = trim(($error ? $error . ' | ' : '') . 'Could not load category workflow results: ' . $e->getMessage());
}

render_header('Category Workflow');
?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>

<div class="card">
    <h2>Category Workflow</h2>
    <p class="muted">Use this page to find files and folders that still need category assignment. Right-click one or more rows to add/remove a category.</p>
    <form method="get">
        <div class="form-grid compact-search">
            <label>Status
                <select name="status">
                    <option value="any" <?= $status === 'any' ? 'selected' : '' ?>>Any</option>
                    <option value="categorised" <?= $status === 'categorised' ? 'selected' : '' ?>>Categorised</option>
                    <option value="uncategorised" <?= $status === 'uncategorised' ? 'selected' : '' ?>>Uncategorised</option>
                    <option value="conflicted" <?= $status === 'conflicted' ? 'selected' : '' ?>>Conflicted</option>
                </select>
            </label>
            <label>Drive
                <select name="drive_id">
                    <option value="0">All drives</option>
                    <?php foreach ($drives as $drive): ?><option value="<?= e((string)$drive['id']) ?>" <?= $driveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e((string)$drive['friendly_name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Entry type
                <select name="entry_type">
                    <option value="any" <?= $entryType === 'any' ? 'selected' : '' ?>>Files and folders</option>
                    <option value="file" <?= $entryType === 'file' ? 'selected' : '' ?>>Files only</option>
                    <option value="folder" <?= $entryType === 'folder' ? 'selected' : '' ?>>Folders only</option>
                </select>
            </label>
            <label>Limit <input type="number" name="limit" min="1" max="<?= e((string)CATEGORY_WORKFLOW_LIMIT_MAX) ?>" value="<?= e((string)$limit) ?>"></label>
            <label>Path contains <input type="text" name="path_contains" value="<?= e($pathContains) ?>"></label>
            <label>Name contains <input type="text" name="name_contains" value="<?= e($nameContains) ?>"></label>
        </div>
        <p><button type="submit">Filter</button> <a class="button" href="category-workflow.php">Reset</a></p>
    </form>
</div>

<div class="card">
    <h2>Results</h2>
    <p class="muted">Matches: <?= e(number_format($totalItems)) ?> | Files: <?= e(number_format($totalFiles)) ?> | Folders: <?= e(number_format($totalFolders)) ?> | File size: <?= e(format_bytes($totalSize)) ?> | Showing: <?= e(number_format(count($rows))) ?></p>
    <div class="table-scroll">
        <table class="auto-table selectable-table" data-status-target="workflow-status">
            <thead><tr><th>Drive</th><th>Name</th><th>Type</th><th>Category</th><th>Path</th><th>Size</th><th>Modified</th><th>Open</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $openUrl = 'files.php?' . http_build_query(['drive_id' => (int)$row['drive_id'], 'path' => cw_parent((string)$row['relative_path']), 'selected_entry_id' => (int)$row['id'], 'explorer_page' => 1]) . '#explorer-panel'; ?>
                    <tr class="search-result-row selectable-row" data-id="<?= e((string)$row['id']) ?>" data-entry-type="<?= e((string)$row['entry_type']) ?>" data-entry-name="<?= e((string)$row['name']) ?>" data-url="<?= e($openUrl) ?>" data-size="<?= e((string)(int)$row['size_bytes']) ?>" data-is-file="<?= $row['entry_type'] === 'file' ? '1' : '0' ?>">
                        <td><?= e((string)$row['friendly_name']) ?></td>
                        <td><?= $row['entry_type'] === 'folder' ? '📁' : '📄' ?> <?= e((string)$row['name']) ?></td>
                        <td><?= e($row['entry_type'] === 'folder' ? 'Folder' : strtoupper((string)$row['extension']) . ' File') ?></td>
                        <td><?= cw_badges($badgeMap, (int)$row['id']) ?></td>
                        <td class="path-cell"><?= e((string)$row['relative_path']) ?></td>
                        <td class="nowrap"><?= $row['entry_type'] === 'file' ? e(format_bytes((int)$row['size_bytes'])) : '' ?></td>
                        <td class="nowrap"><?= e((string)$row['modified_at']) ?></td>
                        <td><a class="button" href="<?= e($openUrl) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="8">No matching entries found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="status-bar" id="workflow-status" data-total-count="<?= e((string)$totalItems) ?>" data-total-size="<?= e((string)$totalSize) ?>" data-file-count="<?= e((string)$totalFiles) ?>" data-folder-count="<?= e((string)$totalFolders) ?>"></div>
</div>

<div id="context-menu" class="context-menu"><button type="button" data-context-action="add_category">Add to category</button><button type="button" data-context-action="remove_category">Remove from category</button><hr><button type="button" data-context-action="rename">Rename...</button><button type="button" data-context-action="move">Move...</button><button type="button" data-context-action="delete">Delete...</button></div>
<div id="drive-context-menu" class="context-menu"><button type="button" data-drive-action="remove_drive">Remove drive from DB...</button></div>

<form method="post" action="files-action.php" id="context-form"><input type="hidden" name="return_url" value="<?= e($_SERVER['REQUEST_URI'] ?? 'category-workflow.php') ?>"><input type="hidden" name="action" id="context-action-input"><input type="hidden" name="selected_ids" id="context-selected-ids"><input type="hidden" name="drive_id" id="context-drive-id">
    <div id="context-dialog" class="context-dialog hidden"><div class="context-dialog-card"><h3 id="context-dialog-title">Action</h3><div id="context-category-area"><label>Category <select name="context_category_id"><?php foreach ($categories as $category): ?><option value="<?= e((string)$category['id']) ?>"><?= e((string)$category['name']) ?></option><?php endforeach; ?></select></label></div><div id="context-move-area"><label>Destination drive <select name="destination_drive_id"><?php foreach ($drives as $drive): ?><option value="<?= e((string)$drive['id']) ?>"><?= e((string)$drive['friendly_name']) ?></option><?php endforeach; ?></select></label></div><div id="context-rename-area"><label>New name <input type="text" name="new_name" id="context-new-name"></label></div><p id="context-dialog-note" class="muted"></p><p><button type="submit">Confirm</button> <button type="button" id="context-cancel-button">Cancel</button></p></div></div>
</form>

<?php render_footer(); ?>
