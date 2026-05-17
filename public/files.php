<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$q = trim($_GET['q'] ?? '');
$extension = trim($_GET['extension'] ?? '');
$driveId = (int)($_GET['drive_id'] ?? 0);
$minSize = trim($_GET['min_size'] ?? '');
$maxSize = trim($_GET['max_size'] ?? '');

$where = ['f.is_missing = 0'];
$params = [];

if ($q !== '') {
    $where[] = '(f.name LIKE ? OR f.relative_path LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if ($extension !== '') {
    $where[] = 'f.extension = ?';
    $params[] = strtolower(ltrim($extension, '.'));
}

if ($driveId > 0) {
    $where[] = 'f.drive_id = ?';
    $params[] = $driveId;
}

if ($minSize !== '') {
    $where[] = 'f.size_bytes >= ?';
    $params[] = (int)$minSize;
}

if ($maxSize !== '') {
    $where[] = 'f.size_bytes <= ?';
    $params[] = (int)$maxSize;
}

$sql = 'SELECT f.*, d.friendly_name FROM file_entries f JOIN drives d ON d.id = f.drive_id WHERE ' . implode(' AND ', $where) . ' ORDER BY f.entry_type, f.name LIMIT 500';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$files = $stmt->fetchAll();
$drives = db()->query('SELECT id, friendly_name FROM drives ORDER BY friendly_name')->fetchAll();

render_header('Files');
?>
<div class="card">
    <h2>File search</h2>
    <form method="get">
        <div class="form-grid">
            <label>Search <input type="text" name="q" value="<?= e($q) ?>"></label>
            <label>Extension <input type="text" name="extension" value="<?= e($extension) ?>"></label>
            <label>Drive
                <select name="drive_id">
                    <option value="0">All drives</option>
                    <?php foreach ($drives as $drive): ?>
                        <option value="<?= e((string)$drive['id']) ?>" <?= $driveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Min size bytes <input type="number" name="min_size" min="0" value="<?= e($minSize) ?>"></label>
            <label>Max size bytes <input type="number" name="max_size" min="0" value="<?= e($maxSize) ?>"></label>
        </div>
        <p><button type="submit">Search</button></p>
    </form>
</div>

<div class="card">
    <h2>Results</h2>
    <p class="muted">Showing up to 500 matching entries.</p>
    <table>
        <thead><tr><th>Type</th><th>Name</th><th>Drive</th><th>Relative path</th><th>Size</th><th>Modified</th></tr></thead>
        <tbody>
        <?php foreach ($files as $file): ?>
            <tr>
                <td><?= e($file['entry_type']) ?></td>
                <td><?= e($file['name']) ?></td>
                <td><?= e($file['friendly_name']) ?></td>
                <td><?= e($file['relative_path']) ?></td>
                <td><?= e(format_bytes((int)$file['size_bytes'])) ?></td>
                <td><?= e($file['modified_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php render_footer(); ?>
