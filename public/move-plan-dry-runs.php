<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

function mpdr_json(?string $json): array
{
    if (!$json) {
        return [];
    }
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (Throwable) {
        return [];
    }
}

$id = max(0, (int)($_GET['id'] ?? 0));
$plan = null;
if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM move_plans WHERE id = ?');
    $stmt->execute([$id]);
    $plan = $stmt->fetch() ?: null;
}

render_header('Move Plan Dry-Runs');
?>
<?php if (!$plan): ?>
<div class="card">
    <h2>Move plan not found</h2>
    <p>The requested saved plan snapshot could not be found.</p>
    <div class="button-row"><a class="button" href="move-plans.php">Saved Plans</a></div>
</div>
<?php render_footer(); return; ?>
<?php endif; ?>
<?php
$stmt = db()->prepare("SELECT * FROM jobs WHERE job_type = 'move_plan_dry_run' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.move_plan_id')) AS UNSIGNED) = ? ORDER BY id DESC LIMIT 50");
$stmt->execute([$id]);
$jobs = $stmt->fetchAll();
?>
<div class="card">
    <h2>Dry-run reports for plan #<?= e((string)$plan['id']) ?></h2>
    <p class="muted">These are non-destructive worker reports. They do not move, copy, delete, or rename files.</p>
    <div class="button-row"><a class="button" href="move-plan-view.php?id=<?= e((string)$plan['id']) ?>">Plan View</a> <a class="button" href="jobs.php">Jobs</a> <a class="button" href="move-plans.php">Saved Plans</a></div>
</div>

<div class="card">
    <h2>Recent dry-runs</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Job</th><th>Status</th><th>Created</th><th>Finished</th><th>Checked</th><th>Bytes</th><th>Issues</th><th>Source missing</th><th>Target unavailable</th><th>Target exists</th><th>Error</th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <?php $result = mpdr_json($job['result_json'] ?? null); ?>
                <tr>
                    <td><?= e((string)$job['id']) ?></td>
                    <td><?= e($job['status']) ?></td>
                    <td><?= e($job['created_at']) ?></td>
                    <td><?= e($job['finished_at'] ?? '') ?></td>
                    <td><?= e(number_format((int)($result['items_checked'] ?? 0))) ?></td>
                    <td><?= e(format_bytes((int)($result['bytes_checked'] ?? 0))) ?></td>
                    <td><?= e(number_format((int)($result['issue_count'] ?? 0))) ?></td>
                    <td><?= e(number_format((int)($result['source_missing'] ?? 0))) ?></td>
                    <td><?= e(number_format((int)($result['target_mount_missing'] ?? 0))) ?></td>
                    <td><?= e(number_format((int)($result['target_already_exists'] ?? 0))) ?></td>
                    <td class="wrap-cell"><?= e($job['error_message'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$jobs): ?><tr><td colspan="11">No dry-run jobs found for this plan.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ($jobs as $job): ?>
    <?php $result = mpdr_json($job['result_json'] ?? null); $samples = is_array($result['sample_issues'] ?? null) ? $result['sample_issues'] : []; ?>
    <?php if ($samples): ?>
        <div class="card">
            <h2>Sample issues from job #<?= e((string)$job['id']) ?></h2>
            <p class="muted">Showing up to 25 sample issues captured by the dry-run.</p>
            <div class="table-scroll">
                <table class="auto-table compact-table">
                    <thead><tr><th>Item</th><th>Source</th><th>Target</th><th>Path</th><th>Issues</th></tr></thead>
                    <tbody>
                    <?php foreach ($samples as $sample): ?>
                        <tr>
                            <td><?= e((string)($sample['item_id'] ?? '')) ?></td>
                            <td><?= e((string)($sample['source_drive'] ?? '')) ?></td>
                            <td><?= e((string)($sample['target_drive'] ?? '')) ?></td>
                            <td class="wrap-cell path-cell"><?= e((string)($sample['relative_path'] ?? '')) ?></td>
                            <td class="wrap-cell"><?= e(implode(', ', array_map('strval', (array)($sample['issues'] ?? [])))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
<?php render_footer(); ?>
