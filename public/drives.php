<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Repository\DriveRepository;

$repo = new DriveRepository(db());
$editDrive = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? $repo->find($id) : null;

        if ($existing) {
            $data = [
                'friendly_name' => trim($_POST['friendly_name'] ?? ''),
                'serial_number' => $existing['serial_number'],
                'volume_label' => $existing['volume_label'] ?? '',
                'filesystem' => $existing['filesystem'] ?? '',
                'mount_path' => $existing['mount_path'] ?? '',
                'total_bytes' => (int)$existing['total_bytes'],
                'free_bytes' => (int)$existing['free_bytes'],
                'used_bytes' => (int)$existing['used_bytes'],
                'notes' => trim($_POST['notes'] ?? ''),
                'is_active' => $_POST['is_active'] ?? null,
            ];

            $repo->updateManual($id, $data);
        }

        header('Location: drives.php');
        exit;
    }

    if ($action === 'delete') {
        $repo->delete((int)$_POST['id']);
        header('Location: drives.php');
        exit;
    }
}

if (isset($_GET['edit'])) {
    $editDrive = $repo->find((int)$_GET['edit']);
}

$drives = $repo->all();

render_header('Drives');
?>
<div class="card">
    <h2>Drive management</h2>
    <p class="muted">Drives are added by scanning connected/mounted drives from the Scan page. This page only edits friendly metadata or deletes existing inventory records.</p>
    <p><a class="button" href="scan.php">Scan connected drive</a></p>
</div>

<?php if ($editDrive): ?>
<div class="card">
    <h2>Edit drive</h2>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= e((string)$editDrive['id']) ?>">
        <div class="form-grid">
            <label>Friendly name <input type="text" name="friendly_name" required value="<?= e($editDrive['friendly_name']) ?>"></label>
            <label>Active <input type="checkbox" name="is_active" value="1" <?= (int)$editDrive['is_active'] === 1 ? 'checked' : '' ?>></label>
            <label>Serial number <input type="text" readonly value="<?= e($editDrive['serial_number']) ?>"></label>
            <label>Mount path <input type="text" readonly value="<?= e($editDrive['mount_path']) ?>"></label>
            <label>Total size <input type="text" readonly value="<?= e(format_bytes((int)$editDrive['total_bytes'])) ?>"></label>
            <label>Free space <input type="text" readonly value="<?= e(format_bytes((int)$editDrive['free_bytes'])) ?>"></label>
            <label class="wide">Notes <textarea name="notes" rows="3"><?= e($editDrive['notes'] ?? '') ?></textarea></label>
        </div>
        <p><button type="submit">Save drive</button> <a class="button" href="drives.php">Cancel</a></p>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Known drives</h2>
    <table>
        <thead><tr><th>Name</th><th>Serial</th><th>Path</th><th>Size</th><th>Free</th><th>Last scanned</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($drives as $drive): ?>
            <tr>
                <td><?= e($drive['friendly_name']) ?></td>
                <td><?= e($drive['serial_number']) ?></td>
                <td><?= e($drive['mount_path']) ?></td>
                <td><?= e(format_bytes((int)$drive['total_bytes'])) ?></td>
                <td><?= e(format_bytes((int)$drive['free_bytes'])) ?></td>
                <td><?= e($drive['last_scanned_at'] ?? 'Never') ?></td>
                <td class="actions">
                    <a class="button" href="drives.php?edit=<?= e((string)$drive['id']) ?>">Edit</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this drive and its inventory?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= e((string)$drive['id']) ?>">
                        <button class="danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php render_footer(); ?>
