<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

function pd_rows(string $sql): array
{
    try {
        return db()->query($sql)->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

function pd_value(string $sql): int
{
    try {
        return (int)db()->query($sql)->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

$planStatuses = pd_rows("SELECT status, COUNT(*) AS item_count, COALESCE(SUM(candidate_count), 0) AS candidates, COALESCE(SUM(total_size_bytes), 0) AS total_size FROM move_plans GROUP BY status ORDER BY status");
$executionStatuses = pd_rows("SELECT status, COUNT(*) AS item_count FROM move_execution_runs GROUP BY status ORDER BY status");
$jobStatuses = pd_rows("SELECT job_type, status, COUNT(*) AS item_count FROM jobs WHERE job_type IN ('move_plan_dry_run','move_execution_run','move_execution') GROUP BY job_type, status ORDER BY job_type, status");
$itemStatuses = pd_rows("SELECT status, COUNT(*) AS item_count, COALESCE(SUM(size_bytes), 0) AS total_size FROM move_plan_items GROUP BY status ORDER BY status");
$executionItemStatuses = pd_rows("SELECT status, COUNT(*) AS item_count, COALESCE(SUM(size_bytes), 0) AS total_size FROM move_execution_items GROUP BY status ORDER BY status");

$activePlans = pd_value("SELECT COUNT(*) FROM move_plans WHERE status IN ('building','draft','validated','blocked')");
$buildingPlans = pd_value("SELECT COUNT(*) FROM move_plans WHERE status = 'building'");
$validatedPlans = pd_value("SELECT COUNT(*) FROM move_plans WHERE status = 'validated'");
$failedExecutions = pd_value("SELECT COUNT(*) FROM move_execution_runs WHERE status = 'failed'");
$simulatedExecutions = pd_value("SELECT COUNT(*) FROM move_execution_runs WHERE status = 'simulated'");
$queuedDryRuns = pd_value("SELECT COUNT(*) FROM jobs WHERE job_type = 'move_plan_dry_run' AND status IN ('queued','running')");

render_header('Planning Dashboard');
?>
<div class="card">
    <h2>Planning dashboard</h2>
    <p class="muted">High-level read-only status for category planning, saved snapshots, dry-runs, execution preparation, and simulated execution. This page does not move files.</p>
    <div class="button-row"><a class="button" href="planner-readiness.php">Readiness</a> <a class="button" href="move-planner.php">Move Planner</a> <a class="button" href="move-plans.php">Saved Plans</a> <a class="button" href="move-executions.php">Execution Runs</a> <a class="button" href="move-execution-settings.php">Execution Settings</a> <a class="button" href="planning-maintenance.php">Maintenance</a></div>
    <div class="status-bar">Active plans: <?= e(number_format($activePlans)) ?> | Building: <?= e(number_format($buildingPlans)) ?> | Validated: <?= e(number_format($validatedPlans)) ?> | Failed execution runs: <?= e(number_format($failedExecutions)) ?> | Simulated runs: <?= e(number_format($simulatedExecutions)) ?> | Queued/running dry-runs: <?= e(number_format($queuedDryRuns)) ?></div>
</div>

<div class="card"><h2>Saved plan statuses</h2><div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Status</th><th>Plans</th><th>Candidates</th><th>Total size</th></tr></thead><tbody><?php foreach ($planStatuses as $row): ?><tr><td><?= e($row['status']) ?></td><td><?= e(number_format((int)$row['item_count'])) ?></td><td><?= e(number_format((int)$row['candidates'])) ?></td><td><?= e(format_bytes((int)$row['total_size'])) ?></td></tr><?php endforeach; ?><?php if (!$planStatuses): ?><tr><td colspan="4">No saved plans found.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><h2>Move plan item statuses</h2><div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Status</th><th>Items</th><th>Total size</th></tr></thead><tbody><?php foreach ($itemStatuses as $row): ?><tr><td><?= e($row['status']) ?></td><td><?= e(number_format((int)$row['item_count'])) ?></td><td><?= e(format_bytes((int)$row['total_size'])) ?></td></tr><?php endforeach; ?><?php if (!$itemStatuses): ?><tr><td colspan="3">No saved plan items found.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><h2>Planning jobs</h2><div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Job type</th><th>Status</th><th>Jobs</th></tr></thead><tbody><?php foreach ($jobStatuses as $row): ?><tr><td><?= e($row['job_type']) ?></td><td><?= e($row['status']) ?></td><td><?= e(number_format((int)$row['item_count'])) ?></td></tr><?php endforeach; ?><?php if (!$jobStatuses): ?><tr><td colspan="3">No planning jobs found.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><h2>Execution preparation statuses</h2><div class="form-grid"><div><h3>Runs</h3><div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Status</th><th>Runs</th></tr></thead><tbody><?php foreach ($executionStatuses as $row): ?><tr><td><?= e($row['status']) ?></td><td><?= e(number_format((int)$row['item_count'])) ?></td></tr><?php endforeach; ?><?php if (!$executionStatuses): ?><tr><td colspan="2">No execution runs found.</td></tr><?php endif; ?></tbody></table></div></div><div><h3>Items</h3><div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Status</th><th>Items</th><th>Total size</th></tr></thead><tbody><?php foreach ($executionItemStatuses as $row): ?><tr><td><?= e($row['status']) ?></td><td><?= e(number_format((int)$row['item_count'])) ?></td><td><?= e(format_bytes((int)$row['total_size'])) ?></td></tr><?php endforeach; ?><?php if (!$executionItemStatuses): ?><tr><td colspan="3">No execution items found.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
<?php render_footer(); ?>
