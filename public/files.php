<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$drives = db()->query('SELECT id, friendly_name, mount_path FROM drives ORDER BY friendly_name')->fetchAll();
$driveId = (int)($_GET['drive_id'] ?? ($drives[0]['id'] ?? 0));
$currentPath = trim($_GET['path'] ?? '');
$expandedDriveId = (int)($_GET['expanded_drive_id'] ?? ($driveId > 0 ? $driveId : 0));
$sort = $_GET['sort'] ?? 'name';
$dir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$nextDir = $dir === 'asc' ? 'desc' : 'asc';

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

function render_folder_tree(int $driveId, string $parentPath, string $activePath, int $level = 0): void
{
    foreach (folder_children($driveId, $parentPath) as $folder) {
        $folderPath = $folder['relative_path'];
        $isActive = $folderPath === $activePath;
        $isOpen = path_is_on_branch($folderPath, $activePath);
        $padding = 18 + ($level * 18);
        ?>
        <div class="tree-folder <?= $isActive ? 'selected' : '' ?>" style="padding-left: <?= e((string)$padding) ?>px" data-url="<?= e(files_link(['drive_id' => $driveId, 'path' => $folderPath, 'expanded_drive_id' => $driveId])) ?>">
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

render_header('Files');
?>
<div class="card">
    <h2>File Explorer</h2>
    <p class="muted">Single-click selects. Double-click folders or drives to open. Double-click an expanded drive in the left tree to collapse it.</p>
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
                $driveUrl = files_link(['drive_id' => $thisDriveId, 'path' => '', 'expanded_drive_id' => $isExpandedDrive ? 0 : $thisDriveId]);
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
                <a href="<?= e(files_link(['path' => '', 'drive_id' => $driveId, 'expanded_drive_id' => $driveId])) ?>"><?= e($selectedDrive['friendly_name']) ?></a>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    / <a href="<?= e(files_link(['path' => $crumb['path'], 'drive_id' => $driveId, 'expanded_drive_id' => $driveId])) ?>"><?= e($crumb['name']) ?></a>
                <?php endforeach; ?>
            </div>

            <table class="explorer-table">
                <thead>
                    <tr>
                        <th><a href="<?= e(files_link(['sort' => 'name', 'dir' => $sort === 'name' ? $nextDir : 'asc'])) ?>">Name<?= $sort === 'name' ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                        <th><a href="<?= e(files_link(['sort' => 'type', 'dir' => $sort === 'type' ? $nextDir : 'asc'])) ?>">Type<?= $sort === 'type' ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                        <th><a href="<?= e(files_link(['sort' => 'size', 'dir' => $sort === 'size' ? $nextDir : 'asc'])) ?>">Size<?= $sort === 'size' ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                        <th><a href="<?= e(files_link(['sort' => 'modified', 'dir' => $sort === 'modified' ? $nextDir : 'asc'])) ?>">Modified<?= $sort === 'modified' ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($currentPath !== ''): ?>
                        <tr class="explorer-row" data-url="<?= e(files_link(['path' => path_parent($currentPath), 'expanded_drive_id' => $driveId])) ?>">
                            <td>↩ ..</td>
                            <td>Folder</td>
                            <td></td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($children as $entry): ?>
                        <?php $entryUrl = $entry['entry_type'] === 'folder' ? files_link(['path' => $entry['relative_path'], 'expanded_drive_id' => $driveId]) : ''; ?>
                        <tr class="explorer-row" data-url="<?= e($entryUrl) ?>">
                            <td><?= $entry['entry_type'] === 'folder' ? '📁' : '📄' ?> <?= e($entry['name']) ?></td>
                            <td><?= e($entry['entry_type'] === 'folder' ? 'Folder' : (($entry['extension'] ?? '') !== '' ? strtoupper($entry['extension']) . ' File' : 'File')) ?></td>
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
document.querySelectorAll('.tree-drive, .tree-folder, .explorer-row').forEach(function (row) {
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
</script>
<?php render_footer(); ?>
