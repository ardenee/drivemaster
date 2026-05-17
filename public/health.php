<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "DriveMaster health check\n";
echo "========================\n\n";

echo "PHP version: " . PHP_VERSION . "\n";
echo "Current file: " . __FILE__ . "\n";
echo "Current dir: " . __DIR__ . "\n";
echo "PDO loaded: " . (extension_loaded('pdo') ? 'yes' : 'no') . "\n";
echo "pdo_mysql loaded: " . (extension_loaded('pdo_mysql') ? 'yes' : 'no') . "\n";
echo "mbstring loaded: " . (extension_loaded('mbstring') ? 'yes' : 'no') . "\n\n";

$configFile = dirname(__DIR__) . '/config/config.php';
$configExampleFile = dirname(__DIR__) . '/config/config.example.php';

echo "config/config.php exists: " . (file_exists($configFile) ? 'yes' : 'no') . "\n";
echo "config/config.php readable: " . (is_readable($configFile) ? 'yes' : 'no') . "\n";
echo "config/config.example.php exists: " . (file_exists($configExampleFile) ? 'yes' : 'no') . "\n\n";

try {
    require dirname(__DIR__) . '/src/bootstrap.php';
    echo "bootstrap.php: OK\n";

    $config = app_config();
    echo "DB host: " . ($config['db']['host'] ?? '') . "\n";
    echo "DB port: " . ($config['db']['port'] ?? '') . "\n";
    echo "DB database: " . ($config['db']['database'] ?? '') . "\n";
    echo "DB username: " . ($config['db']['username'] ?? '') . "\n\n";

    $pdo = db();
    echo "Database connection: OK\n";

    $tables = ['drives', 'scan_runs', 'file_entries', 'categories', 'category_assignments'];
    foreach ($tables as $table) {
        try {
            $count = $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
            echo "Table " . $table . ": OK, rows=" . $count . "\n";
        } catch (Throwable $tableError) {
            echo "Table " . $table . ": FAIL - " . $tableError->getMessage() . "\n";
        }
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}
