<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

function pr_match_sql(): string
{
    return "
        SELECT category_id, file_entry_id, entry_type, size_bytes, MAX(is_manual) AS is_manual, MAX(is_rule) AS is_rule
        FROM (
            SELECT cma.category_id, f.id AS file_entry_id, f.entry_type, f.size_bytes, 1 AS is_manual, 0 AS is_rule
            FROM category_manual_assignments cma
            JOIN categories c ON c.id = cma.category_id AND c.is_active = 1
            JOIN file_entries f ON f.is_missing = 0
                AND (
                    cma.file_entry_id = f.id
                    OR (
                        cma.assignment_type = 'folder'
                        AND cma.drive_id = f.drive_id
                        AND cma.path_prefix IS NOT NULL
                        AND (f.relative_path = cma.path_prefix OR f.relative_path LIKE CONCAT(cma.path_prefix, '/%'))
                    )
                )
            UNION ALL
            SELECT cr.category_id, f.id AS file_entry_id, f.entry_type, f.size_bytes, 0 AS is_manual, 1 AS is_rule
            FROM category_rules cr
            JOIN categories c ON c.id = cr.category_id AND c.is_active = 1
            JOIN file_entries f ON f.is_missing = 0
                AND cr.is_enabled = 1
                AND (cr.drive_id IS NULL OR cr.drive_id = f.drive_id)
                AND (
                    (cr.rule_type = 'extension' AND cr.pattern = f.extension)
                    OR (cr.rule_type = 'folder_path' AND (f.relative_path = cr.pattern OR f.relative_path LIKE CONCAT(cr.pattern, '/%')))
                    OR (cr.rule_type = 'path_contains' AND f.relative_path LIKE CONCAT('%', cr.pattern, '%'))
                    OR (cr.rule_type = 'name_contains' AND f.name LIKE CONCAT('%', cr.pattern, '%'))
                )
        ) raw_matches
        GROUP BY category_id, file_entry_id, entry_type, size_bytes
    ";
}

function pr_status_badge(string $status): string
{
    $class = match ($status) {
        'Blocker' => 'readiness-badge readiness-blocker',
        'Warning' => 'readiness-badge readiness-warning',
        default => 'readiness-badge readiness-ok',
    };
    return '<span class="' . e($class) . '">' . e($status) . '</span>';
}

$matchSql = pr_match_sql();

$activeConflictsSql = "
    SELECT COUNT(*) AS item_count,
        COALESCE(SUM(CASE WHEN x.entry_type = 'file' THEN x.size_bytes ELSE 0 END), 0) AS total_size
    FROM (
        SELECT m.file_entry_id, MAX(m.entry_type) AS entry_type, MAX(m.size_bytes) AS size_bytes
        FROM ({$matchSql}) m
        GROUP BY m.file_entry_id
        HAVING COUNT(DISTINCT m.category_id) > 1
    ) x
    LEFT JOIN category_conflict_ignores cci ON cci.file_entry_id = x.file_entry_id
    WHERE cci.id IS NULL
";
$activeConflicts = db()->query($activeConflictsSql)->fetch() ?: [];

$acceptedConflictsSql = "
    SELECT COUNT(*) AS item_count,
        COALESCE(SUM(CASE WHEN x.entry_type = 'file' THEN x.size_bytes ELSE 0 END), 0) AS total_size
    FROM (
        SELECT m.file_entry_id, MAX(m.entry_type) AS entry_type, MAX(m.size_bytes) AS size_bytes
        FROM ({$matchSql}) m
        GROUP BY m.file_entry_id
        HAVING COUNT(DISTINCT m.category_id) > 1
    ) x
    JOIN category_conflict_ignores cci ON cci.file_entry_id = x.file_entry_id
";
$acceptedConflicts = db()->query($acceptedConflictsSql)->fetch() ?: [];

$uncategorisedSql = "
    SELECT COUNT(*) AS item_count,
        COALESCE(SUM(f.entry_type = 'file'), 0) AS file_count,
        COALESCE(SUM(f.entry_type = 'folder'), 0) AS folder_count,
        COALESCE(SUM(CASE WHEN f.entry_type = 'file' THEN f.size_bytes ELSE 0 END), 0) AS total_size
    FROM file_entries f
    WHERE f.is_missing = 0
    AND NOT EXISTS (SELECT 1 FROM ({$matchSql}) m WHERE m.file_entry_id = f.id)
";
$uncategorised = db()->query($uncategorisedSql)->fetch() ?: [];

$weakDrives = db()->query("SELECT COUNT(*) FROM drives WHERE is_active = 1 AND (identity_source IS NULL OR identity_source IN ('fallback_path_size') OR serial_number LIKE 'fallback-%' OR serial_number LIKE 'device-%')")->fetchColumn();
$unknownSpace = db()->query('SELECT COUNT(*) FROM drives WHERE is_active = 1 AND total_bytes <= 0')->fetchColumn();
$activeCategories = db()->query('SELECT COUNT(*) FROM categories WHERE is_active = 1')->fetchColumn();
$activeRules = db()->query('SELECT COUNT(*) FROM category_rules WHERE is_enabled = 1')->fetchColumn();
$manualAssignments = db()->query('SELECT COUNT(*) FROM category_manual_assignments')->fetchColumn();

$categorySizesSql = "
    SELECT c.id, c.name,
        COUNT(m.file_entry_id) AS item_count,
        COALESCE(SUM(CASE WHEN m.entry_type = 'file' THEN m.size_bytes ELSE 0 END), 0) AS total_size,
        COALESCE(MAX(d.free_bytes), 0) AS largest_drive_free
    FROM categories c
    LEFT JOIN ({$matchSql}) m ON m.category_id = c.id
    CROSS JOIN (SELECT COALESCE(MAX(free_bytes), 0) AS free_bytes FROM drives WHERE is_active = 1) d
    WHERE c.is_active = 1
    GROUP BY c.id, c.name
    HAVING total_size > largest_drive_free AND total_size > 0
    ORDER BY total_size DESC
    LIMIT 25
