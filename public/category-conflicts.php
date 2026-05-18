<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

const CATEGORY_CONFLICTS_PAGE_SIZE = 990;

function cc_link(array $params = []): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        }
    }
    return 'category-conflicts.php?' . http_build_query($query);
}

function cc_match_sql(): string
{
    return "
        SELECT category_id, category_name, color_hex, file_entry_id, entry_type, size_bytes, MAX(is_manual) AS is_manual, MAX(is_rule) AS is_rule
        FROM (
            SELECT cma.category_id, c.name AS category_name, c.color_hex, f.id AS file_entry_id, f.entry_type, f.size_bytes, 1 AS is_manual, 0 AS is_rule
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
            SELECT cr.category_id, c.name AS category_name, c.color_hex, f.id AS file_entry_id, f.entry_type, f.size_bytes, 0 AS is_manual, 1 AS is_rule
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
        GROUP BY category_id, category_name, color_hex, file_entry_id, entry_type, size_bytes
    ";
}

function cc_colour(string $value): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : '#dbeafe';
}

$driveId = max(0, (int)($_GET['drive_id'] ?? 0));
$categoryId = max(0, (int)($_GET['category_id'] ?? 0));
$source = (string)($_GET['source'] ?? 'both');
if (!in_array($source, ['both', 'manual', 'rules'], true)) {
    $source = 'both';
}
$entryType = (string)($_GET['entry_type'] ?? 'any');
if (!in_array($entryType, ['any', 'file', 'folder'], true)) {
    $entryType = 'any';
}
$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * CATEGORY_CONFLICTS_PAGE_SIZE;
$sort = (string)($_GET['sort'] ?? 'drive');
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$nextDir = $dir === 'asc' ? 'desc' : 'asc';

$categories = db()->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$drives = db()->query('SELECT id, friendly_name FROM drives ORDER BY friendly_name')->fetchAll();
$matchSql = cc_match_sql();

$where = [];
$params = [];
if ($driveId > 0) {
    $where[] = 'f.drive_id = ?';
    $params[] = $driveId;
}
if ($entryType !== 'any') {
    $where[] = 'f.entry_type = ?';
    $params[] = $entryType;
}
if ($search !== '') {
    $where[] = '(f.name LIKE ? OR f.relative_path LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($categoryId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM (' . $matchSql . ') cm WHERE cm.file_entry_id = f.id AND cm.category_id = ?)';
    $params[] = $categoryId;
}
if ($source === 'manual') {
    $where[] = 'EXISTS (SELECT 1 FROM (' . $matchSql . ') sm WHERE sm.file_entry_id = f.id AND sm.is_manual = 1)';
} elseif ($source === 'rules') {
    $where[] = 'EXISTS (SELECT 1 FROM (' . $matchSql . ') sr WHERE sr.file_entry_id = f.id AND sr.is_rule = 1)';
}
$whereSql = $where ? ' AND ' . implode(' AND ', $where) : '';
$sortSql = match ($sort) {
    'name' => 'f.name',
    'type' => 'f.entry_type',
    'path' => 'f.relative_path',
    'size' => 'f.size_bytes',
    'modified' => 'f.modified_at',
    'categories' => 'category_count',
    default => 'd.friendly_name',
};

$baseSql = "
    FROM file_entries f
    JOIN drives d ON d.id = f.drive_id
    JOIN (
        SELECT file_entry_id, COUNT(DISTINCT category_id) AS category_count,
            GROUP_CONCAT(CONCAT(category_id, '|', category_name, '|', color_hex, '|', is_manual, '|', is_rule) ORDER BY category_name SEPARATOR '||') AS category_data
        FROM ({$matchSql}) m
        GROUP BY file_entry_id
        HAVING COUNT(DISTINCT category_id) > 1
    ) conflicts ON conflicts.file_entry_id = f.id
    WHERE f.is_missing = 0 {$whereSql}
";

$countStmt = db()->prepare("SELECT COUNT(*) {$baseSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / CATEGORY_CONFLICTS_PAGE_SIZE));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * CATEGORY_CONFLICTS_PAGE_SIZE;
}

