<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

function coverage_match_sql(): string
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

$matchSql = coverage_match_sql();

$inventory = db()->query("SELECT COUNT(*) AS total_items, SUM(entry_type = 'file') AS file_count, SUM(entry_type = 'folder') AS folder_count, COALESCE(SUM(CASE WHEN entry_type = 'file' THEN size_bytes ELSE 0 END), 0) AS total_size FROM file_entries WHERE is_missing = 0")->fetch() ?: [];

$coverageSql = "
    SELECT c.id, c.name,
        COUNT(m.file_entry_id) AS total_items,
        COALESCE(SUM(m.entry_type = 'file'), 0) AS file_count,
        COALESCE(SUM(m.entry_type = 'folder'), 0) AS folder_count,
        COALESCE(SUM(CASE WHEN m.entry_type = 'file' THEN m.size_bytes ELSE 0 END), 0) AS total_size,
        COALESCE(SUM(m.is_manual = 1), 0) AS manual_count,
        COALESCE(SUM(m.is_rule = 1), 0) AS rule_count,
        COALESCE(SUM(m.is_manual = 1 AND m.is_rule = 1), 0) AS both_count
    FROM categories c
    LEFT JOIN ({$matchSql}) m ON m.category_id = c.id
    WHERE c.is_active = 1
    GROUP BY c.id, c.name
    ORDER BY c.name
";
$coverage = db()->query($coverageSql)->fetchAll();

$uncategorisedSql = "
    SELECT COUNT(*) AS total_items,
        COALESCE(SUM(f.entry_type = 'file'), 0) AS file_count,
        COALESCE(SUM(f.entry_type = 'folder'), 0) AS folder_count,
        COALESCE(SUM(CASE WHEN f.entry_type = 'file' THEN f.size_bytes ELSE 0 END), 0) AS total_size
    FROM file_entries f
    WHERE f.is_missing = 0
    AND NOT EXISTS (SELECT 1 FROM ({$matchSql}) m WHERE m.file_entry_id = f.id)
";
$uncategorised = db()->query($uncategorisedSql)->fetch() ?: [];

$conflictSql = "
    SELECT COUNT(*) AS conflict_items,
        COALESCE(SUM(x.entry_type = 'file'), 0) AS conflict_files,
        COALESCE(SUM(x.entry_type = 'folder'), 0) AS conflict_folders,
        COALESCE(SUM(CASE WHEN x.entry_type = 'file' THEN x.size_bytes ELSE 0 END), 0) AS conflict_size
    FROM (
        SELECT m.file_entry_id, MAX(m.entry_type) AS entry_type, MAX(m.size_bytes) AS size_bytes, COUNT(DISTINCT m.category_id) AS category_count
        FROM ({$matchSql}) m
        GROUP BY m.file_entry_id
        HAVING COUNT(DISTINCT m.category_id) > 1
    ) x
";
$conflicts = db()->query($conflictSql)->fetch() ?: [];

render_header('Category Coverage');
?>
<div class="card">
    <h2>Category coverage</h2>
    <p class="muted">Shows how much of the current inventory is covered by manual category assignments and enabled category rules. This is read-only and does not move files.</p>
</div>

<div class="card">
    <h2>Inventory summary</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Metric</th><th>Items</th><th>Files</th><th>Folders</th><th>File size</th><th>Action</th></tr></thead>
            <tbody>
                <tr><td>Total indexed inventory</td><td><?= e(number_format((int)($inventory['total_items'] ?? 0))) ?></td><td><?= e(number_format((int)($inventory['file_count'] ?? 0))) ?></td><td><?= e(number_format((int)($inventory['folder_count'] ?? 0))) ?></td><td><?= e(format_bytes((int)($inventory['total_size'] ?? 0))) ?></td><td><a class="button" href="category-preview.php?view_mode=all">View</a></td></tr>
                <tr><td>Uncategorised</td><td><?= e(number_format((int)($uncategorised['total_items'] ?? 0))) ?></td><td><?= e(number_format((int)($uncategorised['file_count'] ?? 0))) ?></td><td><?= e(number_format((int)($uncategorised['folder_count'] ?? 0))) ?></td><td><?= e(format_bytes((int)($uncategorised['total_size'] ?? 0))) ?></td><td><a class="button" href="category-preview.php?view_mode=uncategorised&source=both">Review</a></td></tr>
                <tr><td>Category conflicts</td><td><?= e(number_format((int)($conflicts['conflict_items'] ?? 0))) ?></td><td><?= e(number_format((int)($conflicts['conflict_files'] ?? 0))) ?></td><td><?= e(number_format((int)($conflicts['conflict_folders'] ?? 0))) ?></td><td><?= e(format_bytes((int)($conflicts['conflict_size'] ?? 0))) ?></td><td><span class="muted">Conflict detail next</span></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Coverage by category</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Category</th><th>Items</th><th>Files</th><th>Folders</th><th>File size</th><th>Manual matches</th><th>Rule matches</th><th>Manual + rule</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($coverage as $row): ?>
                <tr>
                    <td><?= e($row['name']) ?></td>
                    <td><?= e(number_format((int)$row['total_items'])) ?></td>
                    <td><?= e(number_format((int)$row['file_count'])) ?></td>
                    <td><?= e(number_format((int)$row['folder_count'])) ?></td>
                    <td><?= e(format_bytes((int)$row['total_size'])) ?></td>
                    <td><?= e(number_format((int)$row['manual_count'])) ?></td>
                    <td><?= e(number_format((int)$row['rule_count'])) ?></td>
                    <td><?= e(number_format((int)$row['both_count'])) ?></td>
                    <td class="actions">
                        <a class="button" href="category-preview.php?view_mode=category&category_id=<?= e((string)$row['id']) ?>&source=both">Preview</a>
                        <a class="button" href="category-preview.php?view_mode=category&category_id=<?= e((string)$row['id']) ?>&source=manual">Manual</a>
                        <a class="button" href="category-preview.php?view_mode=category&category_id=<?= e((string)$row['id']) ?>&source=rules">Rules</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$coverage): ?><tr><td colspan="9">No active categories found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
