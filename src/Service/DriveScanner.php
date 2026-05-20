<?php

declare(strict_types=1);

namespace DriveMaster\Service;

use DriveMaster\Repository\DriveRepository;
use PDO;
use PDOStatement;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class DriveScanner
{
    private const MAX_EXTENSION_LENGTH = 50;
    private const MAX_NAME_LENGTH = 500;
    private const MAX_PARENT_PATH_LENGTH = 1000;
    private const MAX_FULL_PATH_LENGTH = 1500;
    private const MAX_RELATIVE_PATH_LENGTH = 1500;
    private const BATCH_SIZE = 1000;
    private const CANCEL_CHECK_INTERVAL = 250;
    private const PROGRESS_INTERVAL_SECONDS = 1.0;

    private ?PDOStatement $entryUpsertStmt = null;
    private ?PDOStatement $entryErrorStmt = null;

    public function __construct(private PDO $db, private DriveRepository $drives)
    {
    }

    public function scan(string $rootPath, string $friendlyName, ?string $serialNumber = null, ?callable $progressCallback = null, ?callable $cancelCallback = null): int
    {
        $rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);

        if (!is_dir($rootPath)) {
            throw new \RuntimeException('Scan path is not a readable directory: ' . $rootPath);
        }

        $discovery = new DriveDiscovery();
        $driveInfo = $discovery->describePath($rootPath);
        $totalBytes = $driveInfo['total_bytes'];
        $freeBytes = $driveInfo['free_bytes'];
        $usedBytes = $driveInfo['used_bytes'];
        $serialNumber = $serialNumber ?: $driveInfo['serial_number'] ?: $this->fallbackSerial($rootPath, $totalBytes);

        $driveId = $this->drives->upsert([
            'friendly_name' => $friendlyName,
            'serial_number' => $serialNumber,
            'hardware_serial' => $driveInfo['hardware_serial'] ?? null,
            'filesystem_uuid' => $driveInfo['filesystem_uuid'] ?? null,
            'volume_label' => $driveInfo['label'] ?? null,
            'model' => $driveInfo['model'] ?? null,
            'vendor' => $driveInfo['vendor'] ?? null,
            'filesystem' => $driveInfo['filesystem'] ?? null,
            'mount_path' => $rootPath,
            'device_name' => $driveInfo['device_name'] ?? null,
            'identity_source' => $driveInfo['identity_source'] ?? null,
            'total_bytes' => (int)$totalBytes,
            'free_bytes' => (int)$freeBytes,
            'used_bytes' => (int)$usedBytes,
            'is_active' => 1,
        ]);

        $scanRunId = $this->startScanRun($driveId, $rootPath);
        $stats = ['files' => 0, 'folders' => 0, 'bytes' => 0, 'errors' => 0];
        $this->entryUpsertStmt = $this->prepareEntryUpsertStatement();
        $this->entryErrorStmt = $this->prepareEntryErrorStatement();

        try {
            $this->throwIfCancelled($cancelCallback);
            $progressCallback && $progressCallback($stats, 'Marking previous inventory as stale');
            $this->markDriveEntriesMissing($driveId);
            $this->scanDirectory($driveId, $scanRunId, $rootPath, $stats, $progressCallback, $cancelCallback);
            $this->throwIfCancelled($cancelCallback);
            $this->finishScanRun($scanRunId, 'complete', $stats);
            $this->drives->markScanned($driveId);
            $progressCallback && $progressCallback($stats, 'Scan complete');
        } catch (Throwable $e) {
            $this->rollbackOpenTransaction();
            $this->failScanRun($scanRunId, $e->getMessage(), $stats);
            throw $e;
        } finally {
            $this->entryUpsertStmt = null;
            $this->entryErrorStmt = null;
        }

        return $scanRunId;
    }

    private function scanDirectory(int $driveId, int $scanRunId, string $rootPath, array &$stats, ?callable $progressCallback, ?callable $cancelCallback): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $pendingRows = 0;
        $lastProgressAt = microtime(true);
        $this->beginBatch();

        foreach ($iterator as $item) {
            $seenBefore = $stats['files'] + $stats['folders'];
            if ($seenBefore % self::CANCEL_CHECK_INTERVAL === 0) {
                $this->throwIfCancelled($cancelCallback);
            }

            $fullPath = $this->safeString(fn(): string => $item->getPathname(), '', $driveId, $scanRunId, 'get_pathname', $stats);
            if ($fullPath === '') {
                continue;
            }

            $relativePath = ltrim(substr($fullPath, strlen($rootPath)), DIRECTORY_SEPARATOR);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            $relativePath = $this->truncateString($relativePath, self::MAX_RELATIVE_PATH_LENGTH, $driveId, $scanRunId, $fullPath, 'relative_path_too_long', $stats);
            $name = $this->safeString(fn(): string => $item->getFilename(), basename($fullPath), $driveId, $scanRunId, 'get_filename', $stats);
            $name = $this->truncateString($name, self::MAX_NAME_LENGTH, $driveId, $scanRunId, $fullPath, 'name_too_long', $stats);
            $parentPath = $this->relativeParentPath($relativePath);
            $parentPath = $this->truncateString($parentPath, self::MAX_PARENT_PATH_LENGTH, $driveId, $scanRunId, $fullPath, 'parent_path_too_long', $stats);
            $storedFullPath = $this->truncateString($fullPath, self::MAX_FULL_PATH_LENGTH, $driveId, $scanRunId, $fullPath, 'full_path_too_long', $stats);
            $isDir = $this->safeBool(fn(): bool => $item->isDir(), false, $driveId, $scanRunId, $fullPath, 'is_dir', $stats);
            $size = $isDir ? 0 : $this->safeInt(fn(): int => (int)$item->getSize(), 0, $driveId, $scanRunId, $fullPath, 'get_size', $stats);
            $modifiedTime = $this->safeInt(fn(): int => (int)$item->getMTime(), 0, $driveId, $scanRunId, $fullPath, 'get_mtime', $stats);

            if ($isDir) {
                $stats['folders']++;
            } else {
                $stats['files']++;
                $stats['bytes'] += max(0, $size);
            }

            try {
                $this->upsertEntry($driveId, $scanRunId, $storedFullPath, $relativePath, $name, $parentPath, $isDir, max(0, $size), $modifiedTime);
                $pendingRows++;
            } catch (Throwable $e) {
                $this->logEntryError($driveId, $scanRunId, $fullPath, 'upsert_entry', $e->getMessage(), $stats);
            }

            if ($pendingRows >= self::BATCH_SIZE) {
                $this->commitBatch();
                $pendingRows = 0;
                $this->beginBatch();
                $this->throwIfCancelled($cancelCallback);
            }

            if ($progressCallback && (microtime(true) - $lastProgressAt) >= self::PROGRESS_INTERVAL_SECONDS) {
                $progressCallback($stats, 'Scanning: ' . $relativePath);
                $lastProgressAt = microtime(true);
            }
        }

        $this->commitBatch();

        if ($progressCallback) {
            $progressCallback($stats, 'Scan write complete');
        }
    }

    private function relativeParentPath(string $relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || !str_contains($relativePath, '/')) {
            return '';
        }
        return substr($relativePath, 0, strrpos($relativePath, '/')) ?: '';
    }

    private function throwIfCancelled(?callable $cancelCallback): void
    {
        if ($cancelCallback && $cancelCallback()) {
            throw new \RuntimeException('Scan cancelled by user.');
        }
    }

    private function prepareEntryUpsertStatement(): PDOStatement
    {
        $sql = 'INSERT INTO file_entries (drive_id, scan_run_id, parent_path, full_path, relative_path, name, extension, entry_type, size_bytes, modified_at, is_missing, last_seen_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                ON DUPLICATE KEY UPDATE scan_run_id = VALUES(scan_run_id), parent_path = VALUES(parent_path), full_path = VALUES(full_path), name = VALUES(name), extension = VALUES(extension), entry_type = VALUES(entry_type), size_bytes = VALUES(size_bytes), modified_at = VALUES(modified_at), is_missing = 0, last_seen_at = NOW()';
        return $this->db->prepare($sql);
    }

    private function prepareEntryErrorStatement(): PDOStatement
    {
        $sql = 'INSERT INTO scan_entry_errors (drive_id, scan_run_id, path, error_stage, error_message) VALUES (?, ?, ?, ?, ?)';
        return $this->db->prepare($sql);
    }

    private function upsertEntry(int $driveId, int $scanRunId, string $fullPath, string $relativePath, string $name, string $parentPath, bool $isDir, int $size, int $modifiedTime): void
    {
        $extension = $this->safeExtension($isDir, $name);
        $entryType = $isDir ? 'folder' : 'file';
        $modifiedAt = $modifiedTime > 0 ? date('Y-m-d H:i:s', $modifiedTime) : null;
        $stmt = $this->entryUpsertStmt ?? $this->prepareEntryUpsertStatement();
        $stmt->execute([$driveId, $scanRunId, $parentPath, $fullPath, $relativePath, $name, $extension, $entryType, $size, $modifiedAt]);
    }

    private function safeString(callable $callback, string $default, int $driveId, int $scanRunId, string $stage, array &$stats): string
    {
        try {
            return (string)$callback();
        } catch (Throwable $e) {
            $this->logEntryError($driveId, $scanRunId, $default, $stage, $e->getMessage(), $stats);
            return $default;
        }
    }

    private function safeInt(callable $callback, int $default, int $driveId, int $scanRunId, string $path, string $stage, array &$stats): int
    {
        try {
            return (int)$callback();
        } catch (Throwable $e) {
            $this->logEntryError($driveId, $scanRunId, $path, $stage, $e->getMessage(), $stats);
            return $default;
        }
    }

    private function safeBool(callable $callback, bool $default, int $driveId, int $scanRunId, string $path, string $stage, array &$stats): bool
    {
        try {
            return (bool)$callback();
        } catch (Throwable $e) {
            $this->logEntryError($driveId, $scanRunId, $path, $stage, $e->getMessage(), $stats);
            return $default;
        }
    }

    private function truncateString(string $value, int $maxLength, int $driveId, int $scanRunId, string $path, string $stage, array &$stats): string
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length <= $maxLength) {
            return $value;
        }

        $this->logEntryError($driveId, $scanRunId, $path, $stage, 'Value exceeded ' . $maxLength . ' characters and was truncated.', $stats);
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
    }

    private function logEntryError(int $driveId, int $scanRunId, string $path, string $stage, string $message, array &$stats): void
    {
        $stats['errors'] = ((int)($stats['errors'] ?? 0)) + 1;
        $path = function_exists('mb_substr') ? mb_substr($path, 0, self::MAX_FULL_PATH_LENGTH, 'UTF-8') : substr($path, 0, self::MAX_FULL_PATH_LENGTH);
        $stage = function_exists('mb_substr') ? mb_substr($stage, 0, 80, 'UTF-8') : substr($stage, 0, 80);

        try {
            $stmt = $this->entryErrorStmt ?? $this->prepareEntryErrorStatement();
            $stmt->execute([$driveId, $scanRunId, $path, $stage, $message]);
        } catch (Throwable) {
            // Do not let error logging break the scan.
        }
    }

    private function beginBatch(): void
    {
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
        }
    }

    private function commitBatch(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->commit();
        }
    }

    private function rollbackOpenTransaction(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function safeExtension(bool $isDir, string $name): ?string
    {
        if ($isDir) {
            return null;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($extension, 'UTF-8') : strlen($extension);
        if ($length > self::MAX_EXTENSION_LENGTH) {
            return null;
        }

        return $extension;
    }

    private function startScanRun(int $driveId, string $rootPath): int
    {
        $stmt = $this->db->prepare('INSERT INTO scan_runs (drive_id, root_path) VALUES (?, ?)');
        $stmt->execute([$driveId, $rootPath]);
        return (int)$this->db->lastInsertId();
    }

    private function finishScanRun(int $scanRunId, string $status, array $stats): void
    {
        $errorMessage = ((int)($stats['errors'] ?? 0)) > 0 ? 'Completed with ' . (int)$stats['errors'] . ' item warning(s). See scan_entry_errors.' : null;
        $stmt = $this->db->prepare('UPDATE scan_runs SET status = ?, files_seen = ?, folders_seen = ?, bytes_seen = ?, error_message = ?, finished_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $stats['files'], $stats['folders'], $stats['bytes'], $errorMessage, $scanRunId]);
    }

    private function failScanRun(int $scanRunId, string $message, array $stats): void
    {
        $stmt = $this->db->prepare('UPDATE scan_runs SET status = "failed", files_seen = ?, folders_seen = ?, bytes_seen = ?, error_message = ?, finished_at = NOW() WHERE id = ?');
        $stmt->execute([$stats['files'], $stats['folders'], $stats['bytes'], $message, $scanRunId]);
    }

    private function markDriveEntriesMissing(int $driveId): void
    {
        $stmt = $this->db->prepare('UPDATE file_entries SET is_missing = 1 WHERE drive_id = ?');
        $stmt->execute([$driveId]);
    }

    private function fallbackSerial(string $rootPath, int|float $totalBytes): string
    {
        return 'manual-' . sha1(strtolower($rootPath) . '|' . (string)$totalBytes);
    }
}
