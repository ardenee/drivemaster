<?php

declare(strict_types=1);

namespace DriveMaster\Support;

use PDO;
use RuntimeException;
use Throwable;

final class OperationAudit
{
    public static function ensureTables(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS operation_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            operation_type ENUM('move_execution','undo_move_execution') NOT NULL,
            source_run_id BIGINT UNSIGNED NULL,
            status ENUM('running','complete','failed','partial','dry_run') NOT NULL DEFAULT 'running',
            dry_run TINYINT(1) NOT NULL DEFAULT 0,
            item_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            success_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            failed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            warning_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            summary_json JSON NULL,
            error_message TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME NULL,
            KEY idx_operation_runs_type_source (operation_type, source_run_id),
            KEY idx_operation_runs_status (status),
            KEY idx_operation_runs_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS operation_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            operation_run_id BIGINT UNSIGNED NOT NULL,
            source_operation_item_id BIGINT UNSIGNED NULL,
            move_execution_item_id BIGINT UNSIGNED NULL,
            file_entry_id BIGINT UNSIGNED NULL,
            action_type ENUM('move_file','undo_move_file','verify_only') NOT NULL,
            status ENUM('planned','done','failed','skipped','warning') NOT NULL DEFAULT 'planned',
            source_drive_id INT UNSIGNED NULL,
            target_drive_id INT UNSIGNED NULL,
            before_path VARCHAR(1500) NULL,
            after_path VARCHAR(1500) NULL,
            temp_path VARCHAR(1500) NULL,
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            before_exists TINYINT(1) NULL,
            after_exists TINYINT(1) NULL,
            message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_operation_items_run FOREIGN KEY (operation_run_id) REFERENCES operation_runs(id) ON DELETE CASCADE,
            KEY idx_operation_items_run_status (operation_run_id, status),
            KEY idx_operation_items_execution_item (move_execution_item_id),
            KEY idx_operation_items_file_entry (file_entry_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function createRun(PDO $db, string $type, ?int $sourceRunId, bool $dryRun, ?int $userId = null): int
    {
        self::ensureTables($db);
        $stmt = $db->prepare('INSERT INTO operation_runs (operation_type, source_run_id, status, dry_run, created_by_user_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$type, $sourceRunId, $dryRun ? 'dry_run' : 'running', $dryRun ? 1 : 0, $userId]);
        return (int)$db->lastInsertId();
    }

    public static function finishRun(PDO $db, int $runId, string $status, array $summary = [], ?string $error = null): void
    {
        self::ensureTables($db);
        $counts = $db->prepare("SELECT COUNT(*) AS items, SUM(status = 'done') AS success_count, SUM(status = 'failed') AS failed_count, SUM(status IN ('warning','skipped')) AS warning_count FROM operation_items WHERE operation_run_id = ?");
        $counts->execute([$runId]);
        $row = $counts->fetch() ?: [];
        $stmt = $db->prepare('UPDATE operation_runs SET status = ?, item_count = ?, success_count = ?, failed_count = ?, warning_count = ?, summary_json = ?, error_message = ?, finished_at = NOW() WHERE id = ?');
        $stmt->execute([$status, (int)($row['items'] ?? 0), (int)($row['success_count'] ?? 0), (int)($row['failed_count'] ?? 0), (int)($row['warning_count'] ?? 0), json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $error, $runId]);
    }

    public static function logMoveItem(PDO $db, int $operationRunId, array $executionItem): void
    {
        self::ensureTables($db);
        $stmt = $db->prepare('INSERT INTO operation_items (operation_run_id, move_execution_item_id, file_entry_id, action_type, status, source_drive_id, target_drive_id, before_path, after_path, temp_path, size_bytes, before_exists, after_exists, message) SELECT ?, mei.id, mpi.file_entry_id, ?, ?, mpi.source_drive_id, mpi.target_drive_id, mei.source_path, mei.target_path, mei.temp_target_path, mei.size_bytes, 0, 1, ? FROM move_execution_items mei JOIN move_plan_items mpi ON mpi.id = mei.move_plan_item_id WHERE mei.id = ?');
        $stmt->execute([$operationRunId, 'move_file', 'done', 'Moved by execution run.', (int)$executionItem['id']]);
    }

    public static function updateFileEntryAfterMove(PDO $db, int $executionItemId): void
    {
        $stmt = $db->prepare('SELECT mpi.file_entry_id, mpi.target_drive_id, mei.target_path, mpi.relative_path FROM move_execution_items mei JOIN move_plan_items mpi ON mpi.id = mei.move_plan_item_id WHERE mei.id = ?');
        $stmt->execute([$executionItemId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['file_entry_id'])) {
            return;
        }
        $parent = dirname((string)$row['target_path']);
        $update = $db->prepare('UPDATE file_entries SET drive_id = ?, parent_path = ?, full_path = ?, relative_path = ?, is_missing = 0, last_seen_at = NOW() WHERE id = ?');
        $update->execute([(int)$row['target_drive_id'], $parent, (string)$row['target_path'], (string)$row['relative_path'], (int)$row['file_entry_id']]);
    }

    public static function updateFileEntryAfterUndo(PDO $db, int $operationItemId): void
    {
        $stmt = $db->prepare('SELECT file_entry_id, source_drive_id, before_path FROM operation_items WHERE id = ?');
        $stmt->execute([$operationItemId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['file_entry_id'])) {
            return;
        }
        $parent = dirname((string)$row['before_path']);
        $name = basename((string)$row['before_path']);
        $update = $db->prepare('UPDATE file_entries SET drive_id = ?, parent_path = ?, full_path = ?, name = ?, is_missing = 0, last_seen_at = NOW() WHERE id = ?');
        $update->execute([(int)$row['source_drive_id'], $parent, (string)$row['before_path'], $name, (int)$row['file_entry_id']]);
    }

    public static function dryRunUndo(PDO $db, int $sourceOperationRunId, ?int $userId = null): int
    {
        self::ensureTables($db);
        $runId = self::createRun($db, 'undo_move_execution', $sourceOperationRunId, true, $userId);
        $sourceItems = $db->prepare("SELECT * FROM operation_items WHERE operation_run_id = ? AND action_type = 'move_file' AND status = 'done' ORDER BY id");
        $sourceItems->execute([$sourceOperationRunId]);
        $insert = $db->prepare('INSERT INTO operation_items (operation_run_id, source_operation_item_id, move_execution_item_id, file_entry_id, action_type, status, source_drive_id, target_drive_id, before_path, after_path, temp_path, size_bytes, before_exists, after_exists, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($sourceItems->fetchAll() as $item) {
            $targetPath = (string)$item['after_path'];
            $originalPath = (string)$item['before_path'];
            $status = 'done';
            $messages = [];
            $targetExists = is_file($targetPath);
            $originalParent = dirname($originalPath);
            if (!$targetExists) {
                $status = 'failed';
                $messages[] = 'Moved file is not currently visible at target path.';
            } elseif (filesize($targetPath) !== (int)$item['size_bytes']) {
                $status = 'failed';
                $messages[] = 'Moved file size differs from logged size.';
            }
            if (file_exists($originalPath)) {
                $status = 'failed';
                $messages[] = 'Original source path already exists.';
            }
            if (!is_dir($originalParent)) {
                $status = 'warning';
                $messages[] = 'Original parent folder is not currently visible.';
            } elseif (!is_writable($originalParent)) {
                $status = 'warning';
                $messages[] = 'Original parent folder is not writable.';
            }
            $insert->execute([$runId, (int)$item['id'], $item['move_execution_item_id'], $item['file_entry_id'], 'undo_move_file', $status, $item['target_drive_id'], $item['source_drive_id'], $item['after_path'], $item['before_path'], $item['before_path'] . '.drivemaster-undo-tmp-' . $runId, (int)$item['size_bytes'], $targetExists ? 1 : 0, file_exists($originalPath) ? 1 : 0, implode(' ', $messages)]);
        }
        self::finishRun($db, $runId, 'dry_run');
        return $runId;
    }

    public static function executeUndo(PDO $db, int $dryRunId, ?int $userId = null): int
    {
        self::ensureTables($db);
        $stmt = $db->prepare("SELECT * FROM operation_runs WHERE id = ? AND operation_type = 'undo_move_execution' AND dry_run = 1");
        $stmt->execute([$dryRunId]);
        $dryRun = $stmt->fetch();
        if (!$dryRun) {
            throw new RuntimeException('Undo dry-run not found.');
        }
        $runId = self::createRun($db, 'undo_move_execution', (int)$dryRun['source_run_id'], false, $userId);
        $items = $db->prepare("SELECT * FROM operation_items WHERE operation_run_id = ? AND action_type = 'undo_move_file' ORDER BY id");
        $items->execute([$dryRunId]);
        $insert = $db->prepare('INSERT INTO operation_items (operation_run_id, source_operation_item_id, move_execution_item_id, file_entry_id, action_type, status, source_drive_id, target_drive_id, before_path, after_path, temp_path, size_bytes, before_exists, after_exists, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($items->fetchAll() as $item) {
            $from = (string)$item['before_path'];
            $to = (string)$item['after_path'];
            $temp = (string)$item['temp_path'];
            $status = 'done';
            $message = null;
            try {
                if (!is_file($from)) {
                    throw new RuntimeException('Moved file is missing; skipped.');
                }
                if (filesize($from) !== (int)$item['size_bytes']) {
                    throw new RuntimeException('Moved file size differs; skipped.');
                }
                if (file_exists($to)) {
                    throw new RuntimeException('Original path already exists; skipped.');
                }
                if (!is_dir(dirname($to)) || !is_writable(dirname($to))) {
                    throw new RuntimeException('Original parent folder unavailable or not writable; skipped.');
                }
                if (!copy($from, $temp)) {
                    throw new RuntimeException('Copy to undo temp path failed.');
                }
                clearstatcache(true, $temp);
                if (!is_file($temp) || filesize($temp) !== (int)$item['size_bytes']) {
                    @unlink($temp);
                    throw new RuntimeException('Undo temp verification failed.');
                }
                if (!rename($temp, $to)) {
                    @unlink($temp);
                    throw new RuntimeException('Undo temp rename failed.');
                }
                clearstatcache(true, $to);
                if (!is_file($to) || filesize($to) !== (int)$item['size_bytes']) {
                    throw new RuntimeException('Undo target verification failed.');
                }
                if (!unlink($from)) {
                    throw new RuntimeException('Undo copied back but could not remove moved copy. Manual cleanup required.');
                }
            } catch (Throwable $e) {
                $status = 'failed';
                $message = $e->getMessage();
            }
            $insert->execute([$runId, $item['source_operation_item_id'], $item['move_execution_item_id'], $item['file_entry_id'], 'undo_move_file', $status, $item['source_drive_id'], $item['target_drive_id'], $item['before_path'], $item['after_path'], $temp, (int)$item['size_bytes'], is_file($from) ? 1 : 0, is_file($to) ? 1 : 0, $message]);
            if ($status === 'done') {
                self::updateFileEntryAfterUndo($db, (int)$db->lastInsertId());
            }
        }
        $failed = (int)$db->query("SELECT COUNT(*) FROM operation_items WHERE operation_run_id = {$runId} AND status = 'failed'")->fetchColumn();
        self::finishRun($db, $runId, $failed > 0 ? 'partial' : 'complete');
        return $runId;
    }
}
