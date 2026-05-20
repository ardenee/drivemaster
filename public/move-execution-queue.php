<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Support\ExecutionSettings;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: move-executions.php');
    exit;
}

$runId = max(0, (int)($_POST['id'] ?? 0));
$mode = (string)($_POST['mode'] ?? 'simulate');
if ($runId <= 0) {
    header('Location: move-executions.php?error=' . rawurlencode('Missing execution run ID.'));
    exit;
}
if ($mode !== 'simulate') {
    header('Location: move-execution-view.php?id=' . $runId . '&execution_error=' . rawurlencode('Only simulation mode is currently available.'));
    exit;
}

$settings = ExecutionSettings::all(db());
if (ExecutionSettings::actualMovesEnabled($settings)) {
    header('Location: move-execution-view.php?id=' . $runId . '&execution_error=' . rawurlencode('Actual move mode is not wired here. Disable actual moves and use simulation.'));
    exit;
}

$stmt = db()->prepare('SELECT mer.*, mp.status AS plan_status FROM move_execution_runs mer JOIN move_plans mp ON mp.id = mer.move_plan_id WHERE mer.id = ?');
$stmt->execute([$runId]);
$run = $stmt->fetch();
if (!$run) {
    header('Location: move-executions.php?error=' . rawurlencode('Execution run not found.'));
    exit;
}
if ((string)$run['status'] !== 'draft') {
    header('Location: move-execution-view.php?id=' . $runId . '&execution_error=' . rawurlencode('Only draft execution runs can be simulated.'));
    exit;
}
if ((string)$run['plan_status'] !== 'validated') {
    header('Location: move-execution-view.php?id=' . $runId . '&execution_error=' . rawurlencode('Parent plan must be validated.'));
    exit;
}

if ((string)($settings['execution_require_dry_run'] ?? '1') === '1') {
    $dryRunStmt = db()->prepare("SELECT result_json FROM jobs WHERE job_type = 'move_plan_dry_run' AND status = 'complete' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.move_plan_id')) AS UNSIGNED) = ? ORDER BY id DESC LIMIT 1");
    $dryRunStmt->execute([(int)$run['move_plan_id']]);
    $dryRunJson = (string)($dryRunStmt->fetchColumn() ?: '');
    $dryRun = $dryRunJson !== '' ? json_decode($dryRunJson, true) : [];
    if (!$dryRun) {
        header('Location: move-execution-view.php?id=' . $runId . '&execution_error=' . rawurlencode('A completed dry-run is required before simulation.'));
        exit;
    }
    if ((int)($dryRun['issue_count'] ?? 0) > 0) {
        header('Location: move-execution-view.php?id=' . $runId . '&execution_error=' . rawurlencode('Latest completed dry-run has issues.'));
        exit;
    }
}

$pendingStmt = db()->prepare("SELECT COUNT(*) FROM move_execution_items WHERE execution_run_id = ? AND status = 'pending'");
$pendingStmt->execute([$runId]);
$pending = (int)$pendingStmt->fetchColumn();
if ($pending <= 0) {
    header('Location: move-execution-view.php?id=' . $runId . '&execution_error=' . rawurlencode('No pending execution items found. Run final safety check first.'));
    exit;
}

$badStmt = db()->prepare("SELECT COUNT(*) FROM move_execution_items WHERE execution_run_id = ? AND status IN ('failed','cancelled')");
$badStmt->execute([$runId]);
if ((int)$badStmt->fetchColumn() > 0) {
    header('Location: move-execution-view.php?id=' . $runId . '&execution_error=' . rawurlencode('Execution run has failed or cancelled items.'));
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $db->prepare("UPDATE move_execution_runs SET status = 'simulated', started_at = COALESCE(started_at, NOW()), finished_at = NOW(), updated_at = NOW(), error_message = NULL WHERE id = ?")->execute([$runId]);
    $db->prepare("UPDATE move_execution_items SET status = 'simulated', updated_at = NOW() WHERE execution_run_id = ? AND status = 'pending'")->execute([$runId]);
    $db->commit();
    header('Location: move-execution-view.php?id=' . $runId . '&simulated=' . $pending);
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    header('Location: move-execution-view.php?id=' . $runId . '&execution_error=' . rawurlencode($e->getMessage()));
    exit;
}
