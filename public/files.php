<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$drives = db()->query('SELECT id, friendly_name, mount_path FROM drives ORDER BY friendly_name')->fetchAll();
$driveId = (int)($_GET['drive_id'] ?? ($drives[0]['id'] ?? 0));
$currentPath = trim($_GET['path'] ?? '');
$allowedSorts = [
    'name' => 'name',
    'type' => 'entry_type',
    'size' => 'size_bytes',
    'modified' => 'modified_at',
];
$sort = $_GET['sort'] ?? 'type';
$dir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$orderColumn = $allowedSorts[$sort] ?? 'entry_type';
$nextDir = $dir === 'asc' ? 'desc' : 'asc';

function files_link(array $params): string
{
    $merged = array_merge($_GET, $params);
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
    $sql = "SELECT id, relative_path, name, extension, entry_type, size_bytes, modified_at FROM file_entries WHERE drive_id = ? AND is_missing = 0 AND {$parentExpr} = ? ORDER BY {$orderColumn} {$dir}, name ASC";
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
    <p class="muted">Browse indexed files from the database. This mimics a basic Windows Explorer-style view per scanned drive.</p>
    <form method="get">
        <label>Drive
            <select name="drive_id" onchange="this.form.submit()">
                <?php foreach ($drives as $drive): ?>
                    <option value="<?= e((string)$drive['id']) ?>" <?= $driveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</div>

<?php if (!$selectedDrive): ?>
    <div class="card"><p>No drive selected or no drives have been scanned yet.</p></div>
<?php else: ?>
    <div class="card explorer-wrap">
        <div class="explorer-sidebar">
            <h3>Drives</h3>
            <?php foreach ($drives as $drive): ?>
                <div class="tree-drive <?= $driveId === (int)$drive['id'] ? 'selected' : '' ?>">
                    <a href="files.php?drive_id=<?= e((string)$drive['id']) ?>">▸ <?= e($drive['friendly_name']) ?></a>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="explorer-main">
            <div class="breadcrumbs">
                <a href="<?= e(files_link(['path' => '', 'drive_id' => $driveId])) ?>"><?= e($selectedDrive['friendly_name']) ?></a>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    / <a href="<?= e(files_link(['path' => $crumb['path'], 'drive_id' => $driveId])) ?>"><?= e($crumb['name']) ?></a>
                <?php endforeach; ?>
            </div>

            <table class="explorer-table">
                <thead>
                    <tr>
                        <th><a href="<?= e(files_link(['sort' => 'name', 'dir' => $sort === 'name' ? $nextDir : 'asc'])) ?>">Name</a></th>
                        <th><a href="<?= e(files_link(['sort' => 'type', 'dir' => $sort === 'type' ? $nextDir : 'asc'])) ?>">Type</a></th>
                        <th><a href="<?= e(files_link(['sort' => 'size', 'dir' => $sort === 'size' ? $nextDir : 'asc'])) ?>">Size</a></th>
                        <th><a href="<?= e(files_link(['sort' => 'modified', 'dir' => $sort === 'modified' ? $nextDir : 'asc'])) ?>">Modified</a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($currentPath !== ''): ?>
                        <tr>
                            <td><a href="<?= e(files_link(['path' => path_parent($currentPath)])) ?>">↩ ..</a></td>
                            <td>Folder</td>
                            <td></td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($children as $entry): ?>
                        <tr>
                            <td>
                                <?php if ($entry['entry_type'] === 'folder'): ?>
                                    <a href="<?= e(files_link(['path' => $entry['relative_path']])) ?>">📁 <?= e($entry['name']) ?></a>
                                <?php else: ?>
                                    📄 <?= e($entry['name']) ?>
                                <?php endif; ?>
                            </td>
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
<?php render_footer(); ?>
