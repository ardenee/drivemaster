<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Repository\DriveRepository;
use DriveMaster\Repository\JobRepository;
use DriveMaster\Service\DriveDiscovery;

$discovery = new DriveDiscovery();
$driveRepo = new DriveRepository(db());
$jobRepo = new JobRepository(db());
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
        $jobId = $jobRepo->create('scan_drive', [
            'path' => $details['path'],
            'friendly_name' => $friendlyName,
            'serial_number' => $details['serial_number'],
        ]);

        header('Location: jobs.php?created=' . $jobId);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$mountedDrives = $discovery->discover();
$mountedGroups = $discovery->groupByPhysicalDisk($mountedDrives);
$knownDrivesBySerial = [];
foreach ($driveRepo->all() as $drive) {
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
    <p class="muted">Select a mounted partition/path discovered by the system. Partitions are grouped by physical disk so one USB disk does not appear as unrelated drives.</p>

    <?php if (!$mountedGroups): ?>
        <p>No mounted drives were discovered. On Synology, make sure the USB drive is mounted and readable by Web Station.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Disk</th><th>Partitions / paths</th><th>Total mounted size</th><th>Total mounted free</th><th>Queue scan</th></tr></thead>
            <tbody>
            <?php foreach ($mountedGroups as $groupIndex => $group): ?>
                <?php
                $firstPartition = $group['partitions'][0] ?? null;
                $defaultFriendly = $firstPartition['label'] ?? ('drive-' . ($groupIndex + 1));
                $known = null;
                foreach ($group['partitions'] as $partition) {
                    if (isset($knownDrivesBySerial[$partition['serial_number']])) {
                        $known = $knownDrivesBySerial[$partition['serial_number']];
                        break;
                    }
                }
                ?>
                <tr>
                    <td>
                        <strong><?= e($group['device_name'] ?? $group['disk_key']) ?></strong><br>
                        <span class="muted"><?= e(trim(($group['vendor'] ?? '') . ' ' . ($group['model'] ?? '')) ?: 'USB disk') ?></span><br>
                        <span class="muted"><?= e($group['disk_serial'] ? 'Serial: ' . $group['disk_serial'] : 'Serial: not exposed') ?></span>
                    </td>
                    <td>
                        <?php foreach ($group['partitions'] as $index => $partition): ?>
                            <div class="partition-choice">
                                <label>
                                    <input type="radio" name="scan_path_<?= e((string)$groupIndex) ?>" value="<?= e($partition['path']) ?>" <?= $index === 0 ? 'checked' : '' ?> form="scan-form-<?= e((string)$groupIndex) ?>">
                                    <?= e($partition['path']) ?>
                                </label><br>
                                <span class="muted">
                                    <?= e($partition['partition_device'] ?? '') ?> | <?= e($partition['serial_number']) ?> | <?= e(format_bytes((int)$partition['total_bytes'])) ?> | Free <?= e(format_bytes((int)$partition['free_bytes'])) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <td><?= e(format_bytes((int)$group['total_bytes'])) ?></td>
                    <td><?= e(format_bytes((int)$group['free_bytes'])) ?></td>
                    <td>
                        <form method="post" id="scan-form-<?= e((string)$groupIndex) ?>" onsubmit="this.path.value = document.querySelector('input[name=scan_path_<?= e((string)$groupIndex) ?>]:checked').value;">
                            <input type="hidden" name="path" value="<?= e($firstPartition['path'] ?? '') ?>">
                            <input type="text" name="friendly_name" required placeholder="Friendly drive name" value="<?= e($known['friendly_name'] ?? $defaultFriendly) ?>">
                            <button type="submit">Queue scan</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
