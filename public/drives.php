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
        $data = [
            'friendly_name' => trim($_POST['friendly_name'] ?? ''),
            'serial_number' => trim($_POST['serial_number'] ?? ''),
            'volume_label' => trim($_POST['volume_label'] ?? ''),
            'filesystem' => trim($_POST['filesystem'] ?? ''),
            'mount_path' => trim($_POST['mount_path'] ?? ''),
            'total_bytes' => (int)($_POST['total_bytes'] ?? 0),
            'free_bytes' => (int)($_POST['free_bytes'] ?? 0),
            'used_bytes' => (int)($_POST['used_bytes'] ?? 0),
            'notes' => trim($_POST['notes'] ?? ''),
            'is_active' => $_POST['is_active'] ?? null,
        ];

        if ($id > 0) {
            $repo->updateManual($id, $data);
        } else {
            $repo->upsert($data);
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
    <h2><?= $editDrive ? 'Edit drive' : 'Add drive' ?></h2>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= e((string)($editDrive['id'] ?? 0)) ?>">
        <div class="form-grid">
            <label>Friendly name <input type="text" name="friendly_name" required value="<?= e($editDrive['friendly_name'] ?? '') ?>"></label>
            <label>Serial number <input type="text" name="serial_number" required value="<?= e($editDrive['serial_number'] ?? '') ?>"></label>
            <label>Volume label <input type="text" name="volume_label" value="<?= e($editDrive['volume_label'] ?? '') ?>"></label>
            <label>Filesystem <input type="text" name="filesystem" value="<?= e($editDrive['filesystem'] ?? '') ?>"></label>
            <label class="wide">Mount path <input type="text" name="mount_path" value="<?= e($editDrive['mount_path'] ?? '') ?>"></label>
            <label>Total bytes <input type="number" name="total_bytes" min="0" value="<?= e((string)($editDrive['total_bytes'] ?? 0)) ?>"></label>
            <label>Free bytes <input type="number" name="free_bytes" min="0" value="<?= e((string)($editDrive['free_bytes'] ?? 0)) ?>"></label>
            <label>Used bytes <input type="number" name="used_bytes" min="0" value="<?= e((string)($editDrive['used_bytes'] ?? 0)) ?>"></label>
            <label>Active <input type="checkbox" name="is_active" value="1" <?= !isset($editDrive) || (int)($editDrive['is_active'] ?? 1) === 1 ? 'checked' : '' ?>></label>
            <label class="wide">Notes <textarea name="notes" rows="3"><?= e($editDrive['notes'] ?? '') ?></textarea></label>
        </div>
        <p><button type="submit">Save drive</button></p>
    </form>
</div>

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