";
$oversizedCategories = db()->query($categorySizesSql)->fetchAll();

$checks = [];
$activeConflictCount = (int)($activeConflicts['item_count'] ?? 0);
$acceptedConflictCount = (int)($acceptedConflicts['item_count'] ?? 0);
$uncategorisedCount = (int)($uncategorised['item_count'] ?? 0);
$weakDriveCount = (int)$weakDrives;
$unknownSpaceCount = (int)$unknownSpace;
$categoryCount = (int)$activeCategories;
$ruleCount = (int)$activeRules;
$manualCount = (int)$manualAssignments;

$checks[] = [
    'status' => $activeConflictCount > 0 ? 'Blocker' : 'OK',
    'check' => 'Active category conflicts',
    'details' => number_format($activeConflictCount) . ' conflicted items, ' . format_bytes((int)($activeConflicts['total_size'] ?? 0)) . ' affected file size.',
    'action' => '<a class="button" href="category-conflicts.php?ignore_status=active">Review conflicts</a>',
];
$checks[] = [
    'status' => $acceptedConflictCount > 0 ? 'Warning' : 'OK',
    'check' => 'Accepted category conflicts',
    'details' => number_format($acceptedConflictCount) . ' accepted conflicts. Future planner must not double-count or move these without a specific handling rule.',
    'action' => '<a class="button" href="category-conflicts.php?ignore_status=accepted">Review accepted</a>',
];
$checks[] = [
    'status' => $uncategorisedCount > 0 ? 'Warning' : 'OK',
    'check' => 'Uncategorised inventory',
    'details' => number_format($uncategorisedCount) . ' items uncategorised, ' . format_bytes((int)($uncategorised['total_size'] ?? 0)) . ' file size. These can be excluded from move planning until assigned.',
    'action' => '<a class="button" href="category-preview.php?view_mode=uncategorised&source=both">Review uncategorised</a>',
];
$checks[] = [
    'status' => $categoryCount <= 0 ? 'Blocker' : 'OK',
    'check' => 'Active categories exist',
    'details' => number_format($categoryCount) . ' active categories.',
    'action' => '<a class="button" href="categories.php">Manage categories</a>',
];
$checks[] = [
    'status' => ($ruleCount + $manualCount) <= 0 ? 'Blocker' : 'OK',
    'check' => 'Category assignment data exists',
    'details' => number_format($manualCount) . ' manual assignments, ' . number_format($ruleCount) . ' enabled rules.',
    'action' => '<a class="button" href="category-coverage.php">Coverage</a>',
];
$checks[] = [
    'status' => $weakDriveCount > 0 ? 'Warning' : 'OK',
    'check' => 'Drive identity strength',
    'details' => number_format($weakDriveCount) . ' active drives have weak/fallback identity. This can make reconnect matching risky.',
    'action' => '<a class="button" href="drives.php">Review drives</a>',
];
$checks[] = [
    'status' => $unknownSpaceCount > 0 ? 'Blocker' : 'OK',
    'check' => 'Drive capacity/free-space data',
    'details' => number_format($unknownSpaceCount) . ' active drives have unknown/zero total size.',
    'action' => '<a class="button" href="drives.php">Review drives</a>',
];
$checks[] = [
    'status' => count($oversizedCategories) > 0 ? 'Warning' : 'OK',
    'check' => 'Categories larger than any single current free space',
    'details' => count($oversizedCategories) . ' categories appear larger than the largest currently known free space. They may need to span drives or wait for more drives.',
    'action' => '<a class="button" href="#oversized-categories">Details</a>',
];

$blockers = count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'Blocker'));
$warnings = count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'Warning'));

render_header('Planner Readiness');
?>
<div class="card">
    <h2>Planner readiness</h2>
    <p class="muted">Read-only checklist for whether the inventory is safe enough to start move/reorganisation planning. Nothing here moves files.</p>
    <div class="status-bar">Blockers: <?= e(number_format($blockers)) ?> | Warnings: <?= e(number_format($warnings)) ?> | Result: <?= $blockers > 0 ? '<strong>Not ready for move planning</strong>' : '<strong>Ready for read-only planning tests</strong>' ?></div>
</div>

<div class="card">
    <h2>Readiness checks</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Status</th><th>Check</th><th>Details</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($checks as $check): ?>
                <tr>
                    <td><?= pr_status_badge($check['status']) ?></td>
                    <td><?= e($check['check']) ?></td>
                    <td><?= e($check['details']) ?></td>
                    <td class="actions"><?= $check['action'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="oversized-categories">
    <h2>Categories that may not fit on one currently known drive</h2>
    <p class="muted">This is not necessarily a blocker. The final planner may split a category across drives, but this flags categories that cannot fit into the largest currently recorded free space.</p>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Category</th><th>Items</th><th>Total file size</th><th>Largest known free space</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($oversizedCategories as $row): ?>
                <tr>
                    <td><?= e($row['name']) ?></td>
                    <td><?= e(number_format((int)$row['item_count'])) ?></td>
                    <td><?= e(format_bytes((int)$row['total_size'])) ?></td>
                    <td><?= e(format_bytes((int)$row['largest_drive_free'])) ?></td>
                    <td><a class="button" href="category-preview.php?view_mode=category&category_id=<?= e((string)$row['id']) ?>&source=both">Preview</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$oversizedCategories): ?><tr><td colspan="5">No oversized category warnings.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
