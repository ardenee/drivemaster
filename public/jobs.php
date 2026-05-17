<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Repository\JobRepository;

$repo = new JobRepository(db());
$jobs = $repo->all(100);
$created = (int)($_GET['created'] ?? 0);

render_header('Jobs');
?>
<?php if ($created > 0): ?>
    <div class="card"><strong>Job queued:</strong> <?= e((string)$created) ?></div>
<?php endif; ?>

<div class="card">
    <h2>Background jobs</h2>
    <p class="muted">Scans and future move operations run from the CLI worker. Refresh this page to monitor progress.</p>
    <table>
        <thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Progress</th><th>Message</th><th>Error</th><th>Created</th><th>Finished</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $job): ?>
            <?php
            $total = $job['progress_total'] === null ? null : (int)$job['progress_total'];
            $current = (int)$job['progress_current'];
            $progress = $total && $total > 0 ? $current . ' / ' . $total : (string)$current;
            ?>
            <tr>
                <td><?= e((string)$job['id']) ?></td>
                <td><?= e($job['job_type']) ?></td>
                <td><?= e($job['status']) ?></td>
                <td><?= e($progress) ?></td>
                <td><?= e($job['progress_message']) ?></td>
                <td><?= e($job['error_message']) ?></td>
                <td><?= e($job['created_at']) ?></td>
                <td><?= e($job['finished_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php render_footer(); ?>
