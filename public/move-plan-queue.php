<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Repository\JobRepository;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: move-plans.php');
    exit;
}

$planId = max(0, (int)($_POST['id'] ?? 0));
if ($planId <= 0) {
    header('Location: move-plans.php?error=' . rawurlencode('Missing plan ID.'));
    exit;
}

$stmt = db()->prepare('SELECT id, status, candidate_count, total_size_bytes FROM move_plans WHERE id = ?');
$stmt->execute([$planId]);
$plan = $stmt->fetch();
if (!$plan) {
    header('Location: move-plans.php?error=' . rawurlencode('Saved plan not found.'));
    exit;
}

if ((string)$plan['status'] !== 'validated') {
    header('Location: move-plan-view.php?id=' . $planId . '&queue_error=' . rawurlencode('Only validated plans can be queued for dry-run.'));
    exit;
}

$countStmt = db()->prepare("SELECT COUNT(*) FROM move_plan_items WHERE move_plan_id = ? AND status = 'validated'");
$countStmt->execute([$planId]);
$validatedItems = (int)$countStmt->fetchColumn();
if ($validatedItems <= 0) {
    header('Location: move-plan-view.php?id=' . $planId . '&queue_error=' . rawurlencode('No validated candidate items found for this plan.'));
    exit;
}

$jobs = new JobRepository(db());
$jobId = $jobs->create('move_plan_dry_run', [
    'move_plan_id' => $planId,
    'validated_items' => $validatedItems,
]);

header('Location: jobs.php?created=' . $jobId);
exit;
