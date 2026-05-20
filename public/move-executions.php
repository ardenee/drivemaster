<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$statusFilter = (string)($_GET['status'] ?? 'active');
$allowed = ['active', 'all', 'draft', 'failed', 'simulated', 'complete', 'cancelled'];
if (!in_array($statusFilter, $allowed, true)) {
    $statusFilter = 'active';
}
$message = null;
$error = null;

function mer_status_link(string $status, string $label, string $current): string
{
    return '<a class="button' . ($status === $current ? ' selected-row' : '') . '" href="move-executions.php?status=' . e($status) . '">' . e($label) . '</a>';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $id = max(0, (int)($_POST['id'] ?? 0));
        if ($id <= 0) {
            throw new RuntimeException('Missing execution run ID.');
        }
        if ($action === 'cancel_run') {
            $stmt = db()->prepare("UPDATE move_execution_runs SET status = 'cancelled', error_message = 'Cancelled by user before execution.', updated_at = NOW() WHERE id = ? AND status IN ('draft', 'failed')");
            $stmt->execute([$id]);
            db()->prepare("UPDATE move_execution_items SET status = 'cancelled', error_message = 'Execution run cancelled.', updated_at = NOW() WHERE execution_run_id = ? AND status IN ('pending', 'failed')")->execute([$id]);
            header('Location: move-executions.php?status=' . urlencode($statusFilter));
            exit;
        }
        if ($action === 'delete_run') {
            $stmt = db()->prepare("DELETE FROM move_execution_runs WHERE id = ? AND status IN ('draft', 'failed', 'cancelled')");
            $stmt->execute([$id]);
            header('Location: move-executions.php?status=' . urlencode($statusFilter));
            exit;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$where = '';
$params = [];
if ($statusFilter === 'active') {
    $where = "WHERE mer.status NOT IN ('complete', 'cancelled', 'simulated')";
} elseif ($statusFilter !== 'all') {
    $where = 'WHERE mer.status = ?';
    $params[] = $statusFilter;
}

$stmt = db()->prepare("SELECT mer.*, mp.status AS plan_status, mp.candidate_count, mp.total_size_bytes, COUNT(mei.id) AS execution_item_count FROM move_execution_runs mer JOIN move_plans mp ON mp.id = mer.move_plan_id LEFT JOIN move_execution_items mei ON mei.execution_run_id = mer.id {$where} GROUP BY mer.id, mp.status, mp.candidate_count, mp.total_size_bytes ORDER BY mer.id DESC LIMIT 100");
$stmt->execute($params);
$runs = $stmt->fetchAll();

render_header('Move Execution Runs');
?>
<?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong></div><?php endif; ?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
<div class="card">
    <h2>Move execution runs</h2>
    <p class="muted">Execution preparation/audit records only. Simulated runs update database audit rows only; they do not move files.</p>
    <div class="button-row"><a class="button" href="move-plans.php">Saved Plans</a> <a class="button" href="planner-readiness.php">Readiness</a> <a class="button" href="move-execution-settings.php">Execution Settings</a></div>
    <div class="button-row"><?= mer_status_link('active', 'Active', $statusFilter) ?> <?= mer_status_link('draft', 'Draft', $statusFilter) ?> <?= mer_status_link('failed', 'Failed', $statusFilter) ?> <?= mer_status_link('simulated', 'Simulated', $statusFilter) ?> <?= mer_status_link('complete', 'Complete', $statusFilter) ?> <?= mer_status_link('cancelled', 'Cancelled', $statusFilter) ?> <?= mer_status_link('all', 'All', $statusFilter) ?></div>
</div>

<div class="card">
    <h2>Runs</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Run</th><th>Status</th><th>Plan</th><th>Plan status</th><th>Execution items</th><th>Plan items</th><th>Plan size</th><th>Dry-run required</th><th>Created</th><th>Updated</th><th>Error</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td><a href="move-execution-view.php?id=<?= e((string)$run['id']) ?>"><?= e((string)$run['id']) ?></a></td>
                    <td><?= e((string)$run['status']) ?></td>
                    <td><a href="move-plan-view.php?id=<?= e((string)$run['move_plan_id']) ?>">#<?= e((string)$run['move_plan_id']) ?></a></td>
                    <td><?= e((string)$run['plan_status']) ?></td>
                    <td><?= e(number_format((int)$run['execution_item_count'])) ?></td>
                    <td><?= e(number_format((int)$run['candidate_count'])) ?></td>
                    <td><?= e(format_bytes((int)$run['total_size_bytes'])) ?></td>
                    <td><?= (int)$run['dry_run_required'] === 1 ? 'Yes' : 'No' ?></td>
                    <td><?= e((string)$run['created_at']) ?></td>
                    <td><?= e((string)$run['updated_at']) ?></td>
                    <td class="wrap-cell"><?= e((string)($run['error_message'] ?? '')) ?></td>
                    <td class="actions"><a class="button" href="move-execution-view.php?id=<?= e((string)$run['id']) ?>">View</a><?php if (in_array((string)$run['status'], ['draft', 'failed'], true)): ?> <form method="post" style="display:inline" onsubmit="return confirm('Cancel this execution run? No files will be moved.');"><input type="hidden" name="action" value="cancel_run"><input type="hidden" name="id" value="<?= e((string)$run['id']) ?>"><button type="submit">Cancel</button></form> <form method="post" style="display:inline" onsubmit="return confirm('Delete this execution run audit record?');"><input type="hidden" name="action" value="delete_run"><input type="hidden" name="id" value="<?= e((string)$run['id']) ?>"><button class="danger" type="submit">Delete</button></form><?php elseif ((string)$run['status'] === 'cancelled'): ?> <form method="post" style="display:inline" onsubmit="return confirm('Delete this cancelled execution run audit record?');"><input type="hidden" name="action" value="delete_run"><input type="hidden" name="id" value="<?= e((string)$run['id']) ?>"><button class="danger" type="submit">Delete</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$runs): ?><tr><td colspan="12">No execution runs match this filter.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
