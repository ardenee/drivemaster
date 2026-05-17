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
        $job = $this->jobs->claimNext($workerId);
        if (!$job) {
            return false;
        }

        try {
            $payload = json_decode($job['payload_json'], true, 512, JSON_THROW_ON_ERROR);

            match ($job['job_type']) {
                'scan_drive' => $this->runScanDrive((int)$job['id'], $payload),
                default => throw new \RuntimeException('Unknown job type: ' . $job['job_type']),
            };
        } catch (Throwable $e) {
            $this->jobs->fail((int)$job['id'], $e->getMessage());
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

        $this->jobs->progress($jobId, 0, null, 'Preparing scan: ' . $path);

        if ($serialNumber === null) {
            $details = $this->discovery->describePath($path);
            $serialNumber = $details['serial_number'];
        }

        $scanner = new DriveScanner($this->db, $this->drives);
        $scanRunId = $scanner->scan($path, $friendlyName, $serialNumber, function (array $stats, string $message) use ($jobId): void {
            $current = (int)$stats['files'] + (int)$stats['folders'];
            $this->jobs->progress($jobId, $current, null, $message);
        });

        $this->jobs->complete($jobId, ['scan_run_id' => $scanRunId]);
    }
}
