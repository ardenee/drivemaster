<?php

declare(strict_types=1);

namespace DriveMaster\Service;

use DriveMaster\Repository\DriveRepository;
use DriveMaster\Repository\JobRepository;
use Throwable;

final class JobRunner
{
    public function __construct(
        private JobRepository $jobs,
        private DriveRepository $drives,
        private DriveDiscovery $discovery,
        private \PDO $db
    ) {
    }

    public function runNext(string $workerId): bool
    {
        $this->jobs->requeueStaleRunning();

        $job = $this->jobs->claimNext($workerId);
        if (!$job) {
            return false;
        }

        try {
            $payload = json_decode($job['payload_json'], true, 512, JSON_THROW_ON_ERROR);

            match ($job['job_type']) {
                'scan_drive' => $this->runScanDrive((int)$job['id'], $payload),
                'refresh_drive_info' => $this->runRefreshDriveInfo((int)$job['id'], $payload),
                'move_plan_dry_run' => $this->runMovePlanDryRun((int)$job['id'], $payload),
                default => throw new \RuntimeException('Unknown job type: ' . $job['job_type']),
            };
        } catch (Throwable $e) {
            if ($this->jobs->isCancelRequested((int)$job['id'])) {
                $this->jobs->markCancelled((int)$job['id']);
            } else {
                $this->jobs->fail((int)$job['id'], $e->getMessage());
            }
        }

        return true;
    }

    private function runScanDrive(int $jobId, array $payload): void
    {
        $path = trim((string)($payload['path'] ?? ''));
        $friendlyName = trim((string)($payload['friendly_name'] ?? ''));
        $serialNumber = trim((string)($payload['serial_number'] ?? '')) ?: null;

        if ($path === '') {
            throw new \RuntimeException('Scan job is missing path.');
        }
        if ($friendlyName === '') {
            throw new \RuntimeException('Scan job is missing friendly name.');
        }

        $this->throwIfCancelled($jobId);
        $details = $this->discovery->describePath($path);
        $usedBytes = max(1, (int)($details['used_bytes'] ?? 0));
        $this->jobs->progress($jobId, 0, $usedBytes, 'Preparing scan: ' . $path);

        if ($serialNumber === null) {
            $serialNumber = $details['serial_number'];
        }

        $scanner = new DriveScanner($this->db, $this->drives);
        $lastUpdateAt = 0.0;
        $scanRunId = $scanner->scan(
            $path,
            $friendlyName,
            $serialNumber,
            function (array $stats, string $message) use ($jobId, $usedBytes, &$lastUpdateAt): void {
                $this->throwIfCancelled($jobId);
                $now = microtime(true);
                if (($now - $lastUpdateAt) < 0.75 && (($stats['files'] + $stats['folders']) % 500) !== 0) {
                    return;
                }
                $lastUpdateAt = $now;
                $bytes = min((int)$stats['bytes'], $usedBytes);
                $this->jobs->progress($jobId, $bytes, $usedBytes, $message);
            },
            fn(): bool => $this->jobs->isCancelRequested($jobId)
        );

        $this->throwIfCancelled($jobId);
        $this->jobs->complete($jobId, [
            'scan_run_id' => $scanRunId,
            'progress_current' => $usedBytes,
            'progress_total' => $usedBytes,
        ]);
    }

    private function runRefreshDriveInfo(int $jobId, array $payload): void
    {
        $driveId = (int)($payload['drive_id'] ?? 0);
        if ($driveId <= 0) {
            throw new \RuntimeException('Refresh job is missing drive ID.');
        }

        $drive = $this->drives->find($driveId);
        if (!$drive) {
            throw new \RuntimeException('Drive no longer exists.');
        }

        $path = (string)($drive['mount_path'] ?? '');
        if ($path === '' || !is_dir($path)) {
            throw new \RuntimeException('Drive mount path is not currently available: ' . $path);
        }

        $this->throwIfCancelled($jobId);
        $this->jobs->progress($jobId, 0, 1, 'Refreshing drive info: ' . $path);

        $info = $this->discovery->describePath($path);
        $this->drives->updateDiscoveredInfo($driveId, [
            'serial_number' => $info['serial_number'],
            'hardware_serial' => $info['hardware_serial'] ?? null,
            'filesystem_uuid' => $info['filesystem_uuid'] ?? null,
            'volume_label' => $info['label'] ?? null,
            'model' => $info['model'] ?? null,
            'vendor' => $info['vendor'] ?? null,
            'filesystem' => $info['filesystem'] ?? null,
            'mount_path' => $info['path'],
            'device_name' => $info['device_name'] ?? null,
            'identity_source' => $info['identity_source'] ?? null,
            'total_bytes' => (int)$info['total_bytes'],
            'free_bytes' => (int)$info['free_bytes'],
            'used_bytes' => (int)$info['used_bytes'],
            'notes' => $drive['notes'] ?? null,
            'is_active' => (int)$drive['is_active'],
        ], false);

        $this->jobs->progress($jobId, 1, 1, 'Drive info refreshed');
        $this->jobs->complete($jobId, ['drive_id' => $driveId, 'path' => $path, 'progress_current' => 1, 'progress_total' => 1]);
    }

