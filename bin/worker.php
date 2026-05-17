<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Repository\DriveRepository;
use DriveMaster\Repository\JobRepository;
use DriveMaster\Service\DriveDiscovery;
use DriveMaster\Service\JobRunner;

$options = getopt('', ['once', 'sleep::']);
$runOnce = array_key_exists('once', $options);
$sleepSeconds = isset($options['sleep']) ? max(1, (int)$options['sleep']) : 5;
$workerId = gethostname() . '-' . getmypid();

$jobs = new JobRepository(db());
$drives = new DriveRepository(db());
$discovery = new DriveDiscovery();
$runner = new JobRunner($jobs, $drives, $discovery, db());

echo 'DriveMaster worker started: ' . $workerId . PHP_EOL;

while (true) {
    $ranJob = $runner->runNext($workerId);

    if ($runOnce) {
        echo $ranJob ? 'Processed one job.' . PHP_EOL : 'No queued jobs.' . PHP_EOL;
        break;
    }

    if (!$ranJob) {
        sleep($sleepSeconds);
    }
}
