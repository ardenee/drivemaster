<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\IgnoredFolderSql;

$error = null;
$inventory = [];
$categories = [];
$visibleSql = IgnoredFolderSql::excludeCondition('f');

try {
    $inventory = db()->query("SELECT COUNT(*) AS total_items, SUM(f.entry_type = 'file') AS file_count, SUM(f.entry_type = 'folder') AS folder_count, COALESCE(SUM(CASE WHEN f.entry_type = 'file' THEN f.size_bytes ELSE 0 END), 0) AS total_size FROM file_entries f WHERE f.is_missing = 0 AND {$visibleSql}")->fetch() ?: [];

    $categoriesSql = "
        SELECT c.id, c.name,
            COALESCE(ma.manual_assignments, 0) AS manual_assignments,
            COALESCE(fr.file_assignments, 0) AS file_assignments,
            COALESCE(fd.folder_assignments, 0) AS folder_assignments,
            COALESCE(r.enabled_rules, 0) AS enabled_rules,
            COALESCE(r.disabled_rules, 0) AS disabled_rules
        FROM categories c
        LEFT JOIN (
            SELECT category_id, COUNT(*) AS manual_assignments
            FROM category_manual_assignments
            GROUP BY category_id
        ) ma ON ma.category_id = c.id
        LEFT JOIN (
            SELECT category_id, COUNT(*) AS file_assignments
            FROM category_manual_assignments
            WHERE assignment_type = 'file'
            GROUP BY category_id
        ) fr ON fr.category_id = c.id
        LEFT JOIN (
            SELECT category_id, COUNT(*) AS folder_assignments
            FROM category_manual_assignments
            WHERE assignment_type = 'folder'
            GROUP BY category_id
        ) fd ON fd.category_id = c.id
        LEFT JOIN (
            SELECT category_id,
                SUM(is_enabled = 1) AS enabled_rules,
                SUM(is_enabled = 0) AS disabled_rules
            FROM category_rules
            GROUP BY category_id
        ) r ON r.category_id = c.id
        WHERE c.is_active = 1
        ORDER BY c.name
    ";
    $categories = db()->query($categoriesSql)->fetchAll();
} catch (Throwable $e) {
    $error = 'Could not load category coverage dashboard: ' . $e->getMessage();
}

render_header('Category Coverage');
?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>

<div class="card">
    <h2>Category coverage</h2>
    <p class="muted">Fast dashboard view. Live coverage totals are calculated on demand from the action links so this page does not scan the whole inventory for every category during load.</p>
</div>

<div class="card">
    <h2>Inventory summary</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Metric</th><th>Items</th><th>Files</th><th>Folders</th><th>File size</th><th>Action</th></tr></thead>
            <tbody>
                <tr><td>Total indexed inventory</td><td><?= e(number_format((int)($inventory['total_items'] ?? 0))) ?></td><td><?= e(number_format((int)($inventory['file_count'] ?? 0))) ?></td><td><?= e(number_format((int)($inventory['folder_count'] ?? 0))) ?></td><td><?= e(format_bytes((int)($inventory['total_size'] ?? 0))) ?></td><td><a class="button" href="category-status.php?mode=assigned">View categorised</a></td></tr>
                <tr><td>Uncategorised files</td><td colspan="4" class="muted">Calculated on demand</td><td><a class="button" href="category-status.php?mode=unassigned">Review</a></td></tr>
                <tr><td>Uncategorised folders</td><td colspan="4" class="muted">Calculated on demand</td><td><a class="button" href="category-status.php?mode=unassigned">Review</a></td></tr>
                <tr><td>Category conflicts</td><td colspan="4" class="muted">Calculated on demand</td><td><a class="button" href="category-conflicts.php">Review</a></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Category setup summary</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Category</th><th>Manual assignments</th><th>File assignments</th><th>Folder assignments</th><th>Enabled rules</th><th>Disabled rules</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($categories as $row): ?>
                <tr>
                    <td><?= e($row['name']) ?></td>
                    <td><?= e(number_format((int)$row['manual_assignments'])) ?></td>
                    <td><?= e(number_format((int)$row['file_assignments'])) ?></td>
                    <td><?= e(number_format((int)$row['folder_assignments'])) ?></td>
                    <td><?= e(number_format((int)$row['enabled_rules'])) ?></td>
                    <td><?= e(number_format((int)$row['disabled_rules'])) ?></td>
                    <td class="actions">
                        <a class="button" href="category-preview.php?view_mode=category&category_id=<?= e((string)$row['id']) ?>&source=both">Preview</a>
                        <a class="button" href="category-preview.php?view_mode=category&category_id=<?= e((string)$row['id']) ?>&source=manual">Manual</a>
                        <a class="button" href="category-preview.php?view_mode=category&category_id=<?= e((string)$row['id']) ?>&source=rules">Rules</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$categories): ?><tr><td colspan="7">No active categories found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
