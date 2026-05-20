<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

function pm_table_exists(string $table): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function pm_index_exists(string $table, string $index): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function pm_count_duplicates(string $table, string $a, string $b): int
{
    if (!pm_table_exists($table)) {
        return 0;
    }
    $stmt = db()->query("SELECT COALESCE(SUM(extra_rows), 0) FROM (SELECT COUNT(*) - 1 AS extra_rows FROM {$table} WHERE {$a} IS NOT NULL AND {$b} IS NOT NULL GROUP BY {$a}, {$b} HAVING COUNT(*) > 1) x");
    return (int)$stmt->fetchColumn();
}

function pm_sync_plan_counts(): int
{
    if (!pm_table_exists('move_plans') || !pm_table_exists('move_plan_items')) {
        return 0;
    }
    $stmt = db()->prepare('UPDATE move_plans mp LEFT JOIN (SELECT move_plan_id, COUNT(*) AS item_count, COALESCE(SUM(size_bytes), 0) AS total_size FROM move_plan_items GROUP BY move_plan_id) x ON x.move_plan_id = mp.id SET mp.candidate_count = COALESCE(x.item_count, 0), mp.total_size_bytes = COALESCE(x.total_size, 0), mp.updated_at = NOW()');
    $stmt->execute();
    return $stmt->rowCount();
}

function pm_cleanup_plan_item_duplicates(): int
{
    if (!pm_table_exists('move_plan_items')) {
        return 0;
    }
    $stmt = db()->prepare('DELETE mpi FROM move_plan_items mpi JOIN move_plan_items keep ON keep.move_plan_id = mpi.move_plan_id AND keep.file_entry_id = mpi.file_entry_id AND keep.id < mpi.id');
    $stmt->execute();
    return $stmt->rowCount();
}

function pm_cleanup_execution_item_duplicates(): int
{
    if (!pm_table_exists('move_execution_items')) {
        return 0;
    }
    $stmt = db()->prepare('DELETE mei FROM move_execution_items mei JOIN move_execution_items keep ON keep.execution_run_id = mei.execution_run_id AND keep.move_plan_item_id = mei.move_plan_item_id AND keep.id < mei.id');
    $stmt->execute();
    return $stmt->rowCount();
}

function pm_add_index(string $table, string $index, string $ddl): string
{
    if (!pm_table_exists($table)) {
        return "Skipped {$index}: table {$table} does not exist.";
    }
    if (pm_index_exists($table, $index)) {
        return "Already exists: {$index}.";
    }
    db()->exec($ddl);
    return "Added index: {$index}.";
}

$message = null;
$error = null;
$results = [];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'cleanup_duplicates') {
            $removedPlan = pm_cleanup_plan_item_duplicates();
            $removedExecution = pm_cleanup_execution_item_duplicates();
            $synced = pm_sync_plan_counts();
            $results[] = 'Removed duplicate move plan items: ' . number_format($removedPlan);
            $results[] = 'Removed duplicate execution items: ' . number_format($removedExecution);
            $results[] = 'Resynced move plan count rows: ' . number_format($synced);
        }
        if ($action === 'add_indexes') {
            pm_cleanup_plan_item_duplicates();
            pm_cleanup_execution_item_duplicates();
            $results[] = pm_add_index('move_plan_items', 'uq_move_plan_items_plan_file', 'ALTER TABLE move_plan_items ADD UNIQUE KEY uq_move_plan_items_plan_file (move_plan_id, file_entry_id)');
            $results[] = pm_add_index('move_execution_items', 'uq_move_execution_items_run_plan_item', 'ALTER TABLE move_execution_items ADD UNIQUE KEY uq_move_execution_items_run_plan_item (execution_run_id, move_plan_item_id)');
            $results[] = pm_add_index('move_plan_items', 'idx_move_plan_items_status', 'ALTER TABLE move_plan_items ADD KEY idx_move_plan_items_status (move_plan_id, status)');
            $results[] = pm_add_index('move_execution_items', 'idx_move_execution_items_status', 'ALTER TABLE move_execution_items ADD KEY idx_move_execution_items_status (execution_run_id, status)');
            $results[] = pm_add_index('move_execution_runs', 'idx_move_execution_runs_plan_status', 'ALTER TABLE move_execution_runs ADD KEY idx_move_execution_runs_plan_status (move_plan_id, status)');
            $results[] = pm_add_index('jobs', 'idx_jobs_type_status', 'ALTER TABLE jobs ADD KEY idx_jobs_type_status (job_type, status)');
            pm_sync_plan_counts();
        }
        if ($action === 'sync_counts') {
            $synced = pm_sync_plan_counts();
            $results[] = 'Resynced move plan count rows: ' . number_format($synced);
        }
        $message = 'Maintenance action completed.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$planItemDuplicates = pm_count_duplicates('move_plan_items', 'move_plan_id', 'file_entry_id');
