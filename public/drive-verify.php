<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$message = null;
$error = null;
$selectedDriveId = max(0, (int)($_REQUEST['drive_id'] ?? 0));
$verifyRows = [];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($selectedDriveId <= 0) {
            throw new RuntimeException('Select a drive to verify.');
        }
        $stmt = db()->prepare('SELECT * FROM drives WHERE id = ?');
        $stmt->execute([$selectedDriveId]);
        $drive = $stmt->fetch();
        if (!$drive) {
            throw new RuntimeException('Drive not found.');
        }
        $mount = rtrim((string)$drive['mount_path'], "/\\");
        if ($mount === '' || !is_dir($mount)) {
            throw new RuntimeException('Drive mount path is not currently available.');
        }
        $items = db()->prepare("SELECT id, relative_path, full_path, size_bytes, entry_type FROM file_entries WHERE drive_id = ? AND is_missing = 0 ORDER BY entry_type DESC, relative_path LIMIT 1000");
        $items->execute([$selectedDriveId]);
        $checked = 0;
        $ok = 0;
        $missing = 0;
        $changed = 0;
        foreach ($items->fetchAll() as $item) {
            $checked++;
            $expected = $mount . '/' . ltrim(str_replace('\\', '/', (string)$item['relative_path']), '/');
            $exists = (string)$item['entry_type'] === 'folder' ? is_dir($expected) : is_file($expected);
            $sizeOk = true;
            if ($exists && (string)$item['entry_type'] === 'file') {
                $sizeOk = filesize($expected) === (int)$item['size_bytes'];
            }
            $status = $exists && $sizeOk ? 'ok' : (!$exists ? 'missing' : 'changed');
            if ($status === 'ok') { $ok++; }
            if ($status === 'missing') { $missing++; }
            if ($status === 'changed') { $changed++; }
            if ($status !== 'ok' || count($verifyRows) < 100) {
                $verifyRows[] = ['status' => $status, 'path' => $expected, 'size' => (int)$item['size_bytes']];
            }
        }
        $message = 'Checked ' . number_format($checked) . ' entries. OK: ' . number_format($ok) . ' | Missing: ' . number_format($missing) . ' | Changed: ' . number_format($changed) . '. Only first 100 OK rows are shown; all issues are shown within the 1000-row verification limit.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$drives = db()->query('SELECT id, friendly_name, mount_path, serial_number, last_seen_at, last_scanned_at FROM drives WHERE is_active = 1 ORDER BY friendly_name')->fetchAll();
render_header('Drive Verify');
?>
<div class="page-shell">
    <div class="page-heading"><div><h2>Verify drive before swapping</h2><p class="muted">Use this before removing/changing drives. It checks the currently mounted path against the database entries for that drive.</p></div><div class="button-row"><a class="button" href="drives.php">Drives</a><a class="button" href="operation-log.php">Operation Log</a><a class="button" href="undo.php">Undo</a></div></div>
    <?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong></div><?php endif; ?>
    <?php if ($error): ?><div class="card danger-zone"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
    <div class="card"><h2>Select drive</h2><form method="post" class="admin-form"><label>Drive <select name="drive_id" required><option value="">Select drive</option><?php foreach ($drives as $drive): ?><option value="<?= e((string)$drive['id']) ?>" <?= $selectedDriveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name'] . ' - ' . ($drive['mount_path'] ?? '') . ' - ' . $drive['serial_number']) ?></option><?php endforeach; ?></select></label><button type="submit">Verify drive contents</button></form></div>
    <?php if ($verifyRows): ?><div class="card"><h2>Verification sample/issues</h2><div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Status</th><th>Path</th><th>Expected size</th></tr></thead><tbody><?php foreach ($verifyRows as $row): ?><tr><td><?= e($row['status']) ?></td><td class="wrap-cell path-cell"><?= e($row['path']) ?></td><td><?= e(format_bytes($row['size'])) ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
</div>
<?php render_footer(); ?>
