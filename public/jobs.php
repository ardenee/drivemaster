<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Repository\JobRepository;

$repo = new JobRepository(db());
$created = (int)($_GET['created'] ?? 0);
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($id > 0 && $action === 'cancel') {
        $repo->cancel($id);
        $message = 'Job cancelled: ' . $id;
    }

    if ($id > 0 && $action === 'requeue') {
        $repo->requeue($id);
        $message = 'Job requeued: ' . $id;
    }
}

$jobs = $repo->all(100);

render_header('Jobs');
?>
<?php if ($created > 0): ?>
    <div class="card"><strong>Job queued:</strong> <?= e((string)$created) ?></div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="card"><strong><?= e($message) ?></strong></div>
<?php endif; ?>

<div class="card">
    <h2>Background jobs</h2>
    <p class="muted">Scans and future move operations run from the CLI worker. Refresh this page to monitor progress.</p>
    <table>
        <thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Progress</th><th>Message</th><th>Error</th><th>Created</th><th>Finished</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $job): ?>
            <?php
            $total = $job['progress_total'] === null ? null : (int)$job['progress_total'];
            $current = (int)$job['progress_current'];
            $progress = $total && $total > 0 ? $current . ' / ' . $total : (string)$current;
            $status = (string)$job['status'];
            ?>
            <tr>
                <td><?= e((string)$job['id']) ?></td>
                <td><?= e($job['job_type']) ?></td>
                <td><?= e($status) ?></td>
                <td><?= e($progress) ?></td>
                <td><?= e($job['progress_message']) ?></td>
                <td><?= e($job['error_message']) ?></td>
                <td><?= e($job['created_at']) ?></td>
                <td><?= e($job['finished_at']) ?></td>
                <td class="actions">
                    <?php if (in_array($status, ['queued', 'running'], true)): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="id" value="<?= e((string)$job['id']) ?>">
                            <input type="hidden" name="action" value="cancel">
                            <button class="danger" type="submit">Cancel</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($status, ['failed', 'cancelled', 'complete', 'running'], true)): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="id" value="<?= e((string)$job['id']) ?>">
                            <input type="hidden" name="action" value="requeue">
                            <button type="submit">Requeue</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php render_footer(); ?>
