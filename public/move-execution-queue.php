<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Support\ExecutionSettings;

function meq_fail(int $runId, string $message): void
{
    header('Location: move-execution-view.php?id=' . $runId . '&execution_error=' . rawurlencode($message));
    exit;
}

function meq_json(?string $json): array
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

function meq_require_clean_run(array $run, array $settings): void
{
    if ((string)$run['status'] !== 'draft') {
        meq_fail((int)$run['id'], 'Only draft execution runs can be executed or simulated.');
    }
    if ((string)$run['plan_status'] !== 'validated') {
        meq_fail((int)$run['id'], 'Parent plan must be validated.');
    }

    if ((string)($settings['execution_require_dry_run'] ?? '1') === '1') {
        $dryRunStmt = db()->prepare("SELECT result_json FROM jobs WHERE job_type = 'move_plan_dry_run' AND status = 'complete' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.move_plan_id')) AS UNSIGNED) = ? ORDER BY id DESC LIMIT 1");
        $dryRunStmt->execute([(int)$run['move_plan_id']]);
        $dryRun = meq_json((string)($dryRunStmt->fetchColumn() ?: ''));
        if (!$dryRun) {
            meq_fail((int)$run['id'], 'A completed dry-run is required.');
        }
        if ((int)($dryRun['issue_count'] ?? 0) > 0) {
            meq_fail((int)$run['id'], 'Latest completed dry-run has issues.');
        }
    }

    $pendingStmt = db()->prepare("SELECT COUNT(*) FROM move_execution_items WHERE execution_run_id = ? AND status = 'pending'");
    $pendingStmt->execute([(int)$run['id']]);
    if ((int)$pendingStmt->fetchColumn() <= 0) {
        meq_fail((int)$run['id'], 'No pending execution items found. Run final safety check first.');
    }

    $badStmt = db()->prepare("SELECT COUNT(*) FROM move_execution_items WHERE execution_run_id = ? AND status IN ('failed','cancelled')");
    $badStmt->execute([(int)$run['id']]);
    if ((int)$badStmt->fetchColumn() > 0) {
        meq_fail((int)$run['id'], 'Execution run has failed or cancelled items.');
    }
}

function meq_simulate(int $runId): void
{
    $db = db();
    $pendingStmt = $db->prepare("SELECT COUNT(*) FROM move_execution_items WHERE execution_run_id = ? AND status = 'pending'");
    $pendingStmt->execute([$runId]);
    $pending = (int)$pendingStmt->fetchColumn();

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
        meq_fail($runId, $e->getMessage());
    }
}

function meq_execute_actual(int $runId): void
{
    $db = db();
    $itemStmt = $db->prepare("SELECT * FROM move_execution_items WHERE execution_run_id = ? AND status = 'pending' ORDER BY id");
    $itemStmt->execute([$runId]);
    $items = $itemStmt->fetchAll();
    if (!$items) {
        meq_fail($runId, 'No pending execution items found.');
    }

    $moved = 0;
    $failed = 0;
    $updateItem = $db->prepare('UPDATE move_execution_items SET status = ?, error_message = ?, updated_at = NOW() WHERE id = ?');
    $db->prepare("UPDATE move_execution_runs SET status = 'running', started_at = COALESCE(started_at, NOW()), updated_at = NOW(), error_message = NULL WHERE id = ?")->execute([$runId]);

    foreach ($items as $item) {
        $source = (string)$item['source_path'];
        $target = (string)$item['target_path'];
        $temp = (string)$item['temp_target_path'];
        $size = (int)$item['size_bytes'];
        $reason = null;

        if ($source === '' || !is_file($source)) {
            $reason = 'Source file is not visible.';
        } elseif (filesize($source) !== $size) {
            $reason = 'Source file size changed.';
        } elseif ($target === '' || file_exists($target)) {
            $reason = 'Target path is empty or already exists.';
        } elseif ($temp === '' || file_exists($temp)) {
            $reason = 'Temporary target path is empty or already exists.';
        } elseif (!is_dir(dirname($target)) || !is_writable(dirname($target))) {
            $reason = 'Target folder is missing or not writable.';
        }

        if ($reason === null) {
            try {
                if (!copy($source, $temp)) {
                    throw new RuntimeException('Copy to temporary target failed.');
                }
                clearstatcache(true, $temp);
                if (!is_file($temp) || filesize($temp) !== $size) {
                    @unlink($temp);
                    throw new RuntimeException('Temporary target verification failed.');
                }
                if (!rename($temp, $target)) {
                    @unlink($temp);
                    throw new RuntimeException('Rename from temporary target to final target failed.');
                }
                clearstatcache(true, $target);
                if (!is_file($target) || filesize($target) !== $size) {
                    throw new RuntimeException('Final target verification failed. Source was not removed.');
                }
                if (!unlink($source)) {
                    throw new RuntimeException('Target verified, but source removal failed. Manual cleanup required.');
                }
                $updateItem->execute(['moved', null, (int)$item['id']]);
                $moved++;
            } catch (Throwable $e) {
                $reason = $e->getMessage();
            }
        }

        if ($reason !== null) {
            $updateItem->execute(['failed', $reason, (int)$item['id']]);
            $failed++;
        }
    }

    $status = $failed > 0 ? 'failed' : 'complete';
    $error = $failed > 0 ? 'Execution completed with ' . $failed . ' failed item(s).' : null;
    $db->prepare('UPDATE move_execution_runs SET status = ?, error_message = ?, finished_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([$status, $error, $runId]);
    header('Location: move-execution-view.php?id=' . $runId . '&moved=' . $moved . '&move_failed=' . $failed);
    exit;
}

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

$settings = ExecutionSettings::all(db());
$stmt = db()->prepare('SELECT mer.*, mp.status AS plan_status FROM move_execution_runs mer JOIN move_plans mp ON mp.id = mer.move_plan_id WHERE mer.id = ?');
$stmt->execute([$runId]);
$run = $stmt->fetch();
if (!$run) {
    header('Location: move-executions.php?error=' . rawurlencode('Execution run not found.'));
    exit;
}

meq_require_clean_run($run, $settings);

if ($mode === 'simulate') {
    meq_simulate($runId);
}

if ($mode === 'actual') {
    if (!ExecutionSettings::actualMovesEnabled($settings)) {
        meq_fail($runId, 'Actual moves are disabled in Execution Settings.');
    }
    if ((string)($settings['execution_require_confirmation_phrase'] ?? '1') === '1') {
        $phrase = (string)($settings['execution_confirmation_phrase'] ?? 'I understand this will move files');
        if (trim((string)($_POST['confirmation_phrase'] ?? '')) !== $phrase) {
            meq_fail($runId, 'Confirmation phrase did not match.');
        }
    }
    meq_execute_actual($runId);
}

meq_fail($runId, 'Unknown execution mode.');
