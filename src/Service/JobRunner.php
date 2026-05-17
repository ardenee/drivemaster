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
        $this->jobs->progress($jobId, 0, null, 'Preparing scan: ' . $path);

        if ($serialNumber === null) {
            $details = $this->discovery->describePath($path);
            $serialNumber = $details['serial_number'];
        }

        $scanner = new DriveScanner($this->db, $this->drives);
        $scanRunId = $scanner->scan(
            $path,
            $friendlyName,
            $serialNumber,
            function (array $stats, string $message) use ($jobId): void {
                $this->throwIfCancelled($jobId);
                $current = (int)$stats['files'] + (int)$stats['folders'];
                $this->jobs->progress($jobId, $current, null, $message);
            },
            fn(): bool => $this->jobs->isCancelRequested($jobId)
        );

        $this->throwIfCancelled($jobId);
        $this->jobs->complete($jobId, ['scan_run_id' => $scanRunId]);
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
        $this->jobs->complete($jobId, ['drive_id' => $driveId, 'path' => $path]);
    }

    private function throwIfCancelled(int $jobId): void
    {
        if ($this->jobs->isCancelRequested($jobId)) {
            throw new \RuntimeException('Job was cancelled.');
        }
    }
}