$stmt = db()->prepare("SELECT f.id, f.drive_id, f.relative_path, f.name, f.extension, f.entry_type, f.size_bytes, f.modified_at, d.friendly_name, conflicts.category_count, conflicts.category_data {$baseSql} ORDER BY {$sortSql} {$dir}, f.relative_path ASC LIMIT ? OFFSET ?");
foreach ($params as $index => $value) {
    $stmt->bindValue($index + 1, $value);
}
$stmt->bindValue(count($params) + 1, CATEGORY_CONFLICTS_PAGE_SIZE, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

render_header('Category Conflicts');
?>
<div class="card">
    <h2>Category conflicts</h2>
    <p class="muted">Shows files/folders matching more than one active category through manual assignments and/or enabled rules. These should be reviewed before move planning.</p>
    <form method="get">
        <div class="form-grid compact-search">
            <label>Drive
                <select name="drive_id">
                    <option value="0">All drives</option>
                    <?php foreach ($drives as $drive): ?>
                        <option value="<?= e((string)$drive['id']) ?>" <?= $driveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Category involved
                <select name="category_id">
                    <option value="0">Any category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string)$category['id']) ?>" <?= $categoryId === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Source
                <select name="source">
                    <option value="both" <?= $source === 'both' ? 'selected' : '' ?>>Manual + rules</option>
                    <option value="manual" <?= $source === 'manual' ? 'selected' : '' ?>>Has manual match</option>
                    <option value="rules" <?= $source === 'rules' ? 'selected' : '' ?>>Has rule match</option>
                </select>
            </label>
            <label>Entry type
                <select name="entry_type">
                    <option value="any" <?= $entryType === 'any' ? 'selected' : '' ?>>Files and folders</option>
                    <option value="file" <?= $entryType === 'file' ? 'selected' : '' ?>>Files only</option>
                    <option value="folder" <?= $entryType === 'folder' ? 'selected' : '' ?>>Folders only</option>
                </select>
            </label>
            <label class="wide">Path/name contains <input type="text" name="search" value="<?= e($search) ?>"></label>
        </div>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <input type="hidden" name="dir" value="<?= e($dir) ?>">
        <input type="hidden" name="page" value="1">
        <div class="button-row"><button type="submit">Filter conflicts</button> <a class="button" href="category-conflicts.php">Clear</a></div>
    </form>
</div>

<div class="card">
    <h2>Conflicting items</h2>
    <p class="muted">Showing <?= e(number_format($total === 0 ? 0 : $offset + 1)) ?>-<?= e(number_format(min($offset + count($items), $total))) ?> of <?= e(number_format($total)) ?> conflicts.</p>
    <div class="table-scroll">
        <table class="auto-table compact-table selectable-table">
            <thead><tr>
                <?php foreach (['drive' => 'Drive', 'name' => 'Name', 'type' => 'Type', 'categories' => 'Categories', 'path' => 'Path', 'size' => 'Size', 'modified' => 'Modified', 'actions' => 'Actions'] as $key => $label): ?>
                    <th><?php if ($key === 'actions'): ?><?= e($label) ?><?php else: ?><a href="<?= e(cc_link(['sort' => $key, 'dir' => $sort === $key ? $nextDir : 'asc', 'page' => 1])) ?>"><?= e($label) ?><?= $sort === $key ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a><?php endif; ?></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr class="selectable-row">
                    <td><?= e($item['friendly_name']) ?></td>
                    <td><?= $item['entry_type'] === 'folder' ? '📁' : '📄' ?> <?= e($item['name']) ?></td>
                    <td><?= e($item['entry_type'] === 'folder' ? 'Folder' : strtoupper((string)$item['extension']) . ' File') ?></td>
                    <td>
                        <?php foreach (explode('||', (string)$item['category_data']) as $blob): ?>
                            <?php $parts = explode('|', $blob); if (count($parts) < 5) { continue; } $catId = (int)$parts[0]; $catName = $parts[1]; $catColor = cc_colour($parts[2]); $isManual = (int)$parts[3] === 1; $isRule = (int)$parts[4] === 1; ?>
                            <span class="category-badge" style="background-color: <?= e($catColor) ?>; border-color: <?= e($catColor) ?>" title="<?= e(($isManual ? 'manual' : '') . ($isManual && $isRule ? ' + ' : '') . ($isRule ? 'rule' : '')) ?>"><?= e($catName) ?> <?= e($isManual && $isRule ? '(both)' : ($isManual ? '(manual)' : '(rule)')) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td class="wrap-cell path-cell"><?= e($item['relative_path']) ?></td>
                    <td><?= $item['entry_type'] === 'file' ? e(format_bytes((int)$item['size_bytes'])) : '' ?></td>
                    <td><?= e($item['modified_at']) ?></td>
                    <td class="actions">
                        <a class="button" href="files.php?drive_id=<?= e((string)$item['drive_id']) ?>&path=<?= e(urlencode(dirname((string)$item['relative_path']) === '.' ? '' : dirname((string)$item['relative_path']))) ?>&expanded_drive_id=<?= e((string)$item['drive_id']) ?>&selected_entry_id=<?= e((string)$item['id']) ?>#explorer-panel">Open</a>
                        <a class="button" href="categories.php#rules">Rules</a>
                        <a class="button" href="categories.php#manual">Manual</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?><tr><td colspan="8">No category conflicts found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > CATEGORY_CONFLICTS_PAGE_SIZE): ?>
        <div class="status-bar">Page <?= e(number_format($page)) ?> of <?= e(number_format($totalPages)) ?>. <a class="button" href="<?= e(cc_link(['page' => max(1, $page - 1)])) ?>">Previous</a> <a class="button" href="<?= e(cc_link(['page' => min($totalPages, $page + 1)])) ?>">Next</a></div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