$executionItemDuplicates = pm_count_duplicates('move_execution_items', 'execution_run_id', 'move_plan_item_id');
$planItemUnique = pm_index_exists('move_plan_items', 'uq_move_plan_items_plan_file');
$executionItemUnique = pm_index_exists('move_execution_items', 'uq_move_execution_items_run_plan_item');
$planStatusIndex = pm_index_exists('move_plan_items', 'idx_move_plan_items_status');
$executionStatusIndex = pm_index_exists('move_execution_items', 'idx_move_execution_items_status');
$jobsTypeIndex = pm_index_exists('jobs', 'idx_jobs_type_status');

render_header('Planning Maintenance');
?>
<?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong><?php if ($results): ?><ul><?php foreach ($results as $result): ?><li><?= e($result) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>

<div class="card">
    <h2>Planning maintenance</h2>
    <p class="muted">Safety/cleanup tools for saved move plans, execution preparation records, and dry-run job indexing. These actions do not move files.</p>
    <div class="button-row"><a class="button" href="planning-dashboard.php">Planning Dashboard</a> <a class="button" href="move-plans.php">Saved Plans</a> <a class="button" href="move-executions.php">Execution Runs</a></div>
</div>

<div class="card">
    <h2>Current protection status</h2>
    <div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead><tbody>
        <tr><td>Duplicate move plan items</td><td><?= $planItemDuplicates > 0 ? 'Needs cleanup' : 'OK' ?></td><td><?= e(number_format($planItemDuplicates)) ?> duplicate extra row(s)</td></tr>
        <tr><td>Duplicate execution items</td><td><?= $executionItemDuplicates > 0 ? 'Needs cleanup' : 'OK' ?></td><td><?= e(number_format($executionItemDuplicates)) ?> duplicate extra row(s)</td></tr>
        <tr><td>Plan item unique index</td><td><?= $planItemUnique ? 'OK' : 'Missing' ?></td><td>uq_move_plan_items_plan_file</td></tr>
        <tr><td>Execution item unique index</td><td><?= $executionItemUnique ? 'OK' : 'Missing' ?></td><td>uq_move_execution_items_run_plan_item</td></tr>
        <tr><td>Plan item status index</td><td><?= $planStatusIndex ? 'OK' : 'Missing' ?></td><td>idx_move_plan_items_status</td></tr>
        <tr><td>Execution item status index</td><td><?= $executionStatusIndex ? 'OK' : 'Missing' ?></td><td>idx_move_execution_items_status</td></tr>
        <tr><td>Jobs type/status index</td><td><?= $jobsTypeIndex ? 'OK' : 'Missing' ?></td><td>idx_jobs_type_status</td></tr>
    </tbody></table></div>
</div>

<div class="card">
    <h2>Actions</h2>
    <form method="post" class="inline-actions" onsubmit="return confirm('Remove duplicate planning rows and resync counts?');"><input type="hidden" name="action" value="cleanup_duplicates"><button type="submit">Cleanup duplicates</button></form>
    <form method="post" class="inline-actions" onsubmit="return confirm('Add missing planning indexes? Duplicate rows will be cleaned first.');"><input type="hidden" name="action" value="add_indexes"><button type="submit">Add missing indexes</button></form>
    <form method="post" class="inline-actions"><input type="hidden" name="action" value="sync_counts"><button type="submit">Resync saved plan counts</button></form>
</div>
<?php render_footer(); ?>
