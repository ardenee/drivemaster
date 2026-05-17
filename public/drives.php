<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Repository\DriveRepository;
use DriveMaster\Repository\JobRepository;

$repo = new DriveRepository(db());
$jobRepo = new JobRepository(db());
$editDrive = null;
$message = null;
$formFactors = ['3.5' => '3.5', '2.5' => '2.5', 'M2' => 'M2'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? $repo->find($id) : null;

        if ($existing) {
            $repo->updateManual($id, [
                'friendly_name' => trim($_POST['friendly_name'] ?? ''),
                'form_factor' => trim($_POST['form_factor'] ?? '3.5'),
                'notes' => trim($_POST['notes'] ?? ''),
                'is_active' => $_POST['is_active'] ?? null,
            ]);
        }

        header('Location: drives.php');
        exit;
    }

    if ($action === 'refresh_info') {
        $id = (int)($_POST['id'] ?? 0);
        $drive = $id > 0 ? $repo->find($id) : null;
        if ($drive) {
            $jobId = $jobRepo->create('refresh_drive_info', ['drive_id' => $id]);
            header('Location: jobs.php?created=' . $jobId);
            exit;
        }
        $message = 'Drive not found.';
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
<?php if ($message): ?>
    <div class="card"><strong><?= e($message) ?></strong></div>
<?php endif; ?>

<div class="card">
    <h2>Drive management</h2>
    <p class="muted">Drives are added by scanning connected/mounted drives from the Scan page. Drive Info queues a background metadata refresh only; it does not scan files.</p>
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
            <label>Form factor
                <select name="form_factor">
                    <?php foreach ($formFactors as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= (($editDrive['form_factor'] ?? '3.5') === $value) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Active <input type="checkbox" name="is_active" value="1" <?= (int)$editDrive['is_active'] === 1 ? 'checked' : '' ?>></label>
            <label>Stable identity <input type="text" readonly value="<?= e($editDrive['serial_number']) ?>"></label>
            <label>Identity source <input type="text" readonly value="<?= e($editDrive['identity_source'] ?? '') ?>"></label>
            <label>Hardware serial <input type="text" readonly value="<?= e($editDrive['hardware_serial'] ?? '') ?>"></label>
            <label>Filesystem UUID <input type="text" readonly value="<?= e($editDrive['filesystem_uuid'] ?? '') ?>"></label>
            <label>Model <input type="text" readonly value="<?= e($editDrive['model'] ?? '') ?>"></label>
            <label>Vendor <input type="text" readonly value="<?= e($editDrive['vendor'] ?? '') ?>"></label>
            <label>Device <input type="text" readonly value="<?= e($editDrive['device_name'] ?? '') ?>"></label>
            <label>Filesystem <input type="text" readonly value="<?= e($editDrive['filesystem'] ?? '') ?>"></label>
            <label class="wide">Mount path <input type="text" readonly value="<?= e($editDrive['mount_path']) ?>"></label>
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
        <thead><tr><th>Name</th><th>Form factor</th><th>Stable identity</th><th>Source</th><th>Model</th><th>Device</th><th>Path</th><th>Size</th><th>Free</th><th>Last scanned</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($drives as $drive): ?>
            <tr>
                <td><?= e($drive['friendly_name']) ?></td>
                <td><?= e($drive['form_factor'] ?? '3.5') ?></td>
                <td><?= e($drive['serial_number']) ?></td>
                <td><?= e($drive['identity_source'] ?? '') ?></td>
                <td><?= e(trim(($drive['vendor'] ?? '') . ' ' . ($drive['model'] ?? ''))) ?></td>
                <td><?= e($drive['device_name'] ?? '') ?></td>
                <td><?= e($drive['mount_path']) ?></td>
                <td><?= e(format_bytes((int)$drive['total_bytes'])) ?></td>
                <td><?= e(format_bytes((int)$drive['free_bytes'])) ?></td>
                <td><?= e($drive['last_scanned_at'] ?? 'Never') ?></td>
                <td class="actions">
                    <a class="button" href="drives.php?edit=<?= e((string)$drive['id']) ?>">Edit</a>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="refresh_info">
                        <input type="hidden" name="id" value="<?= e((string)$drive['id']) ?>">
                        <button type="submit">Drive Info</button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this drive, its inventory, and related scan jobs?');">
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
