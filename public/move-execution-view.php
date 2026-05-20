<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\ExecutionSettings;

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

function mev_json(?string $json): array
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

function mev_final_safety_check(int $runId): array
{
    $db = db();
    $summary = ['checked' => 0, 'passed' => 0, 'failed' => 0];
    $runStmt = $db->prepare('SELECT mer.*, mp.status AS plan_status FROM move_execution_runs mer JOIN move_plans mp ON mp.id = mer.move_plan_id WHERE mer.id = ?');
    $runStmt->execute([$runId]);
    $run = $runStmt->fetch();
    if (!$run) {
        throw new RuntimeException('Execution run not found.');
    }
    if (!in_array((string)$run['status'], ['draft', 'failed'], true)) {
        throw new RuntimeException('Only draft or failed execution runs can be safety checked.');
    }
    if ((string)$run['plan_status'] !== 'validated') {
        throw new RuntimeException('The parent plan must be validated before execution safety checks.');
    }
    if ((int)$run['dry_run_required'] === 1) {
        $dryRunStmt = $db->prepare("SELECT result_json FROM jobs WHERE job_type = 'move_plan_dry_run' AND status = 'complete' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.move_plan_id')) AS UNSIGNED) = ? ORDER BY id DESC LIMIT 1");
        $dryRunStmt->execute([(int)$run['move_plan_id']]);
        $dryRunResult = mev_json((string)($dryRunStmt->fetchColumn() ?: ''));
        if (!$dryRunResult) {
            throw new RuntimeException('A completed dry-run is required before this execution run can be safety checked.');
        }
        if ((int)($dryRunResult['issue_count'] ?? 0) > 0) {
            throw new RuntimeException('Latest completed dry-run has issues. Review the dry-run report before preparing execution.');
        }
    }

    $itemsStmt = $db->prepare('SELECT * FROM move_execution_items WHERE execution_run_id = ? ORDER BY id');
    $itemsStmt->execute([$runId]);
    $items = $itemsStmt->fetchAll();
    if (!$items) {
        throw new RuntimeException('Execution run has no items.');
    }

    $updateItem = $db->prepare('UPDATE move_execution_items SET status = ?, error_message = ?, updated_at = NOW() WHERE id = ?');
    foreach ($items as $item) {
        $summary['checked']++;
        $reasons = [];
        $sourcePath = (string)$item['source_path'];
        $targetPath = (string)$item['target_path'];
        $tempTargetPath = (string)($item['temp_target_path'] ?? '');
        $targetDir = dirname($targetPath);
        $tempTargetDir = $tempTargetPath !== '' ? dirname($tempTargetPath) : $targetDir;
        if ($sourcePath === '' || !is_file($sourcePath)) {
            $reasons[] = 'Source file is not currently visible on disk.';
        } elseif (filesize($sourcePath) !== (int)$item['size_bytes']) {
            $reasons[] = 'Source file size no longer matches prepared size.';
        }
        if ($targetPath === '') {
            $reasons[] = 'Target path is empty.';
        } elseif (file_exists($targetPath)) {
            $reasons[] = 'Target path already exists.';
        }
        if ($targetDir === '' || !is_dir($targetDir)) {
            $reasons[] = 'Target directory does not currently exist.';
        } elseif (!is_writable($targetDir)) {
            $reasons[] = 'Target directory is not writable.';
        }
        if ($tempTargetPath === '') {
            $reasons[] = 'Temporary target path is empty.';
        } elseif (file_exists($tempTargetPath)) {
            $reasons[] = 'Temporary target path already exists.';
        }
        if ($tempTargetDir === '' || !is_dir($tempTargetDir)) {
            $reasons[] = 'Temporary target directory does not currently exist.';
        }
        $reasons = array_values(array_unique($reasons));
        if ($reasons) {
            $summary['failed']++;
            $updateItem->execute(['failed', implode(' ', $reasons), (int)$item['id']]);
        } else {
            $summary['passed']++;
            $updateItem->execute(['pending', null, (int)$item['id']]);
        }
    }
    $runStatus = $summary['failed'] > 0 ? 'failed' : 'draft';
    $errorMessage = $summary['failed'] > 0 ? 'Final safety check failed for ' . $summary['failed'] . ' item(s).' : null;
    db()->prepare('UPDATE move_execution_runs SET status = ?, error_message = ?, updated_at = NOW() WHERE id = ?')->execute([$runStatus, $errorMessage, $runId]);
    return $summary;
}

