<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function percent_value(int $current, ?int $total): float
{
    if ($total === null || $total <= 0) {
        return 0.0;
    }
    return max(0.0, min(100.0, ($current / $total) * 100.0));
}

function fmt_bytes(int $bytes): string
{
    return format_bytes($bytes);
}

$limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
$stmt = db()->prepare('SELECT * FROM jobs ORDER BY id DESC LIMIT ?');
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->execute();

$jobs = [];
foreach ($stmt->fetchAll() as $job) {
    $current = (int)($job['progress_current'] ?? 0);
    $total = $job['progress_total'] === null ? null : (int)$job['progress_total'];
    $percent = percent_value($current, $total);

    $jobs[] = [
        'id' => (int)$job['id'],
        'job_type' => (string)$job['job_type'],
        'status' => (string)$job['status'],
        'cancel_requested' => (int)($job['cancel_requested'] ?? 0) === 1,
        'progress_current' => $current,
        'progress_total' => $total,
        'progress_percent' => $percent,
        'progress_percent_label' => $total && $total > 0 ? number_format($percent, 1) . '%' : '-',
        'progress_label' => $total && $total > 0 ? fmt_bytes($current) . ' / ' . fmt_bytes($total) : (string)$current,
        'progress_message' => (string)($job['progress_message'] ?? ''),
        'error_message' => (string)($job['error_message'] ?? ''),
        'last_heartbeat_at' => (string)($job['last_heartbeat_at'] ?? ''),
        'created_at' => (string)($job['created_at'] ?? ''),
        'finished_at' => (string)($job['finished_at'] ?? ''),
    ];
}

echo json_encode(['jobs' => $jobs, 'updated_at' => date('Y-m-d H:i:s')], JSON_THROW_ON_ERROR);
