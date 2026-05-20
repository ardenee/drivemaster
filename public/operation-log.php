<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\OperationAudit;

OperationAudit::ensureTables(db());
$status = (string)($_GET['status'] ?? 'all');
$type = (string)($_GET['type'] ?? 'all');
$where = [];
$params = [];
if ($status !== 'all') {
    $where[] = 'status = ?';
    $params[] = $status;
}
if ($type !== 'all') {
    $where[] = 'operation_type = ?';
    $params[] = $type;
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = db()->prepare("SELECT * FROM operation_runs {$sqlWhere} ORDER BY id DESC LIMIT 200");
$stmt->execute($params);
$runs = $stmt->fetchAll();

render_header('Operation Log');
?>
<div class="page-shell">
    <div class="page-heading"><div><h2>Operation log</h2><p class="muted">Structured log of actual move executions and undo runs.</p></div><div class="button-row"><a class="button" href="undo.php">Undo</a><a class="button" href="move-executions.php">Execution Runs</a></div></div>
    <div class="card"><div class="button-row"><a class="button" href="operation-log.php">All</a><a class="button" href="operation-log.php?type=move_execution">Move executions</a><a class="button" href="operation-log.php?type=undo_move_execution">Undo runs</a><a class="button" href="operation-log.php?status=failed">Failed</a><a class="button" href="operation-log.php?status=partial">Partial</a></div></div>
    <div class="card">
        <h2>Runs</h2>
        <div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Dry-run</th><th>Source run</th><th>Items</th><th>Success</th><th>Failed</th><th>Warnings</th><th>Created</th><th>Finished</th><th>Error</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($runs as $run): ?>
            <tr><td><?= e((string)$run['id']) ?></td><td><?= e($run['operation_type']) ?></td><td><?= e($run['status']) ?></td><td><?= (int)$run['dry_run'] === 1 ? 'Yes' : 'No' ?></td><td><?= e((string)($run['source_run_id'] ?? '')) ?></td><td><?= e(number_format((int)$run['item_count'])) ?></td><td><?= e(number_format((int)$run['success_count'])) ?></td><td><?= e(number_format((int)$run['failed_count'])) ?></td><td><?= e(number_format((int)$run['warning_count'])) ?></td><td><?= e($run['created_at']) ?></td><td><?= e($run['finished_at'] ?? '') ?></td><td class="wrap-cell"><?= e($run['error_message'] ?? '') ?></td><td class="actions"><a class="button" href="operation-view.php?id=<?= e((string)$run['id']) ?>">View</a><?php if ($run['operation_type'] === 'move_execution' && in_array($run['status'], ['complete','partial'], true)): ?> <a class="button" href="undo.php?source_run_id=<?= e((string)$run['id']) ?>">Undo dry-run</a><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$runs): ?><tr><td colspan="13">No operation runs logged yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
</div>
<?php render_footer(); ?>
