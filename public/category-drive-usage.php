<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

function cdu_match_sql(): string
{
    return "
        SELECT category_id, category_name, color_hex, file_entry_id, drive_id, entry_type, size_bytes, MAX(is_manual) AS is_manual, MAX(is_rule) AS is_rule
        FROM (
            SELECT cma.category_id, c.name AS category_name, c.color_hex, f.id AS file_entry_id, f.drive_id, f.entry_type, f.size_bytes, 1 AS is_manual, 0 AS is_rule
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
            SELECT cr.category_id, c.name AS category_name, c.color_hex, f.id AS file_entry_id, f.drive_id, f.entry_type, f.size_bytes, 0 AS is_manual, 1 AS is_rule
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
        GROUP BY category_id, category_name, color_hex, file_entry_id, drive_id, entry_type, size_bytes
    ";
}

function cdu_colour(string $value): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : '#dbeafe';
}

$categoryId = max(0, (int)($_GET['category_id'] ?? 0));
$driveId = max(0, (int)($_GET['drive_id'] ?? 0));
$source = (string)($_GET['source'] ?? 'both');
if (!in_array($source, ['both', 'manual', 'rules'], true)) {
    $source = 'both';
}
$includeAccepted = isset($_GET['include_accepted']) ? 1 : 0;

$categories = db()->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$drives = db()->query('SELECT id, friendly_name FROM drives WHERE is_active = 1 ORDER BY friendly_name')->fetchAll();
$matchSql = cdu_match_sql();

$where = ['1=1'];
$params = [];
if ($categoryId > 0) {
    $where[] = 'm.category_id = ?';
    $params[] = $categoryId;
}
if ($driveId > 0) {
    $where[] = 'm.drive_id = ?';
    $params[] = $driveId;
}
if ($source === 'manual') {
    $where[] = 'm.is_manual = 1';
} elseif ($source === 'rules') {
    $where[] = 'm.is_rule = 1';
}
$whereSql = implode(' AND ', $where);

$activeConflictSql = "
    SELECT x.file_entry_id
    FROM (
        SELECT file_entry_id
        FROM ({$matchSql}) cm
        GROUP BY file_entry_id
        HAVING COUNT(DISTINCT category_id) > 1
    ) x
    LEFT JOIN category_conflict_ignores cci ON cci.file_entry_id = x.file_entry_id
    WHERE cci.id IS NULL
";

$acceptedConflictSql = "
    SELECT x.file_entry_id
    FROM (
        SELECT file_entry_id
        FROM ({$matchSql}) cm
        GROUP BY file_entry_id
        HAVING COUNT(DISTINCT category_id) > 1
    ) x
    JOIN category_conflict_ignores cci ON cci.file_entry_id = x.file_entry_id
";

$usageSql = "
    SELECT m.category_id, m.category_name, m.color_hex, d.id AS drive_id, d.friendly_name,
        COUNT(m.file_entry_id) AS item_count,
        COALESCE(SUM(m.entry_type = 'file'), 0) AS file_count,
        COALESCE(SUM(m.entry_type = 'folder'), 0) AS folder_count,
        COALESCE(SUM(CASE WHEN m.entry_type = 'file' THEN m.size_bytes ELSE 0 END), 0) AS total_size,
        COALESCE(SUM(m.is_manual = 1), 0) AS manual_count,
        COALESCE(SUM(m.is_rule = 1), 0) AS rule_count,
        COALESCE(SUM(m.is_manual = 1 AND m.is_rule = 1), 0) AS both_count,
        COALESCE(SUM(CASE WHEN ac.file_entry_id IS NOT NULL AND m.entry_type = 'file' THEN m.size_bytes ELSE 0 END), 0) AS active_conflict_size,
        COALESCE(SUM(CASE WHEN ic.file_entry_id IS NOT NULL AND m.entry_type = 'file' THEN m.size_bytes ELSE 0 END), 0) AS accepted_conflict_size,
        COALESCE(SUM(CASE WHEN ac.file_entry_id IS NULL AND (ic.file_entry_id IS NULL OR ? = 1) AND m.entry_type = 'file' THEN m.size_bytes ELSE 0 END), 0) AS planner_eligible_size
    FROM ({$matchSql}) m
    JOIN drives d ON d.id = m.drive_id
    LEFT JOIN ({$activeConflictSql}) ac ON ac.file_entry_id = m.file_entry_id
    LEFT JOIN ({$acceptedConflictSql}) ic ON ic.file_entry_id = m.file_entry_id
    WHERE {$whereSql}
    GROUP BY m.category_id, m.category_name, m.color_hex, d.id, d.friendly_name
    ORDER BY m.category_name, d.friendly_name
";
$stmt = db()->prepare($usageSql);
$stmt->bindValue(1, $includeAccepted, PDO::PARAM_INT);
foreach ($params as $index => $value) {
    $stmt->bindValue($index + 2, $value);
}
$stmt->execute();
$usageRows = $stmt->fetchAll();

