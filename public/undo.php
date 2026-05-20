<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\OperationAudit;

OperationAudit::ensureTables(db());
$message = null;
$error = null;
$dryRunId = max(0, (int)($_GET['dry_run_id'] ?? 0));
$sourceRunId = max(0, (int)($_GET['source_run_id'] ?? 0));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'dry_run') {
            $sourceRunId = max(0, (int)($_POST['source_run_id'] ?? 0));
            if ($sourceRunId <= 0) {
                throw new RuntimeException('Select an operation run to undo.');
            }
            $dryRunId = OperationAudit::dryRunUndo(db(), $sourceRunId, (int)(current_user()['id'] ?? 0) ?: null);
            header('Location: undo.php?dry_run_id=' . $dryRunId);
            exit;
        }
        if ($action === 'execute') {
            $dryRunId = max(0, (int)($_POST['dry_run_id'] ?? 0));
            if ($dryRunId <= 0) {
                throw new RuntimeException('Missing undo dry-run ID.');
            }
            $runId = OperationAudit::executeUndo(db(), $dryRunId, (int)(current_user()['id'] ?? 0) ?: null);
            header('Location: operation-view.php?id=' . $runId);
            exit;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$candidates = db()->query("SELECT * FROM operation_runs WHERE operation_type = 'move_execution' AND status IN ('complete','partial') ORDER BY id DESC LIMIT 100")->fetchAll();
$dryRun = null;
$dryItems = [];
if ($dryRunId > 0) {
    $stmt = db()->prepare("SELECT * FROM operation_runs WHERE id = ? AND operation_type = 'undo_move_execution' AND dry_run = 1");
    $stmt->execute([$dryRunId]);
    $dryRun = $stmt->fetch() ?: null;
    if ($dryRun) {
        $itemsStmt = db()->prepare('SELECT * FROM operation_items WHERE operation_run_id = ? ORDER BY status DESC, id LIMIT 1000');
        $itemsStmt->execute([$dryRunId]);
        $dryItems = $itemsStmt->fetchAll();
    }
}

render_header('Undo');
?>
<div class="page-shell">
    <div class="page-heading"><div><h2>Undo move operation</h2><p class="muted">Undo always starts with a dry-run report. You can continue even if some items cannot be undone; DriveMaster will only undo what is safe.</p></div><div class="button-row"><a class="button" href="operation-log.php">Operation Log</a><a class="button" href="drive-verify.php">Verify Drives</a></div></div>
    <?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong></div><?php endif; ?>
    <?php if ($error): ?><div class="card danger-zone"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>

    <div class="card">
        <h2>1. Select move operation to undo</h2>
        <form method="post" class="admin-form">
            <input type="hidden" name="action" value="dry_run">
            <label>Move operation
                <select name="source_run_id" required>
                    <option value="">Select operation</option>
                    <?php foreach ($candidates as $candidate): ?><option value="<?= e((string)$candidate['id']) ?>" <?= $sourceRunId === (int)$candidate['id'] ? 'selected' : '' ?>>#<?= e((string)$candidate['id']) ?> - <?= e($candidate['status']) ?> - <?= e(number_format((int)$candidate['success_count'])) ?> moved - <?= e($candidate['created_at']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Run undo dry-run</button>
        </form>
    </div>

    <?php if ($dryRun): ?>
        <div class="card">
            <h2>2. Undo dry-run report #<?= e((string)$dryRun['id']) ?></h2>
            <div class="status-bar">Items: <?= e(number_format((int)$dryRun['item_count'])) ?> | Can undo: <?= e(number_format((int)$dryRun['success_count'])) ?> | Cannot undo: <?= e(number_format((int)$dryRun['failed_count'])) ?> | Warnings: <?= e(number_format((int)$dryRun['warning_count'])) ?></div>
            <p class="muted">Failed items will be skipped if you continue. Warning items may still fail if the required drive/path is not available.</p>
            <form method="post" onsubmit="return confirm('Run undo now? Items that cannot be safely undone will be skipped.');"><input type="hidden" name="action" value="execute"><input type="hidden" name="dry_run_id" value="<?= e((string)$dryRun['id']) ?>"><button class="danger" type="submit">Continue and undo what is possible</button></form>
        </div>
        <div class="card"><h2>Dry-run item details</h2><div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Status</th><th>Moved location</th><th>Original location</th><th>Size</th><th>Message</th></tr></thead><tbody>
        <?php foreach ($dryItems as $item): ?><tr><td><?= e($item['status']) ?></td><td class="wrap-cell path-cell"><?= e($item['before_path'] ?? '') ?></td><td class="wrap-cell path-cell"><?= e($item['after_path'] ?? '') ?></td><td><?= e(format_bytes((int)$item['size_bytes'])) ?></td><td class="wrap-cell"><?= e($item['message'] ?? '') ?></td></tr><?php endforeach; ?>
        <?php if (!$dryItems): ?><tr><td colspan="5">No dry-run items.</td></tr><?php endif; ?>
        </tbody></table></div></div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
