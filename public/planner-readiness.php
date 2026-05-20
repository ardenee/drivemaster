<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

function pr_badge(string $status): string
{
    $class = $status === 'Blocker' ? 'readiness-badge readiness-blocker' : ($status === 'Warning' ? 'readiness-badge readiness-warning' : 'readiness-badge readiness-ok');
    return '<span class="' . e($class) . '">' . e($status) . '</span>';
}

$error = null;
$checks = [];
$rows = [];

try {
    $db = db();
    $activeCategories = (int)$db->query('SELECT COUNT(*) FROM categories WHERE is_active = 1')->fetchColumn();
    $enabledTargets = (int)$db->query('SELECT COUNT(*) FROM category_drive_targets WHERE is_enabled = 1')->fetchColumn();
    $weakDrives = (int)$db->query('SELECT COUNT(*) FROM drives WHERE is_active = 1 AND total_bytes <= 0')->fetchColumn();
    $manualFiles = (int)$db->query("SELECT COUNT(*) FROM category_manual_assignments WHERE assignment_type = 'file'")->fetchColumn();
    $manualFolders = (int)$db->query("SELECT COUNT(*) FROM category_manual_assignments WHERE assignment_type = 'folder'")->fetchColumn();
    $rules = (int)$db->query('SELECT COUNT(*) FROM category_rules WHERE is_enabled = 1')->fetchColumn();

    $checks[] = ['status' => $activeCategories > 0 ? 'OK' : 'Blocker', 'check' => 'Active categories', 'details' => number_format($activeCategories) . ' active categories.', 'action' => '<a class="button" href="categories.php">Categories</a>'];
    $checks[] = ['status' => $enabledTargets > 0 ? 'OK' : 'Blocker', 'check' => 'Target drives', 'details' => number_format($enabledTargets) . ' enabled target mappings.', 'action' => '<a class="button" href="category-targets.php">Targets</a>'];
    $checks[] = ['status' => ($manualFiles + $manualFolders + $rules) > 0 ? 'OK' : 'Warning', 'check' => 'Assignment data', 'details' => number_format($manualFiles) . ' file assignments, ' . number_format($manualFolders) . ' folder assignments, ' . number_format($rules) . ' rules.', 'action' => '<a class="button" href="category-workflow-summary.php">Workflow Summary</a>'];
    $checks[] = ['status' => $weakDrives > 0 ? 'Blocker' : 'OK', 'check' => 'Drive capacity data', 'details' => number_format($weakDrives) . ' active drives have zero/unknown total size.', 'action' => '<a class="button" href="drives.php">Drives</a>'];

    $rows[] = ['label' => 'Manual file assignments', 'count' => $manualFiles, 'handling' => 'Candidate drill-down applies exact safety rules'];
    $rows[] = ['label' => 'Manual folder assignments', 'count' => $manualFolders, 'handling' => 'Workflow and candidate pages expand exact coverage'];
    $rows[] = ['label' => 'Enabled category rules', 'count' => $rules, 'handling' => 'Candidate drill-down applies exact matching'];
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$blockers = count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'Blocker'));
$warnings = count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'Warning'));

render_header('Planner Readiness');
?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
<div class="card"><h2>Planner readiness</h2><p class="muted">Fast read-only checklist. Exact candidate/conflict checks are done on the drill-down pages.</p><div class="status-bar">Blockers: <?= e(number_format($blockers)) ?> | Warnings: <?= e(number_format($warnings)) ?></div></div>
<div class="card"><h2>Readiness checks</h2><div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Status</th><th>Check</th><th>Details</th><th>Action</th></tr></thead><tbody><?php foreach ($checks as $check): ?><tr><td><?= pr_badge($check['status']) ?></td><td><?= e($check['check']) ?></td><td><?= e($check['details']) ?></td><td><?= $check['action'] ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card"><h2>Move planning safety inputs</h2><div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Input</th><th>Count</th><th>Handling</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= e($row['label']) ?></td><td><?= e(number_format($row['count'])) ?></td><td><?= e($row['handling']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php render_footer(); ?>
