<?php

declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config/config.php';

if (!file_exists($configPath)) {
    $configPath = dirname(__DIR__) . '/config/config.example.php';
}

$config = require $configPath;

date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Dublin');

spl_autoload_register(function (string $class): void {
    $prefix = 'DriveMaster\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

function app_config(): array
{
    global $config;
    return $config;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['charset']
    );

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_bytes(int|float|null $bytes): string
{
    $bytes = (float)($bytes ?? 0);
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $index = 0;

    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    return $index === 0 ? sprintf('%d %s', (int)$bytes, $units[$index]) : sprintf('%.2f %s', $bytes, $units[$index]);
}

function current_user(): ?array
{
    return DriveMaster\Support\Auth::currentUser(db());
}

function require_admin(): void
{
    DriveMaster\Support\Auth::requireAdmin(db());
}

function enforce_auth(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $openPages = ['login.php', 'logout.php', 'setup-admin.php', 'install.php'];
    DriveMaster\Support\Auth::startSession();
    DriveMaster\Support\Auth::ensureTable(db());
    if (in_array($script, $openPages, true)) {
        return;
    }
    if (DriveMaster\Support\Auth::userCount(db()) <= 0) {
        header('Location: setup-admin.php');
        exit;
    }
    if (!DriveMaster\Support\Auth::currentUser(db())) {
        $next = (string)($_SERVER['REQUEST_URI'] ?? 'index.php');
        header('Location: login.php?next=' . rawurlencode($next));
        exit;
    }
}

enforce_auth();
