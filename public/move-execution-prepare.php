<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: move-plans.php');
    exit;
}

$planId = max(0, (int)($_POST['id'] ?? 0));
if ($planId <= 0) {
    header('Location: move-plans.php?error=' . rawurlencode('Missing plan ID.'));
    exit;
}

$stmt = db()->prepare('SELECT id, status FROM move_plans WHERE id = ?');
$stmt->execute([$planId]);
$plan = $stmt->fetch();
if (!$plan) {
    header('Location: move-plans.php?error=' . rawurlencode('Saved plan not found.'));
    exit;
}

if ((string)$plan['status'] !== 'validated') {
    header('Location: move-plan-view.php?id=' . $planId . '&execution_error=' . rawurlencode('Only validated plans can be prepared for execution.'));
    exit;
}

$validatedStmt = db()->prepare("SELECT COUNT(*) FROM move_plan_items WHERE move_plan_id = ? AND status = 'validated'");
$validatedStmt->execute([$planId]);
$validatedItems = (int)$validatedStmt->fetchColumn();
if ($validatedItems <= 0) {
    header('Location: move-plan-view.php?id=' . $planId . '&execution_error=' . rawurlencode('No validated candidate items found for this plan.'));
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $runStmt = $db->prepare("INSERT INTO move_execution_runs (move_plan_id, status, dry_run_required) VALUES (?, 'draft', 1)");
    $runStmt->execute([$planId]);
    $runId = (int)$db->lastInsertId();

    $itemSql = "
        SELECT mpi.id AS move_plan_item_id, mpi.size_bytes, f.full_path AS source_path, td.mount_path AS target_mount_path, mpi.relative_path
        FROM move_plan_items mpi
        JOIN file_entries f ON f.id = mpi.file_entry_id
        JOIN drives td ON td.id = mpi.target_drive_id
        WHERE mpi.move_plan_id = ? AND mpi.status = 'validated'
        ORDER BY mpi.id
    ";
    $itemStmt = $db->prepare($itemSql);
    $itemStmt->execute([$planId]);

    $insertItem = $db->prepare('INSERT INTO move_execution_items (execution_run_id, move_plan_item_id, source_path, target_path, temp_target_path, size_bytes) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($itemStmt->fetchAll() as $item) {
        $targetMount = rtrim((string)$item['target_mount_path'], "/\\");
        $relativePath = ltrim(str_replace('\\', '/', (string)$item['relative_path']), '/');
        $targetPath = $targetMount . '/' . $relativePath;
        $tempTargetPath = $targetPath . '.drivemaster-tmp-' . $runId;
        $insertItem->execute([$runId, (int)$item['move_plan_item_id'], (string)$item['source_path'], $targetPath, $tempTargetPath, (int)$item['size_bytes']]);
    }

    $db->commit();
    header('Location: move-execution-view.php?id=' . $runId);
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    header('Location: move-plan-view.php?id=' . $planId . '&execution_error=' . rawurlencode($e->getMessage()));
    exit;
}
