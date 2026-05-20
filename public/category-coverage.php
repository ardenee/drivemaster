<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\IgnoredFolderSql;

function coverage_manual_exists_sql(string $alias = 'f'): string
{
    return "EXISTS (SELECT 1 FROM category_manual_assignments cma WHERE cma.category_id = c.id AND (cma.file_entry_id = {$alias}.id OR (cma.assignment_type = 'folder' AND cma.drive_id = {$alias}.drive_id AND cma.path_prefix IS NOT NULL AND ({$alias}.relative_path = cma.path_prefix OR {$alias}.relative_path LIKE CONCAT(cma.path_prefix, '/%')))))";
}

function coverage_rule_exists_sql(string $alias = 'f'): string
{
    return "EXISTS (SELECT 1 FROM category_rules cr WHERE cr.category_id = c.id AND cr.is_enabled = 1 AND (cr.drive_id IS NULL OR cr.drive_id = {$alias}.drive_id) AND ((cr.rule_type = 'extension' AND cr.pattern = {$alias}.extension) OR (cr.rule_type = 'folder_path' AND ({$alias}.relative_path = cr.pattern OR {$alias}.relative_path LIKE CONCAT(cr.pattern, '/%'))) OR (cr.rule_type = 'path_contains' AND {$alias}.relative_path LIKE CONCAT('%', cr.pattern, '%')) OR (cr.rule_type = 'name_contains' AND {$alias}.name LIKE CONCAT('%', cr.pattern, '%'))))";
}

$error = null;
$inventory = [];
$coverage = [];
$visibleSql = IgnoredFolderSql::excludeCondition('f');
$manualSql = coverage_manual_exists_sql('f');
$ruleSql = coverage_rule_exists_sql('f');
$matchedSql = "({$manualSql} OR {$ruleSql})";

try {
    $inventory = db()->query("SELECT COUNT(*) AS total_items, SUM(f.entry_type = 'file') AS file_count, SUM(f.entry_type = 'folder') AS folder_count, COALESCE(SUM(CASE WHEN f.entry_type = 'file' THEN f.size_bytes ELSE 0 END), 0) AS total_size FROM file_entries f WHERE f.is_missing = 0 AND {$visibleSql}")->fetch() ?: [];

    $coverageSql = "
        SELECT c.id, c.name,
            COUNT(f.id) AS total_items,
            COALESCE(SUM(f.entry_type = 'file'), 0) AS file_count,
            COALESCE(SUM(f.entry_type = 'folder'), 0) AS folder_count,
            COALESCE(SUM(CASE WHEN f.entry_type = 'file' THEN f.size_bytes ELSE 0 END), 0) AS total_size,
            COALESCE(SUM({$manualSql}), 0) AS manual_count,
            COALESCE(SUM({$ruleSql}), 0) AS rule_count,
            COALESCE(SUM({$manualSql} AND {$ruleSql}), 0) AS both_count
        FROM categories c
        LEFT JOIN file_entries f ON f.is_missing = 0 AND {$visibleSql} AND {$matchedSql}
        WHERE c.is_active = 1
        GROUP BY c.id, c.name
        ORDER BY c.name
    ";
    $coverage = db()->query($coverageSql)->fetchAll();
} catch (Throwable $e) {
    $error = 'Could not load category coverage: ' . $e->getMessage();
}

render_header('Category Coverage');
?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>

<div class="card">
    <h2>Category coverage</h2>
    <p class="muted">Shows category coverage for the current inventory. Heavier review checks are linked to Category Workflow to avoid loading all expensive totals on this dashboard.</p>
</div>

<div class="card">
    <h2>Inventory summary</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Metric</th><th>Items</th><th>Files</th><th>Folders</th><th>File size</th><th>Action</th></tr></thead>
            <tbody>
                <tr><td>Total indexed inventory</td><td><?= e(number_format((int)($inventory['total_items'] ?? 0))) ?></td><td><?= e(number_format((int)($inventory['file_count'] ?? 0))) ?></td><td><?= e(number_format((int)($inventory['folder_count'] ?? 0))) ?></td><td><?= e(format_bytes((int)($inventory['total_size'] ?? 0))) ?></td><td><a class="button" href="category-workflow.php?status=any&entry_type=any">View</a></td></tr>
                <tr><td>Uncategorised</td><td colspan="4" class="muted">Calculated on demand</td><td><a class="button" href="category-workflow.php?status=uncategorised&entry_type=file">Review</a></td></tr>
                <tr><td>Category conflicts</td><td colspan="4" class="muted">Calculated on demand</td><td><a class="button" href="category-workflow.php?status=conflicted&entry_type=any">Review</a></td></tr>
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
