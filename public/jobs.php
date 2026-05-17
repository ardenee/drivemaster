<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Repository\JobRepository;

$repo = new JobRepository(db());
$created = (int)($_GET['created'] ?? 0);
$message = null;

function job_progress_label(array $job): string
{
    $total = $job['progress_total'] === null ? null : (int)$job['progress_total'];
    $current = (int)$job['progress_current'];
    return $total && $total > 0 ? format_bytes($current) . ' / ' . format_bytes($total) : (string)$current;
}

function job_progress_percent(array $job): float
{
    $total = $job['progress_total'] === null ? null : (int)$job['progress_total'];
    if (!$total || $total <= 0) {
        return 0.0;
    }
    return max(0.0, min(100.0, ((int)$job['progress_current'] / $total) * 100.0));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($id > 0 && $action === 'cancel') {
        $repo->requestCancel($id);
        $message = 'Cancel requested for job: ' . $id;
    }

    if ($id > 0 && $action === 'requeue') {
        $repo->requeue($id);
        $message = 'Job requeued: ' . $id;
    }

    if ($id > 0 && $action === 'delete') {
        $stmt = db()->prepare('DELETE FROM jobs WHERE id = ? AND status <> "running"');
        $stmt->execute([$id]);
        $message = $stmt->rowCount() > 0 ? 'Job removed: ' . $id : 'Running jobs must be cancelled before they can be removed.';
    }

    if ($action === 'delete_finished') {
        db()->exec("DELETE FROM jobs WHERE status IN ('complete','failed','cancelled')");
        $message = 'Finished jobs removed.';
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
    <p class="muted">Scans and future move operations run from the CLI worker. This page updates job status every 5 seconds without refreshing.</p>
    <form method="post" onsubmit="return confirm('Remove all complete, failed, and cancelled jobs?');">
        <input type="hidden" name="action" value="delete_finished">
        <button class="danger" type="submit">Remove finished jobs</button>
    </form>
    <table class="jobs-table">
        <thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Progress</th><th>Message</th><th>Error</th><th>Heartbeat</th><th>Created</th><th>Finished</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $job): ?>
            <?php
            $status = (string)$job['status'];
            $cancelRequested = (int)($job['cancel_requested'] ?? 0) === 1;
            $percent = job_progress_percent($job);
            $isComplete = $status === 'complete';
            ?>
            <tr data-job-id="<?= e((string)$job['id']) ?>">
                <td><?= e((string)$job['id']) ?></td>
                <td data-field="job_type"><?= e($job['job_type']) ?></td>
                <td data-field="status"><?= e($status) ?><?= $cancelRequested ? ' / cancel requested' : '' ?></td>
                <td>
                    <div class="job-progress-wrap" data-field="progress_wrap" style="<?= $isComplete ? 'display:none' : '' ?>">
                        <div class="job-progress-bar"><div class="job-progress-fill" data-field="progress_bar" style="width: <?= e(number_format($percent, 2, '.', '')) ?>%"></div></div>
                        <div class="job-progress-text"><span data-field="progress_label"><?= e(job_progress_label($job)) ?></span> <span data-field="progress_percent"><?= $job['progress_total'] ? e(number_format($percent, 1) . '%') : '' ?></span></div>
                    </div>
                    <span data-field="progress_complete" style="<?= $isComplete ? '' : 'display:none' ?>">Complete</span>
                </td>
                <td data-field="progress_message"><?= e($job['progress_message']) ?></td>
                <td data-field="error_message"><?= e($job['error_message']) ?></td>
                <td data-field="last_heartbeat_at"><?= e($job['last_heartbeat_at'] ?? '') ?></td>
                <td data-field="created_at"><?= e($job['created_at']) ?></td>
                <td data-field="finished_at"><?= e($job['finished_at']) ?></td>
                <td class="actions">
                    <?php if (in_array($status, ['queued', 'running'], true) && !$cancelRequested): ?>
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
                    <?php if ($status !== 'running'): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Remove this job from history?');">
                            <input type="hidden" name="id" value="<?= e((string)$job['id']) ?>">
                            <input type="hidden" name="action" value="delete">
                            <button class="danger" type="submit">Remove</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted" id="jobs-last-updated">Waiting for status update...</p>
</div>
<script>
function setText(row, field, value) {
    const cell = row.querySelector('[data-field="' + field + '"]');
    if (cell) {
        cell.textContent = value || '';
    }
}

async function refreshJobs() {
    try {
        const response = await fetch('jobs-status.php?limit=100&ts=' + Date.now(), { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        const data = await response.json();
        data.jobs.forEach(function(job) {
            const row = document.querySelector('tr[data-job-id="' + job.id + '"]');
            if (!row) {
                return;
            }
            setText(row, 'job_type', job.job_type);
            setText(row, 'status', job.status + (job.cancel_requested ? ' / cancel requested' : ''));
            setText(row, 'progress_label', job.progress_label);
            setText(row, 'progress_percent', job.progress_percent_label === '-' ? '' : job.progress_percent_label);
            setText(row, 'progress_message', job.progress_message);
            setText(row, 'error_message', job.error_message);
            setText(row, 'last_heartbeat_at', job.last_heartbeat_at);
            setText(row, 'created_at', job.created_at);
            setText(row, 'finished_at', job.finished_at);

            const isComplete = job.status === 'complete';
            const wrap = row.querySelector('[data-field="progress_wrap"]');
            const complete = row.querySelector('[data-field="progress_complete"]');
            if (wrap) {
                wrap.style.display = isComplete ? 'none' : '';
            }
            if (complete) {
                complete.style.display = isComplete ? '' : 'none';
                complete.textContent = 'Complete';
            }
            const bar = row.querySelector('[data-field="progress_bar"]');
            if (bar) {
                bar.style.width = Math.max(0, Math.min(100, Number(job.progress_percent || 0))) + '%';
            }
        });
        const updated = document.getElementById('jobs-last-updated');
        if (updated) {
            updated.textContent = 'Last updated: ' + data.updated_at;
        }
    } catch (error) {
        const updated = document.getElementById('jobs-last-updated');
        if (updated) {
            updated.textContent = 'Status update failed: ' + error.message;
        }
    }
}

refreshJobs();
setInterval(refreshJobs, 5000);
</script>
<?php render_footer(); ?>
