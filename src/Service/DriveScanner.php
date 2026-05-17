<?php

declare(strict_types=1);

namespace DriveMaster\Service;

use DriveMaster\Repository\DriveRepository;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class DriveScanner
{
    public function __construct(private PDO $db, private DriveRepository $drives)
    {
    }

    public function scan(string $rootPath, string $friendlyName, ?string $serialNumber = null): int
    {
        $rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);

        if (!is_dir($rootPath)) {
            throw new \RuntimeException('Scan path is not a readable directory: ' . $rootPath);
        }

        $totalBytes = @disk_total_space($rootPath) ?: 0;
        $freeBytes = @disk_free_space($rootPath) ?: 0;
        $usedBytes = max(0, $totalBytes - $freeBytes);
        $serialNumber = $serialNumber ?: $this->fallbackSerial($rootPath, $totalBytes);

        $driveId = $this->drives->upsert([
            'friendly_name' => $friendlyName,
            'serial_number' => $serialNumber,
            'mount_path' => $rootPath,
            'total_bytes' => (int)$totalBytes,
            'free_bytes' => (int)$freeBytes,
            'used_bytes' => (int)$usedBytes,
            'is_active' => 1,
        ]);

        $scanRunId = $this->startScanRun($driveId, $rootPath);
        $stats = ['files' => 0, 'folders' => 0, 'bytes' => 0];

        try {
            $this->markDriveEntriesMissing($driveId);
            $this->scanDirectory($driveId, $scanRunId, $rootPath, $stats);
            $this->finishScanRun($scanRunId, 'complete', $stats);
            $this->drives->markScanned($driveId);
        } catch (Throwable $e) {
            $this->failScanRun($scanRunId, $e->getMessage(), $stats);
            throw $e;
        }

        return $scanRunId;
    }

    private function scanDirectory(int $driveId, int $scanRunId, string $rootPath, array &$stats): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
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
        }
    }

    private function upsertEntry(int $driveId, int $scanRunId, string $fullPath, string $relativePath, string $name, string $parentPath, bool $isDir, int $size, int $modifiedTime): void
    {
        $extension = $isDir ? null : strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $entryType = $isDir ? 'folder' : 'file';
        $modifiedAt = date('Y-m-d H:i:s', $modifiedTime);

        $sql = 'INSERT INTO file_entries (drive_id, scan_run_id, parent_path, full_path, relative_path, name, extension, entry_type, size_bytes, modified_at, is_missing, last_seen_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                ON DUPLICATE KEY UPDATE scan_run_id = VALUES(scan_run_id), parent_path = VALUES(parent_path), full_path = VALUES(full_path), name = VALUES(name), extension = VALUES(extension), entry_type = VALUES(entry_type), size_bytes = VALUES(size_bytes), modified_at = VALUES(modified_at), is_missing = 0, last_seen_at = NOW()';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$driveId, $scanRunId, $parentPath, $fullPath, $relativePath, $name, $extension ?: null, $entryType, $size, $modifiedAt]);
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
