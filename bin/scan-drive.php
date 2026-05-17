<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Repository\DriveRepository;
use DriveMaster\Service\DriveScanner;

$options = getopt('', ['path:', 'name:', 'serial::']);

if (empty($options['path']) || empty($options['name'])) {
    fwrite(STDERR, "Usage: php bin/scan-drive.php --path=/path/to/drive --name=FriendlyName [--serial=SerialNumber]\n");
    exit(1);
}

$repository = new DriveRepository(db());
$scanner = new DriveScanner(db(), $repository);

try {
    $scanRunId = $scanner->scan($options['path'], $options['name'], $options['serial'] ?? null);
    echo "Scan complete. Scan run ID: " . $scanRunId . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Scan failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
