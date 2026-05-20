<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Repository\JobRepository;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: move-executions.php');
    exit;
}

$runId = max(0, (int)($_POST['id'] ?? 0));
if ($runId <= 0) {
    header('Location: move-executions.php?error=' . rawurlencode('Missing execution run ID.'));
    exit;
}

$config = app_config();
if (empty($config['app']['enable_real_file_moves'])) {
    header('Location: move-execution-view.php?id=' . $runId . '&execution_error=' . rawurlencode('Real file moves are disabled in config. Set app.enable_real_file_moves=true only after dry-runs and safety checks are clean.'));
    exit;
}

$stmt = db()->prepare('SELECT mer.id, mer.status, mer.move_plan_id, mp.status AS plan_status FROM move_execution_runs mer JOIN move_plans mp ON mp.id = mer.move_plan_id WHERE mer.id = ?');
$stmt->execute([$runId]);
$run = $stmt->fetch();
if (!$run) {
    header('Location: move-executions.php?error=' . rawurlencode('Execution run not found.'));
    exit;
}

if ((string)$run['status'] !== 'draft' || (string)$run['plan_status'] !== 'validated') {
    header('Location: move-execution-view.php?id=' . $runId . '&execution_error=' . rawurlencode('Only draft execution runs for validated plans can be queued.'));
    exit;
}

$pendingStmt = db()->prepare("SELECT COUNT(*) FROM move_execution_items WHERE execution_run_id = ? AND status = 'pending'");
$pendingStmt->execute([$runId]);
$pendingItems = (int)$pendingStmt->fetchColumn();
if ($pendingItems <= 0) {
    header('Location: move-execution-view.php?id=' . $runId . '&execution_error=' . rawurlencode('No pending execution items found. Run final safety check first.'));
    exit;
}

$existingStmt = db()->prepare("SELECT id FROM jobs WHERE job_type = 'move_execution' AND status IN ('queued', 'running') AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.execution_run_id')) AS UNSIGNED) = ? ORDER BY id DESC LIMIT 1");
$existingStmt->execute([$runId]);
$existingJobId = (int)($existingStmt->fetchColumn() ?: 0);
if ($existingJobId > 0) {
    header('Location: jobs.php?created=' . $existingJobId);
    exit;
}

$jobs = new JobRepository(db());
$jobId = $jobs->create('move_execution', [
    'execution_run_id' => $runId,
    'pending_items' => $pendingItems,
]);

header('Location: jobs.php?created=' . $jobId);
exit;
