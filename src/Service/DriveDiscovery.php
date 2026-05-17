<?php

declare(strict_types=1);

namespace DriveMaster\Service;

final class DriveDiscovery
{
    public function discover(): array
    {
        $paths = [];

        if (PHP_OS_FAMILY === 'Windows') {
            foreach (range('A', 'Z') as $letter) {
                $path = $letter . ':\\';
                if (is_dir($path)) {
                    $paths[] = $path;
                }
            }
        } else {
            $candidates = [
                '/volumeUSB*/usbshare*',
                '/volume*/usbshare*',
                '/mnt/*',
                '/media/*',
                '/run/media/*/*',
            ];

            foreach ($candidates as $pattern) {
                foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $path) {
                    $paths[] = $path;
                }
            }
        }

        $paths = array_values(array_unique(array_filter($paths, fn(string $path): bool => $this->isUsableScanPath($path))));
        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(fn(string $path): array => $this->describePath($path), $paths);
    }

    public function groupByPhysicalDisk(array $mountedPaths): array
    {
        $groups = [];

        foreach ($mountedPaths as $pathInfo) {
            $key = (string)($pathInfo['disk_key'] ?? $pathInfo['device_name'] ?? $pathInfo['serial_number'] ?? $pathInfo['path']);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'disk_key' => $key,
                    'device_name' => $pathInfo['device_name'] ?? null,
                    'disk_serial' => $pathInfo['hardware_serial'] ?? null,
                    'model' => $pathInfo['model'] ?? null,
                    'vendor' => $pathInfo['vendor'] ?? null,
                    'partitions' => [],
                    'total_bytes' => 0,
                    'free_bytes' => 0,
                ];
            }

            $groups[$key]['partitions'][] = $pathInfo;
            $groups[$key]['total_bytes'] += (int)($pathInfo['total_bytes'] ?? 0);
            $groups[$key]['free_bytes'] += (int)($pathInfo['free_bytes'] ?? 0);
        }

        return array_values($groups);
    }

    public function describePath(string $path): array
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Z]:$/i', $path)) {
            $path .= DIRECTORY_SEPARATOR;
        }

        $totalBytes = @disk_total_space($path) ?: 0;
        $freeBytes = @disk_free_space($path) ?: 0;
        $usedBytes = max(0, $totalBytes - $freeBytes);
        $mountInfo = $this->findMountInfo($path);
        $device = $mountInfo['device'] ?? null;
        $baseDevice = $device ? $this->baseDeviceName($device) : '';
        $hardware = $baseDevice !== '' ? $this->readLinuxBlockHardware($baseDevice) : [];
        $filesystemUuid = $device ? $this->detectFilesystemUuid($device) : null;
        $hardwareSerial = $hardware['serial'] ?? null;
        $serialNumber = $this->buildStableIdentity($path, (int)$totalBytes, $hardwareSerial, $filesystemUuid);

        return [
            'path' => $path,
            'label' => basename($path) ?: $path,
            'total_bytes' => (int)$totalBytes,
            'free_bytes' => (int)$freeBytes,
            'used_bytes' => (int)$usedBytes,
            'serial_number' => $serialNumber,
            'hardware_serial' => $hardwareSerial,
            'filesystem_uuid' => $filesystemUuid,
            'model' => $hardware['model'] ?? null,
            'vendor' => $hardware['vendor'] ?? null,
            'filesystem' => $mountInfo['filesystem'] ?? null,
            'device_name' => $baseDevice ?: null,
            'partition_device' => $device,
            'mount_point' => $mountInfo['mount_point'] ?? null,
            'disk_key' => $this->diskKey($baseDevice, $hardwareSerial),
            'identity_source' => $this->identitySource($hardwareSerial, $filesystemUuid),
        ];
    }

    private function isUsableScanPath(string $path): bool
    {
        if (!is_dir($path) || !is_readable($path)) {
            return false;
        }

        if (preg_match('#^/volumeUSB\d+$#', $path)) {
            return false;
        }

        $mountInfo = $this->findMountInfo($path);
        if (!$mountInfo) {
            return true;
        }

        $baseDevice = $this->baseDeviceName($mountInfo['device']);
        if ($baseDevice === '') {
            return true;
        }

        if (in_array($baseDevice, ['md', 'md0', 'md1', 'md2', 'synoboot', 'loop', 'zram'], true)) {
            return false;
        }

        if (str_starts_with($baseDevice, 'md') || str_starts_with($baseDevice, 'loop') || str_starts_with($baseDevice, 'zram')) {
            return false;
        }

        return true;
    }

    private function buildStableIdentity(string $path, int $totalBytes, ?string $hardwareSerial, ?string $filesystemUuid): string
    {
        if ($hardwareSerial !== null && $hardwareSerial !== '') {
            return 'hw-' . $hardwareSerial;
        }

        if ($filesystemUuid !== null && $filesystemUuid !== '') {
            return 'fs-' . $filesystemUuid;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $volumeSerial = $this->detectWindowsVolumeSerial($path);
            if ($volumeSerial !== null) {
                return 'winvol-' . $volumeSerial;
            }
        }

        return 'fallback-' . sha1(strtolower($path) . '|' . (string)$totalBytes);
    }

    private function identitySource(?string $hardwareSerial, ?string $filesystemUuid): string
    {
        if ($hardwareSerial !== null && $hardwareSerial !== '') {
            return 'hardware_serial';
        }
        if ($filesystemUuid !== null && $filesystemUuid !== '') {
            return 'filesystem_uuid';
        }
        return 'fallback_path_size';
    }

    private function diskKey(string $baseDevice, ?string $hardwareSerial): string
    {
        if ($hardwareSerial !== null && $hardwareSerial !== '') {
            return 'hw-' . $hardwareSerial;
        }
        if ($baseDevice !== '') {
            return 'dev-' . $baseDevice;
        }
        return 'unknown';
    }

    private function readLinuxBlockHardware(string $baseDevice): array
    {
        $basePath = '/sys/block/' . $baseDevice . '/device';
        $data = [];

        foreach (['serial', 'model', 'vendor'] as $field) {
            $file = $basePath . '/' . $field;
            $value = $this->readTrimmedFile($file);
            if ($value !== null && $value !== '' && !preg_match('/^0+$/', $value)) {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    private function readTrimmedFile(string $file): ?string
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $value = @file_get_contents($file);
        if ($value === false) {
            return null;
        }

        return trim((string)$value);
    }

    private function detectFilesystemUuid(string $device): ?string
    {
        if (!str_starts_with($device, '/dev/')) {
            return null;
        }

        $byUuid = glob('/dev/disk/by-uuid/*') ?: [];
        foreach ($byUuid as $uuidPath) {
            $target = realpath($uuidPath);
            if ($target === $device) {
                return basename($uuidPath);
            }
        }

        $command = 'blkid -s UUID -o value ' . escapeshellarg($device) . ' 2>/dev/null';
        $output = [];
        @exec($command, $output, $code);
        if ($code === 0 && isset($output[0]) && trim($output[0]) !== '') {
            return trim($output[0]);
        }

        return null;
    }

    private function findMountInfo(string $path): ?array
    {
        if (!is_readable('/proc/mounts')) {
            return null;
        }

        $realPath = realpath($path) ?: $path;
        $mounts = file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $best = null;
        $bestLength = -1;

        foreach ($mounts as $line) {
            $parts = preg_split('/\s+/', $line);
            if (!$parts || count($parts) < 3) {
                continue;
            }

            $device = str_replace('\\040', ' ', $parts[0]);
            $mountPoint = str_replace('\\040', ' ', $parts[1]);
            $filesystem = $parts[2];

            if ($realPath === $mountPoint || str_starts_with($realPath, rtrim($mountPoint, '/') . '/')) {
                $length = strlen($mountPoint);
                if ($length > $bestLength) {
                    $bestLength = $length;
                    $best = ['device' => $device, 'mount_point' => $mountPoint, 'filesystem' => $filesystem];
                }
            }
        }

        return $best;
    }

    private function baseDeviceName(string $device): string
    {
        if (!str_starts_with($device, '/dev/')) {
            return '';
        }

        $deviceName = basename($device);
        return preg_replace('/p?\d+$/', '', $deviceName) ?: $deviceName;
    }

    private function detectWindowsVolumeSerial(string $path): ?string
    {
        $drive = strtoupper(substr($path, 0, 2));
        if (!preg_match('/^[A-Z]:$/', $drive)) {
            return null;
        }

        $command = 'vol ' . escapeshellarg($drive);
        $output = [];
        @exec($command, $output);
        $text = implode("\n", $output);

        if (preg_match('/Serial Number is\s+([A-F0-9-]+)/i', $text, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }
}
