<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$rows = db()->query('SELECT name, color_hex FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$colors = [];
foreach ($rows as $row) {
    $name = (string)$row['name'];
    $color = (string)($row['color_hex'] ?? '#dbeafe');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#dbeafe';
    }
    $colors[$name] = $color;
}

echo json_encode(['colors' => $colors], JSON_THROW_ON_ERROR);
