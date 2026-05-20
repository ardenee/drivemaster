<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

function mt_index_exists(string $table, string $index): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function mt_apply_index(string $label, string $table, string $index, string $sql): array
{
    if (mt_index_exists($table, $index)) {
        return ['label' => $label, 'status' => 'exists', 'message' => 'Already present'];
    }
    try {
        db()->exec($sql);
        return ['label' => $label, 'status' => 'created', 'message' => 'Created'];
    } catch (Throwable $e) {
        return ['label' => $label, 'status' => 'failed', 'message' => $e->getMessage()];
    }
}

$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'apply_move_indexes') {
        $results[] = mt_apply_index('Unique move plan item per file', 'move_plan_items', 'uq_move_plan_items_plan_file', 'ALTER TABLE move_plan_items ADD UNIQUE KEY uq_move_plan_items_plan_file (move_plan_id, file_entry_id)');
        $results[] = mt_apply_index('Unique execution item per plan item', 'move_execution_items', 'uq_move_execution_items_run_plan_item', 'ALTER TABLE move_execution_items ADD UNIQUE KEY uq_move_execution_items_run_plan_item (execution_run_id, move_plan_item_id)');
        $results[] = mt_apply_index('Move plan item status lookup', 'move_plan_items', 'idx_move_plan_items_plan_status', 'ALTER TABLE move_plan_items ADD KEY idx_move_plan_items_plan_status (move_plan_id, status)');
        $results[] = mt_apply_index('Execution item status lookup', 'move_execution_items', 'idx_move_execution_items_run_status', 'ALTER TABLE move_execution_items ADD KEY idx_move_execution_items_run_status (execution_run_id, status)');
        $results[] = mt_apply_index('Execution run plan/status lookup', 'move_execution_runs', 'idx_move_execution_runs_plan_status', 'ALTER TABLE move_execution_runs ADD KEY idx_move_execution_runs_plan_status (move_plan_id, status)');
        $results[] = mt_apply_index('Jobs type/status lookup', 'jobs', 'idx_jobs_type_status', 'ALTER TABLE jobs ADD KEY idx_jobs_type_status (job_type, status)');
    }
}

$checks = [
    ['table' => 'move_plan_items', 'index' => 'uq_move_plan_items_plan_file', 'label' => 'Unique move plan item per file'],
    ['table' => 'move_execution_items', 'index' => 'uq_move_execution_items_run_plan_item', 'label' => 'Unique execution item per plan item'],
    ['table' => 'move_plan_items', 'index' => 'idx_move_plan_items_plan_status', 'label' => 'Move plan item status lookup'],
    ['table' => 'move_execution_items', 'index' => 'idx_move_execution_items_run_status', 'label' => 'Execution item status lookup'],
    ['table' => 'move_execution_runs', 'index' => 'idx_move_execution_runs_plan_status', 'label' => 'Execution run plan/status lookup'],
    ['table' => 'jobs', 'index' => 'idx_jobs_type_status', 'label' => 'Jobs type/status lookup'],
];

render_header('Maintenance');
?>
<div class="card">
    <h2>Maintenance</h2>
    <p class="muted">Database protection and performance checks. These actions do not move files.</p>
    <form method="post" onsubmit="return confirm('Apply missing move-planning database protections?');">
        <input type="hidden" name="action" value="apply_move_indexes">
        <button type="submit">Apply move-planning DB protections</button>
    </form>
</div>

<?php if ($results): ?>
<div class="card">
    <h2>Apply results</h2>
    <div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Protection</th><th>Status</th><th>Message</th></tr></thead><tbody>
    <?php foreach ($results as $row): ?><tr><td><?= e($row['label']) ?></td><td><?= e($row['status']) ?></td><td class="wrap-cell"><?= e($row['message']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>

<div class="card">
    <h2>Current protections</h2>
    <div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Protection</th><th>Table</th><th>Index</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($checks as $check): ?>
        <?php $exists = mt_index_exists($check['table'], $check['index']); ?>
        <tr><td><?= e($check['label']) ?></td><td><?= e($check['table']) ?></td><td><?= e($check['index']) ?></td><td><?= $exists ? 'Present' : 'Missing' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php render_footer(); ?>