$id = max(0, (int)($_GET['id'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * MOVE_EXECUTION_VIEW_PAGE_SIZE;
$sort = (string)($_GET['sort'] ?? 'target');
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$nextDir = $dir === 'asc' ? 'desc' : 'asc';
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $postId = max(0, (int)($_POST['id'] ?? 0));
    if ($action === 'final_safety_check' && $postId > 0) {
        try {
            $summary = mev_final_safety_check($postId);
            header('Location: move-execution-view.php?id=' . $postId . '&checked=1&total=' . (int)$summary['checked'] . '&passed=' . (int)$summary['passed'] . '&failed=' . (int)$summary['failed']);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $id = $postId;
        }
    }
    if ($action === 'reset_to_draft' && $postId > 0) {
        db()->prepare("UPDATE move_execution_runs SET status = 'draft', started_at = NULL, finished_at = NULL, error_message = NULL, updated_at = NOW() WHERE id = ? AND status IN ('failed','simulated','cancelled')")->execute([$postId]);
        db()->prepare("UPDATE move_execution_items SET status = 'pending', error_message = NULL, updated_at = NOW() WHERE execution_run_id = ? AND status IN ('failed','simulated','cancelled')")->execute([$postId]);
        header('Location: move-execution-view.php?id=' . $postId . '&reset=1');
        exit;
    }
    if ($action === 'cancel_run' && $postId > 0) {
        db()->prepare("UPDATE move_execution_runs SET status = 'cancelled', error_message = 'Cancelled by user before execution.', updated_at = NOW() WHERE id = ? AND status IN ('draft', 'failed', 'simulated')")->execute([$postId]);
        db()->prepare("UPDATE move_execution_items SET status = 'cancelled', error_message = 'Execution run cancelled.', updated_at = NOW() WHERE execution_run_id = ? AND status IN ('pending', 'failed', 'simulated')")->execute([$postId]);
        header('Location: move-execution-view.php?id=' . $postId . '&cancelled=1');
        exit;
    }
    if ($action === 'delete_run' && $postId > 0) {
        db()->prepare("DELETE FROM move_execution_runs WHERE id = ? AND status IN ('draft', 'failed', 'cancelled', 'simulated')")->execute([$postId]);
        header('Location: move-executions.php');
        exit;
    }
}

if (isset($_GET['checked'])) {
    $message = 'Final safety check complete. Checked: ' . number_format((int)($_GET['total'] ?? 0)) . ' | Passed: ' . number_format((int)($_GET['passed'] ?? 0)) . ' | Failed: ' . number_format((int)($_GET['failed'] ?? 0));
}
if (isset($_GET['cancelled'])) {
    $message = 'Execution run cancelled. No files were moved.';
}
if (isset($_GET['simulated'])) {
    $message = 'Simulated execution complete. Items marked simulated: ' . number_format((int)$_GET['simulated']) . '. No files were moved.';
}
if (isset($_GET['reset'])) {
    $message = 'Execution run reset to draft/pending state.';
}
if (isset($_GET['moved'])) {
    $message = 'Actual execution complete. Moved: ' . number_format((int)$_GET['moved']) . ' | Failed: ' . number_format((int)($_GET['move_failed'] ?? 0));
}
$executionError = (string)($_GET['execution_error'] ?? '');

$run = null;
if ($id > 0) {
    $stmt = db()->prepare('SELECT mer.*, mp.status AS plan_status, mp.candidate_count, mp.total_size_bytes FROM move_execution_runs mer JOIN move_plans mp ON mp.id = mer.move_plan_id WHERE mer.id = ?');
    $stmt->execute([$id]);
    $run = $stmt->fetch() ?: null;
}

render_header('Move Execution View');
?>
<?php if (!$run): ?>
<div class="card"><h2>Execution run not found</h2><p>The requested execution preparation run could not be found.</p><div class="button-row"><a class="button" href="move-executions.php">Execution Runs</a> <a class="button" href="move-plans.php">Saved Plans</a></div></div>
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
$pending = 0; $failed = 0; $cancelled = 0; $simulated = 0;
foreach ($statusSummary as $row) {
    if ($row['status'] === 'pending') { $pending = (int)$row['item_count']; }
    if ($row['status'] === 'failed') { $failed = (int)$row['item_count']; }
    if ($row['status'] === 'cancelled') { $cancelled = (int)$row['item_count']; }
    if ($row['status'] === 'simulated') { $simulated = (int)$row['item_count']; }
}
$latestDryRunStmt = db()->prepare("SELECT id, status, result_json, error_message, finished_at FROM jobs WHERE job_type = 'move_plan_dry_run' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.move_plan_id')) AS UNSIGNED) = ? ORDER BY id DESC LIMIT 1");
$latestDryRunStmt->execute([(int)$run['move_plan_id']]);
$latestDryRun = $latestDryRunStmt->fetch() ?: null;
$latestDryRunResult = $latestDryRun ? mev_json($latestDryRun['result_json'] ?? null) : [];
$dryRunOk = $latestDryRun && (string)$latestDryRun['status'] === 'complete' && (int)($latestDryRunResult['issue_count'] ?? 0) === 0;
$settings = ExecutionSettings::all(db());
$actualMovesEnabled = ExecutionSettings::actualMovesEnabled($settings);
$canExecute = (string)$run['status'] === 'draft' && $pending > 0 && $failed === 0 && $cancelled === 0 && $dryRunOk;
$phrase = (string)($settings['execution_confirmation_phrase'] ?? 'I understand this will move files');
?>

<?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong></div><?php endif; ?>
<?php if ($error): ?><div class="card"><strong>Final safety check failed:</strong> <?= e($error) ?></div><?php endif; ?>
<?php if ($executionError !== ''): ?><div class="card"><strong>Execution not queued:</strong> <?= e($executionError) ?></div><?php endif; ?>

<div class="card">
    <h2>Move execution preparation #<?= e((string)$run['id']) ?></h2>
    <p class="muted">Simulation marks database audit rows only. Actual move mode is available only when enabled in Execution Settings and confirmed here.</p>
    <div class="status-bar">Run status: <?= e($run['status']) ?> | Plan: #<?= e((string)$run['move_plan_id']) ?> | Plan status: <?= e($run['plan_status']) ?> | Items: <?= e(number_format($total)) ?> | Created: <?= e($run['created_at']) ?></div>
    <div class="status-bar">Dry-run: <?= $dryRunOk ? 'Complete with zero issues' : 'Not clean/complete' ?> | Pending: <?= e(number_format($pending)) ?> | Failed: <?= e(number_format($failed)) ?> | Simulated: <?= e(number_format($simulated)) ?> | Actual moves: <?= $actualMovesEnabled ? 'Enabled' : 'Disabled' ?></div>
    <?php if ($run['error_message']): ?><p><strong>Error:</strong> <?= e($run['error_message']) ?></p><?php endif; ?>
    <div class="button-row">
        <a class="button" href="move-executions.php">Execution Runs</a>
        <a class="button" href="move-plan-view.php?id=<?= e((string)$run['move_plan_id']) ?>">Plan View</a>
        <a class="button" href="move-plan-dry-runs.php?id=<?= e((string)$run['move_plan_id']) ?>">Dry-run Reports</a>
        <a class="button" href="move-execution-settings.php">Execution Settings</a>
        <?php if (in_array((string)$run['status'], ['draft', 'failed'], true)): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Run final safety check? This updates execution item statuses only and does not move files.');"><input type="hidden" name="action" value="final_safety_check"><input type="hidden" name="id" value="<?= e((string)$run['id']) ?>"><button type="submit">Final safety check</button></form>
            <?php if ($canExecute): ?><form method="post" action="move-execution-queue.php" style="display:inline" onsubmit="return confirm('Simulate this execution run? This marks audit rows only and does not move files.');"><input type="hidden" name="id" value="<?= e((string)$run['id']) ?>"><input type="hidden" name="mode" value="simulate"><button type="submit">Simulate</button></form><?php endif; ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Cancel this execution run? No files will be moved.');"><input type="hidden" name="action" value="cancel_run"><input type="hidden" name="id" value="<?= e((string)$run['id']) ?>"><button type="submit">Cancel run</button></form>
        <?php endif; ?>
        <?php if (in_array((string)$run['status'], ['failed', 'simulated', 'cancelled'], true)): ?><form method="post" style="display:inline" onsubmit="return confirm('Reset this run to draft/pending?');"><input type="hidden" name="action" value="reset_to_draft"><input type="hidden" name="id" value="<?= e((string)$run['id']) ?>"><button type="submit">Reset to draft</button></form><?php endif; ?>
        <?php if (in_array((string)$run['status'], ['draft', 'failed', 'cancelled', 'simulated'], true)): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete this execution audit record?');"><input type="hidden" name="action" value="delete_run"><input type="hidden" name="id" value="<?= e((string)$run['id']) ?>"><button class="danger" type="submit">Delete run</button></form><?php endif; ?>
    </div>
</div>

<?php if ($actualMovesEnabled && $canExecute): ?>
<div class="card">
    <h2>Actual move execution</h2>
    <p><strong>Warning:</strong> this will copy each source file to a temporary target path, verify the target size, rename it to the final target path, verify again, then remove the source. Failed items are marked failed and source removal is not attempted unless the target has verified.</p>
    <form method="post" action="move-execution-queue.php" onsubmit="return confirm('Actually move these files now?');">
        <input type="hidden" name="id" value="<?= e((string)$run['id']) ?>">
        <input type="hidden" name="mode" value="actual">
        <label>Type confirmation phrase: <input type="text" name="confirmation_phrase" style="width:100%;max-width:520px" placeholder="<?= e($phrase) ?>"></label>
        <p><button class="danger" type="submit">Run actual move execution</button></p>
    </form>
</div>
<?php endif; ?>

<div class="card"><h2>Execution item status summary</h2><div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Status</th><th>Items</th><th>Total size</th></tr></thead><tbody><?php foreach ($statusSummary as $row): ?><tr><td><?= e($row['status']) ?></td><td><?= e(number_format((int)$row['item_count'])) ?></td><td><?= e(format_bytes((int)$row['total_size'])) ?></td></tr><?php endforeach; ?><?php if (!$statusSummary): ?><tr><td colspan="3">No execution items found.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><h2>Prepared execution items</h2><p class="muted">Showing <?= e(number_format($total === 0 ? 0 : $offset + 1)) ?>-<?= e(number_format(min($offset + count($items), $total))) ?> of <?= e(number_format($total)) ?> prepared items.</p><div class="table-scroll"><table class="auto-table compact-table selectable-table"><thead><tr><?php foreach (['status' => 'Status', 'source' => 'Source path', 'target' => 'Target path', 'size' => 'Size'] as $key => $label): ?><th><a href="<?= e(mev_link(['sort' => $key, 'dir' => $sort === $key ? $nextDir : 'asc', 'page' => 1])) ?>"><?= e($label) ?><?= $sort === $key ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th><?php endforeach; ?><th>Temp target</th><th>Error</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr class="selectable-row"><td><?= e($item['status']) ?></td><td class="wrap-cell path-cell"><?= e($item['source_path']) ?></td><td class="wrap-cell path-cell"><?= e($item['target_path']) ?></td><td><?= e(format_bytes((int)$item['size_bytes'])) ?></td><td class="wrap-cell path-cell"><?= e($item['temp_target_path'] ?? '') ?></td><td class="wrap-cell"><?= e($item['error_message'] ?? '') ?></td></tr><?php endforeach; ?><?php if (!$items): ?><tr><td colspan="6">No execution items found.</td></tr><?php endif; ?></tbody></table></div><?php if ($total > MOVE_EXECUTION_VIEW_PAGE_SIZE): ?><div class="status-bar">Page <?= e(number_format($page)) ?> of <?= e(number_format($totalPages)) ?>. <a class="button" href="<?= e(mev_link(['page' => max(1, $page - 1)])) ?>">Previous</a> <a class="button" href="<?= e(mev_link(['page' => min($totalPages, $page + 1)])) ?>">Next</a></div><?php endif; ?></div>
<?php render_footer(); ?>
