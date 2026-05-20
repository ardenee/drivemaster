<?php

declare(strict_types=1);

namespace DriveMaster\Support;

use PDO;

final class ExecutionSettings
{
    public static function ensureTable(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(120) NOT NULL PRIMARY KEY, setting_value TEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function defaults(): array
    {
        return [
            'execution_actual_moves_enabled' => '0',
            'execution_require_dry_run' => '1',
            'execution_require_final_safety_check' => '1',
            'execution_require_confirmation_phrase' => '1',
            'execution_confirmation_phrase' => 'I understand this will move files',
        ];
    }

    public static function all(PDO $db): array
    {
        self::ensureTable($db);
        $settings = self::defaults();
        $stmt = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'execution_%'");
        foreach ($stmt->fetchAll() as $row) {
            $settings[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
        return $settings;
    }

    public static function set(PDO $db, string $key, string $value): void
    {
        self::ensureTable($db);
        $stmt = $db->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');
        $stmt->execute([$key, $value]);
    }

    public static function bool(array $settings, string $key): bool
    {
        return (string)($settings[$key] ?? '0') === '1';
    }

    public static function actualMovesEnabled(array $settings): bool
    {
        return self::bool($settings, 'execution_actual_moves_enabled');
    }
}
