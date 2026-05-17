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
                '/volumeUSB*',
                '/volumeUSB*/usbshare*',
                '/volume*/usbshare*',
                '/volume*/@usb*',
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

        $paths = array_values(array_unique(array_filter($paths, static fn(string $path): bool => is_dir($path) && is_readable($path))));
        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(fn(string $path): array => $this->describePath($path), $paths);
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

        return [
            'path' => $path,
            'label' => basename($path) ?: $path,
            'total_bytes' => (int)$totalBytes,
            'free_bytes' => (int)$freeBytes,
            'used_bytes' => (int)$usedBytes,
            'serial_number' => $this->detectSerial($path, (int)$totalBytes),
        ];
    }

    private function detectSerial(string $path, int $totalBytes): string
    {
        $serial = $this->detectSynologySerial($path);

        if ($serial !== null) {
            return $serial;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $serial = $this->detectWindowsVolumeSerial($path);
            if ($serial !== null) {
                return $serial;
            }
        }

        return 'fallback-' . sha1(strtolower($path) . '|' . (string)$totalBytes);
    }

    private function detectSynologySerial(string $path): ?string
    {
        if (!is_readable('/proc/mounts')) {
            return null;
        }

        $realPath = realpath($path) ?: $path;
        $mounts = file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $bestDevice = null;
        $bestMount = '';

        foreach ($mounts as $line) {
            $parts = preg_split('/\s+/', $line);
            if (!$parts || count($parts) < 2) {
                continue;
            }

            $device = str_replace('\\040', ' ', $parts[0]);
            $mountPoint = str_replace('\\040', ' ', $parts[1]);

            if (str_starts_with($realPath, $mountPoint) && strlen($mountPoint) > strlen($bestMount)) {
                $bestMount = $mountPoint;
                $bestDevice = $device;
            }
        }

        if (!$bestDevice || !str_starts_with($bestDevice, '/dev/')) {
            return null;
        }

        $deviceName = basename($bestDevice);
        $baseDevice = preg_replace('/p?\d+$/', '', $deviceName) ?: $deviceName;
        $serialFile = '/sys/block/' . $baseDevice . '/device/serial';

        if (is_readable($serialFile)) {
            $serial = trim((string)file_get_contents($serialFile));
            if ($serial !== '') {
                return $serial;
            }
        }

        return 'device-' . $baseDevice;
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
