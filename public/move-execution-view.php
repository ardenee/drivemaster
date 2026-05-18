<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

const MOVE_EXECUTION_VIEW_PAGE_SIZE = 990;

function mev_link(array $params = []): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        }
    }
    return 'move-execution-view.php?' . http_build_query($query);
}

$id = max(0, (int)($_GET['id'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * MOVE_EXECUTION_VIEW_PAGE_SIZE;
$sort = (string)($_GET['sort'] ?? 'target');
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$nextDir = $dir === 'asc' ? 'desc' : 'asc';

$run = null;
if ($id > 0) {
    $stmt = db()->prepare('SELECT mer.*, mp.status AS plan_status, mp.candidate_count, mp.total_size_bytes FROM move_execution_runs mer JOIN move_plans mp ON mp.id = mer.move_plan_id WHERE mer.id = ?');
    $stmt->execute([$id]);
    $run = $stmt->fetch() ?: null;
}

render_header('Move Execution View');
?>
<?php if (!$run): ?>
<div class="card">
    <h2>Execution run not found</h2>
    <p>The requested execution preparation run could not be found.</p>
    <div class="button-row"><a class="button" href="move-plans.php">Saved Plans</a></div>
</div>
<?php render_footer(); return; ?>
<?php endif; ?>
<?php
$sortSql = match ($sort) {
    'source' => 'mei.source_path',
    'target' => 'mei.target_path',
    'size' => 'mei.size_bytes',
    'status' => 'mei.status',
    default => 'mei.target_path',
};

$countStmt = db()->prepare('SELECT COUNT(*) FROM move_execution_items WHERE execution_run_id = ?');
$countStmt->execute([$id]);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / MOVE_EXECUTION_VIEW_PAGE_SIZE));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * MOVE_EXECUTION_VIEW_PAGE_SIZE;
}

$itemStmt = db()->prepare("SELECT mei.*, mpi.relative_path, c.name AS category_name, c.color_hex FROM move_execution_items mei JOIN move_plan_items mpi ON mpi.id = mei.move_plan_item_id JOIN categories c ON c.id = mpi.category_id WHERE mei.execution_run_id = ? ORDER BY {$sortSql} {$dir}, mei.id ASC LIMIT ? OFFSET ?");
$itemStmt->bindValue(1, $id, PDO::PARAM_INT);
$itemStmt->bindValue(2, MOVE_EXECUTION_VIEW_PAGE_SIZE, PDO::PARAM_INT);
$itemStmt->bindValue(3, $offset, PDO::PARAM_INT);
$itemStmt->execute();
$items = $itemStmt->fetchAll();

$statusStmt = db()->prepare('SELECT status, COUNT(*) AS item_count, COALESCE(SUM(size_bytes), 0) AS total_size FROM move_execution_items WHERE execution_run_id = ? GROUP BY status ORDER BY status');
$statusStmt->execute([$id]);
$statusSummary = $statusStmt->fetchAll();
?>

<div class="card">
    <h2>Move execution preparation #<?= e((string)$run['id']) ?></h2>
    <p class="muted">Prepared execution audit records only. This page does not queue jobs and does not move files.</p>
    <div class="status-bar">Run status: <?= e($run['status']) ?> | Plan: #<?= e((string)$run['move_plan_id']) ?> | Plan status: <?= e($run['plan_status']) ?> | Items: <?= e(number_format($total)) ?> | Created: <?= e($run['created_at']) ?></div>
    <div class="status-bar">Dry-run required: <?= (int)$run['dry_run_required'] === 1 ? 'Yes' : 'No' ?> | Started: <?= e($run['started_at'] ?? '') ?> | Finished: <?= e($run['finished_at'] ?? '') ?></div>
    <?php if ($run['error_message']): ?><p><strong>Error:</strong> <?= e($run['error_message']) ?></p><?php endif; ?>
    <div class="button-row"><a class="button" href="move-plan-view.php?id=<?= e((string)$run['move_plan_id']) ?>">Plan View</a> <a class="button" href="move-plan-dry-runs.php?id=<?= e((string)$run['move_plan_id']) ?>">Dry-run Reports</a> <a class="button" href="move-plans.php">Saved Plans</a></div>
</div>

<div class="card">
    <h2>Execution item status summary</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Status</th><th>Items</th><th>Total size</th></tr></thead>
            <tbody>
            <?php foreach ($statusSummary as $row): ?>
                <tr><td><?= e($row['status']) ?></td><td><?= e(number_format((int)$row['item_count'])) ?></td><td><?= e(format_bytes((int)$row['total_size'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$statusSummary): ?><tr><td colspan="3">No execution items found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Prepared execution items</h2>
    <p class="muted">Showing <?= e(number_format($total === 0 ? 0 : $offset + 1)) ?>-<?= e(number_format(min($offset + count($items), $total))) ?> of <?= e(number_format($total)) ?> prepared items.</p>
    <div class="table-scroll">
        <table class="auto-table compact-table selectable-table">
            <thead><tr>
                <?php foreach (['status' => 'Status', 'source' => 'Source path', 'target' => 'Target path', 'size' => 'Size'] as $key => $label): ?>
                    <th><a href="<?= e(mev_link(['sort' => $key, 'dir' => $sort === $key ? $nextDir : 'asc', 'page' => 1])) ?>"><?= e($label) ?><?= $sort === $key ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                <?php endforeach; ?>
                <th>Temp target</th><th>Error</th>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr class="selectable-row">
                    <td><?= e($item['status']) ?></td>
                    <td class="wrap-cell path-cell"><?= e($item['source_path']) ?></td>
                    <td class="wrap-cell path-cell"><?= e($item['target_path']) ?></td>
                    <td><?= e(format_bytes((int)$item['size_bytes'])) ?></td>
                    <td class="wrap-cell path-cell"><?= e($item['temp_target_path'] ?? '') ?></td>
                    <td class="wrap-cell"><?= e($item['error_message'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?><tr><td colspan="6">No execution items found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > MOVE_EXECUTION_VIEW_PAGE_SIZE): ?>
        <div class="status-bar">Page <?= e(number_format($page)) ?> of <?= e(number_format($totalPages)) ?>. <a class="button" href="<?= e(mev_link(['page' => max(1, $page - 1)])) ?>">Previous</a> <a class="button" href="<?= e(mev_link(['page' => min($totalPages, $page + 1)])) ?>">Next</a></div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