$uncatWhere = ['f.is_missing = 0'];
$uncatParams = [];
if ($driveId > 0) {
    $uncatWhere[] = 'f.drive_id = ?';
    $uncatParams[] = $driveId;
}
$uncatWhereSql = implode(' AND ', $uncatWhere);
$uncategorisedSql = "
    SELECT d.id AS drive_id, d.friendly_name,
        COUNT(f.id) AS item_count,
        COALESCE(SUM(f.entry_type = 'file'), 0) AS file_count,
        COALESCE(SUM(f.entry_type = 'folder'), 0) AS folder_count,
        COALESCE(SUM(CASE WHEN f.entry_type = 'file' THEN f.size_bytes ELSE 0 END), 0) AS total_size
    FROM file_entries f
    JOIN drives d ON d.id = f.drive_id
    WHERE {$uncatWhereSql}
    AND NOT EXISTS (SELECT 1 FROM ({$matchSql}) m WHERE m.file_entry_id = f.id)
    GROUP BY d.id, d.friendly_name
    ORDER BY d.friendly_name
";
$stmt = db()->prepare($uncategorisedSql);
$stmt->execute($uncatParams);
$uncategorisedRows = $stmt->fetchAll();

render_header('Category Drive Usage');
?>
<div class="card">
    <h2>Category drive usage</h2>
    <p class="muted">Shows where category data currently lives by drive. Active conflicts are excluded from planner-eligible size to avoid double-counting and future move loops.</p>
    <form method="get">
        <div class="form-grid compact-search">
            <label>Category
                <select name="category_id">
                    <option value="0">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string)$category['id']) ?>" <?= $categoryId === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Drive
                <select name="drive_id">
                    <option value="0">All drives</option>
                    <?php foreach ($drives as $drive): ?>
                        <option value="<?= e((string)$drive['id']) ?>" <?= $driveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Source
                <select name="source">
                    <option value="both" <?= $source === 'both' ? 'selected' : '' ?>>Manual + rules</option>
                    <option value="manual" <?= $source === 'manual' ? 'selected' : '' ?>>Manual only</option>
                    <option value="rules" <?= $source === 'rules' ? 'selected' : '' ?>>Rules only</option>
                </select>
            </label>
            <label>Planner size
                <span><input type="checkbox" name="include_accepted" value="1" <?= $includeAccepted ? 'checked' : '' ?>> Include accepted conflicts</span>
            </label>
        </div>
        <div class="button-row"><button type="submit">Filter</button> <a class="button" href="category-drive-usage.php">Clear</a></div>
    </form>
</div>

<div class="card">
    <h2>Usage by category and drive</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Category</th><th>Drive</th><th>Items</th><th>Files</th><th>Folders</th><th>Total file size</th><th>Manual</th><th>Rule</th><th>Both</th><th>Active conflict size</th><th>Accepted conflict size</th><th>Planner eligible</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($usageRows as $row): ?>
                <?php $color = cdu_colour((string)$row['color_hex']); ?>
                <tr>
                    <td><span class="category-badge" style="background-color: <?= e($color) ?>; border-color: <?= e($color) ?>"><?= e($row['category_name']) ?></span></td>
                    <td>💽 <?= e($row['friendly_name']) ?></td>
                    <td><?= e(number_format((int)$row['item_count'])) ?></td>
                    <td><?= e(number_format((int)$row['file_count'])) ?></td>
                    <td><?= e(number_format((int)$row['folder_count'])) ?></td>
                    <td><?= e(format_bytes((int)$row['total_size'])) ?></td>
                    <td><?= e(number_format((int)$row['manual_count'])) ?></td>
                    <td><?= e(number_format((int)$row['rule_count'])) ?></td>
                    <td><?= e(number_format((int)$row['both_count'])) ?></td>
                    <td><?= e(format_bytes((int)$row['active_conflict_size'])) ?></td>
                    <td><?= e(format_bytes((int)$row['accepted_conflict_size'])) ?></td>
                    <td><strong><?= e(format_bytes((int)$row['planner_eligible_size'])) ?></strong></td>
                    <td class="actions"><a class="button" href="category-preview.php?view_mode=category&category_id=<?= e((string)$row['category_id']) ?>&drive_id=<?= e((string)$row['drive_id']) ?>&source=<?= e($source) ?>">Preview</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$usageRows): ?><tr><td colspan="13">No category usage found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Uncategorised by drive</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Drive</th><th>Items</th><th>Files</th><th>Folders</th><th>Total file size</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($uncategorisedRows as $row): ?>
                <tr>
                    <td>💽 <?= e($row['friendly_name']) ?></td>
                    <td><?= e(number_format((int)$row['item_count'])) ?></td>
                    <td><?= e(number_format((int)$row['file_count'])) ?></td>
                    <td><?= e(number_format((int)$row['folder_count'])) ?></td>
                    <td><?= e(format_bytes((int)$row['total_size'])) ?></td>
                    <td><a class="button" href="category-preview.php?view_mode=uncategorised&drive_id=<?= e((string)$row['drive_id']) ?>&source=both">Review</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$uncategorisedRows): ?><tr><td colspan="6">No uncategorised inventory found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
