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
    private const BATCH_SIZE = 1000;
    private const CANCEL_CHECK_INTERVAL = 250;
    private const PROGRESS_INTERVAL_SECONDS = 1.0;

    private ?PDOStatement $entryUpsertStmt = null;

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
        $stats = ['files' => 0, 'folders' => 0, 'bytes' => 0];
        $this->entryUpsertStmt = $this->prepareEntryUpsertStatement();

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

            $fullPath = $item->getPathname();
            $relativePath = ltrim(substr($fullPath, strlen($rootPath)), DIRECTORY_SEPARATOR);
            $isDir = $item->isDir();
            $size = $isDir ? 0 : (int)$item->getSize();

            if ($isDir) {
                $stats['folders']++;
            } else {
                $stats['files']++;
                $stats['bytes'] += $size;
            }

            $this->upsertEntry($driveId, $scanRunId, $fullPath, $relativePath, $item->getFilename(), $item->getPath(), $isDir, $size, $item->getMTime());
            $pendingRows++;

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

    private function upsertEntry(int $driveId, int $scanRunId, string $fullPath, string $relativePath, string $name, string $parentPath, bool $isDir, int $size, int $modifiedTime): void
    {
        $extension = $this->safeExtension($isDir, $name);
        $entryType = $isDir ? 'folder' : 'file';
        $modifiedAt = date('Y-m-d H:i:s', $modifiedTime);
        $stmt = $this->entryUpsertStmt ?? $this->prepareEntryUpsertStatement();
        $stmt->execute([$driveId, $scanRunId, $parentPath, $fullPath, $relativePath, $name, $extension, $entryType, $size, $modifiedAt]);
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
        $stmt = $this->db->prepare('UPDATE scan_runs SET status = ?, files_seen = ?, folders_seen = ?, bytes_seen = ?, finished_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $stats['files'], $stats['folders'], $stats['bytes'], $scanRunId]);
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
