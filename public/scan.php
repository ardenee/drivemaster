<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Repository\DriveRepository;
use DriveMaster\Service\DriveDiscovery;
use DriveMaster\Service\DriveScanner;

$discovery = new DriveDiscovery();
$repo = new DriveRepository(db());
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $path = trim($_POST['path'] ?? '');
    $friendlyName = trim($_POST['friendly_name'] ?? '');

    try {
        if ($path === '') {
            throw new RuntimeException('No drive path selected.');
        }
        if ($friendlyName === '') {
            throw new RuntimeException('Friendly name is required.');
        }

        $details = $discovery->describePath($path);
        $scanner = new DriveScanner(db(), $repo);
        $scanRunId = $scanner->scan($details['path'], $friendlyName, $details['serial_number']);
        $message = 'Scan complete. Scan run ID: ' . $scanRunId;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$mountedDrives = $discovery->discover();
$knownDrivesBySerial = [];
foreach ($repo->all() as $drive) {
    $knownDrivesBySerial[$drive['serial_number']] = $drive;
}

render_header('Scan');
?>
<?php if ($message): ?>
    <div class="card"><strong><?= e($message) ?></strong></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="card"><strong>Error:</strong> <?= e($error) ?></div>
<?php endif; ?>

<div class="card">
    <h2>Scan connected drive</h2>
    <p class="muted">Select a mounted drive/path discovered by the system. Drive records are created from scans, not manually.</p>

    <?php if (!$mountedDrives): ?>
        <p>No mounted drives were discovered. On Synology, make sure the USB drive is mounted and readable by Web Station.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Path</th><th>Detected serial</th><th>Size</th><th>Free</th><th>Known as</th><th>Scan</th></tr></thead>
            <tbody>
            <?php foreach ($mountedDrives as $drive): ?>
                <?php $known = $knownDrivesBySerial[$drive['serial_number']] ?? null; ?>
                <tr>
                    <td><?= e($drive['path']) ?></td>
                    <td><?= e($drive['serial_number']) ?></td>
                    <td><?= e(format_bytes($drive['total_bytes'])) ?></td>
                    <td><?= e(format_bytes($drive['free_bytes'])) ?></td>
                    <td><?= e($known['friendly_name'] ?? '-') ?></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="path" value="<?= e($drive['path']) ?>">
                            <input type="text" name="friendly_name" required placeholder="Friendly drive name" value="<?= e($known['friendly_name'] ?? $drive['label']) ?>">
                            <button type="submit">Scan</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
