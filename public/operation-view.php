<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\OperationAudit;

OperationAudit::ensureTables(db());
$id = max(0, (int)($_GET['id'] ?? 0));
$stmt = db()->prepare('SELECT * FROM operation_runs WHERE id = ?');
$stmt->execute([$id]);
$run = $stmt->fetch();

render_header('Operation View');
?>
<div class="page-shell">
<?php if (!$run): ?>
    <div class="card"><h2>Operation run not found</h2><a class="button" href="operation-log.php">Operation Log</a></div>
<?php else: ?>
    <?php
    $itemsStmt = db()->prepare('SELECT * FROM operation_items WHERE operation_run_id = ? ORDER BY id LIMIT 1000');
    $itemsStmt->execute([$id]);
    $items = $itemsStmt->fetchAll();
    ?>
    <div class="page-heading"><div><h2>Operation #<?= e((string)$run['id']) ?></h2><p class="muted"><?= e($run['operation_type']) ?> | <?= e($run['status']) ?></p></div><div class="button-row"><a class="button" href="operation-log.php">Operation Log</a><?php if ($run['operation_type'] === 'move_execution' && in_array($run['status'], ['complete','partial'], true)): ?><a class="button" href="undo.php?source_run_id=<?= e((string)$run['id']) ?>">Undo dry-run</a><?php endif; ?></div></div>
    <div class="card"><div class="status-bar">Items: <?= e(number_format((int)$run['item_count'])) ?> | Success: <?= e(number_format((int)$run['success_count'])) ?> | Failed: <?= e(number_format((int)$run['failed_count'])) ?> | Warnings: <?= e(number_format((int)$run['warning_count'])) ?> | Dry-run: <?= (int)$run['dry_run'] === 1 ? 'Yes' : 'No' ?></div><?php if ($run['error_message']): ?><p><strong>Error:</strong> <?= e($run['error_message']) ?></p><?php endif; ?></div>
    <div class="card"><h2>Items</h2><div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>ID</th><th>Action</th><th>Status</th><th>Source drive</th><th>Target drive</th><th>Before path</th><th>After path</th><th>Size</th><th>Message</th></tr></thead><tbody>
    <?php foreach ($items as $item): ?>
        <tr><td><?= e((string)$item['id']) ?></td><td><?= e($item['action_type']) ?></td><td><?= e($item['status']) ?></td><td><?= e((string)($item['source_drive_id'] ?? '')) ?></td><td><?= e((string)($item['target_drive_id'] ?? '')) ?></td><td class="wrap-cell path-cell"><?= e($item['before_path'] ?? '') ?></td><td class="wrap-cell path-cell"><?= e($item['after_path'] ?? '') ?></td><td><?= e(format_bytes((int)$item['size_bytes'])) ?></td><td class="wrap-cell"><?= e($item['message'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="9">No operation items logged.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php endif; ?>
</div>
<?php render_footer(); ?>