    private function runMovePlanDryRun(int $jobId, array $payload): void
    {
        $planId = (int)($payload['move_plan_id'] ?? 0);
        if ($planId <= 0) {
            throw new \RuntimeException('Dry-run job is missing move plan ID.');
        }

        $stmt = $this->db->prepare('SELECT id, status FROM move_plans WHERE id = ?');
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();
        if (!$plan) {
            throw new \RuntimeException('Move plan no longer exists.');
        }
        if ((string)$plan['status'] !== 'validated') {
            throw new \RuntimeException('Move plan must be validated before dry-run.');
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM move_plan_items WHERE move_plan_id = ? AND status = 'validated'");
        $countStmt->execute([$planId]);
        $total = (int)$countStmt->fetchColumn();
        if ($total <= 0) {
            throw new \RuntimeException('Move plan has no validated items to dry-run.');
        }

        $this->throwIfCancelled($jobId);
        $this->jobs->progress($jobId, 0, $total, 'Starting dry-run for move plan #' . $planId);

        $itemStmt = $this->db->prepare("SELECT mpi.id, mpi.relative_path, mpi.size_bytes, f.full_path AS source_full_path, sd.friendly_name AS source_drive_name, td.friendly_name AS target_drive_name, td.mount_path AS target_mount_path FROM move_plan_items mpi JOIN file_entries f ON f.id = mpi.file_entry_id JOIN drives sd ON sd.id = mpi.source_drive_id JOIN drives td ON td.id = mpi.target_drive_id WHERE mpi.move_plan_id = ? AND mpi.status = 'validated' ORDER BY mpi.id");
        $itemStmt->execute([$planId]);

        $checked = 0;
        $bytes = 0;
        $sourceMissing = 0;
        $sourceSizeMismatch = 0;
        $targetMountMissing = 0;
        $targetParentMissing = 0;
        $targetParentNotWritable = 0;
        $targetAlreadyExists = 0;
        $tempTargetExists = 0;
        $sameSourceTarget = 0;
        $sampleIssues = [];
        $lastUpdateAt = 0.0;

        while ($item = $itemStmt->fetch()) {
            $this->throwIfCancelled($jobId);
            $checked++;
            $bytes += (int)$item['size_bytes'];
            $issues = [];

            $sourcePath = (string)($item['source_full_path'] ?? '');
            if ($sourcePath === '' || !is_file($sourcePath)) {
                $sourceMissing++;
                $issues[] = 'source missing on disk';
            } elseif (filesize($sourcePath) !== (int)$item['size_bytes']) {
                $sourceSizeMismatch++;
                $issues[] = 'source size changed';
            }

            $targetMount = rtrim((string)($item['target_mount_path'] ?? ''), "/\\");
            if ($targetMount === '' || !is_dir($targetMount)) {
                $targetMountMissing++;
                $issues[] = 'target mount path unavailable';
            } else {
                $relativePath = ltrim(str_replace('\\', '/', (string)$item['relative_path']), '/');
                $targetPath = $targetMount . '/' . $relativePath;
                $tempTargetPath = $targetPath . '.drivemaster-tmp-' . $jobId;
                $targetParent = dirname($targetPath);

                if ($sourcePath !== '' && strcasecmp($sourcePath, $targetPath) === 0) {
                    $sameSourceTarget++;
                    $issues[] = 'source and target path are identical';
                }
                if (file_exists($targetPath)) {
                    $targetAlreadyExists++;
                    $issues[] = 'target path already exists';
                }
                if (file_exists($tempTargetPath)) {
                    $tempTargetExists++;
                    $issues[] = 'temporary target path already exists';
                }
                if (!is_dir($targetParent)) {
                    $targetParentMissing++;
                    $issues[] = 'target parent folder does not exist';
                } elseif (!is_writable($targetParent)) {
                    $targetParentNotWritable++;
                    $issues[] = 'target parent folder is not writable';
                }
            }

            if ($issues && count($sampleIssues) < 50) {
                $sampleIssues[] = [
                    'item_id' => (int)$item['id'],
                    'source_drive' => (string)$item['source_drive_name'],
                    'target_drive' => (string)$item['target_drive_name'],
                    'relative_path' => (string)$item['relative_path'],
                    'issues' => $issues,
                ];
            }

            $now = microtime(true);
            if (($now - $lastUpdateAt) >= 0.75 || ($checked % 500) === 0 || $checked === $total) {
                $lastUpdateAt = $now;
                $this->jobs->progress($jobId, $checked, $total, 'Dry-run checked ' . $checked . ' of ' . $total . ' items');
            }
        }

        $issueCount = $sourceMissing + $sourceSizeMismatch + $targetMountMissing + $targetParentMissing + $targetParentNotWritable + $targetAlreadyExists + $tempTargetExists + $sameSourceTarget;
        $this->jobs->complete($jobId, [
            'move_plan_id' => $planId,
            'items_checked' => $checked,
            'bytes_checked' => $bytes,
            'dry_run_only' => true,
            'source_missing' => $sourceMissing,
            'source_size_mismatch' => $sourceSizeMismatch,
            'target_mount_missing' => $targetMountMissing,
            'target_parent_missing' => $targetParentMissing,
            'target_parent_not_writable' => $targetParentNotWritable,
            'target_already_exists' => $targetAlreadyExists,
            'temp_target_exists' => $tempTargetExists,
            'same_source_target' => $sameSourceTarget,
            'issue_count' => $issueCount,
            'sample_issues' => $sampleIssues,
            'progress_current' => $checked,
            'progress_total' => $total,
        ]);
    }

    private function throwIfCancelled(int $jobId): void
    {
        if ($this->jobs->isCancelRequested($jobId)) {
            throw new \RuntimeException('Job was cancelled.');
        }
    }
}
