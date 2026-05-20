<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$statusFilter = (string)($_GET['status'] ?? 'active');
$allowed = ['active', 'all', 'draft', 'failed', 'complete', 'cancelled'];
if (!in_array($statusFilter, $allowed, true)) {
    $statusFilter = 'active';
}

function mer_status_link(string $status, string $label, string $current): string
{
    return '<a class="button' . ($status === $current ? ' selected-row' : '') . '" href="move-executions.php?status=' . e($status) . '">' . e($label) . '</a>';
}

$where = '';
$params = [];
if ($statusFilter === 'active') {
    $where = "WHERE mer.status NOT IN ('complete', 'cancelled')";
} elseif ($statusFilter !== 'all') {
    $where = 'WHERE mer.status = ?';
    $params[] = $statusFilter;
}

$stmt = db()->prepare("SELECT mer.*, mp.status AS plan_status, mp.candidate_count, mp.total_size_bytes FROM move_execution_runs mer JOIN move_plans mp ON mp.id = mer.move_plan_id {$where} ORDER BY mer.id DESC LIMIT 100");
$stmt->execute($params);
$runs = $stmt->fetchAll();

render_header('Move Execution Runs');
?>
<div class="card">
    <h2>Move execution runs</h2>
    <p class="muted">Execution preparation/audit records only. These pages still do not move files.</p>
    <div class="button-row"><a class="button" href="move-plans.php">Saved Plans</a> <a class="button" href="planner-readiness.php">Readiness</a></div>
    <div class="button-row"><?= mer_status_link('active', 'Active', $statusFilter) ?> <?= mer_status_link('draft', 'Draft', $statusFilter) ?> <?= mer_status_link('failed', 'Failed', $statusFilter) ?> <?= mer_status_link('complete', 'Complete', $statusFilter) ?> <?= mer_status_link('cancelled', 'Cancelled', $statusFilter) ?> <?= mer_status_link('all', 'All', $statusFilter) ?></div>
</div>

<div class="card">
    <h2>Runs</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Run</th><th>Status</th><th>Plan</th><th>Plan status</th><th>Items</th><th>Plan size</th><th>Dry-run required</th><th>Created</th><th>Updated</th><th>Error</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td><a href="move-execution-view.php?id=<?= e((string)$run['id']) ?>"><?= e((string)$run['id']) ?></a></td>
                    <td><?= e((string)$run['status']) ?></td>
                    <td><a href="move-plan-view.php?id=<?= e((string)$run['move_plan_id']) ?>">#<?= e((string)$run['move_plan_id']) ?></a></td>
                    <td><?= e((string)$run['plan_status']) ?></td>
                    <td><?= e(number_format((int)$run['candidate_count'])) ?></td>
                    <td><?= e(format_bytes((int)$run['total_size_bytes'])) ?></td>
                    <td><?= (int)$run['dry_run_required'] === 1 ? 'Yes' : 'No' ?></td>
                    <td><?= e((string)$run['created_at']) ?></td>
                    <td><?= e((string)$run['updated_at']) ?></td>
                    <td class="wrap-cell"><?= e((string)($run['error_message'] ?? '')) ?></td>
                    <td class="actions"><a class="button" href="move-execution-view.php?id=<?= e((string)$run['id']) ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$runs): ?><tr><td colspan="11">No execution runs match this filter.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
