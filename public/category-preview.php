<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

const CATEGORY_PREVIEW_PAGE_SIZE = 495;

function cp_link(array $params = []): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        }
    }
    return 'category-preview.php?' . http_build_query($query);
}

function cp_manual_exists_sql(string $alias): string
{
    return "EXISTS (SELECT 1 FROM category_manual_assignments cma WHERE cma.category_id = ? AND (cma.file_entry_id = {$alias}.id OR (cma.assignment_type = 'folder' AND cma.drive_id = {$alias}.drive_id AND cma.path_prefix IS NOT NULL AND ({$alias}.relative_path = cma.path_prefix OR {$alias}.relative_path LIKE CONCAT(cma.path_prefix, '/%')))))";
}

function cp_rule_exists_sql(string $alias): string
{
    return "EXISTS (SELECT 1 FROM category_rules cr WHERE cr.category_id = ? AND cr.is_enabled = 1 AND (cr.drive_id IS NULL OR cr.drive_id = {$alias}.drive_id) AND ((cr.rule_type = 'extension' AND cr.pattern = {$alias}.extension) OR (cr.rule_type = 'folder_path' AND ({$alias}.relative_path = cr.pattern OR {$alias}.relative_path LIKE CONCAT(cr.pattern, '/%'))) OR (cr.rule_type = 'path_contains' AND {$alias}.relative_path LIKE CONCAT('%', cr.pattern, '%')) OR (cr.rule_type = 'name_contains' AND {$alias}.name LIKE CONCAT('%', cr.pattern, '%'))))";
}

function cp_category_filter_sql(string $alias, string $source): string
{
    return match ($source) {
        'manual' => cp_manual_exists_sql($alias),
        'rules' => cp_rule_exists_sql($alias),
        default => '(' . cp_manual_exists_sql($alias) . ' OR ' . cp_rule_exists_sql($alias) . ')',
    };
}

function cp_category_filter_param_count(string $source): int
{
    return $source === 'both' ? 2 : 1;
}

function cp_sources_for_entry(array $entry, int $categoryId): string
{
    $sources = [];

    $manual = db()->prepare(cp_manual_exists_sql('f'));
    $manual->execute([$categoryId, $entry['id']]);

    $manualSql = "SELECT 1 FROM file_entries f WHERE f.id = ? AND " . cp_manual_exists_sql('f');
    $stmt = db()->prepare($manualSql);
    $stmt->execute([$entry['id'], $categoryId]);
    if ($stmt->fetchColumn()) {
        $sources[] = '<span class="category-badge category-manual">manual</span>';
    }

    $ruleSql = "SELECT 1 FROM file_entries f WHERE f.id = ? AND " . cp_rule_exists_sql('f');
    $stmt = db()->prepare($ruleSql);
    $stmt->execute([$entry['id'], $categoryId]);
    if ($stmt->fetchColumn()) {
        $sources[] = '<span class="category-badge category-rule">rule</span>';
    }

    return implode(' ', $sources);
}

$categoryId = max(0, (int)($_GET['category_id'] ?? 0));
$driveId = max(0, (int)($_GET['drive_id'] ?? 0));
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
$offset = ($page - 1) * CATEGORY_PREVIEW_PAGE_SIZE;
$sort = (string)($_GET['sort'] ?? 'drive');
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$nextDir = $dir === 'asc' ? 'desc' : 'asc';

$categories = db()->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$drives = db()->query('SELECT id, friendly_name FROM drives ORDER BY friendly_name')->fetchAll();

$where = ['f.is_missing = 0'];
$params = [];
if ($categoryId > 0) {
    $where[] = cp_category_filter_sql('f', $source);
    for ($i = 0; $i < cp_category_filter_param_count($source); $i++) {
        $params[] = $categoryId;
    }
}
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
$whereSql = implode(' AND ', $where);
$sortSql = match ($sort) {
    'name' => 'f.name',
    'type' => 'f.entry_type',
    'path' => 'f.relative_path',
    'size' => 'f.size_bytes',
    'modified' => 'f.modified_at',
    default => 'd.friendly_name',
};

$countStmt = db()->prepare("SELECT COUNT(*) FROM file_entries f JOIN drives d ON d.id = f.drive_id WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / CATEGORY_PREVIEW_PAGE_SIZE));

$stmt = db()->prepare("SELECT f.id, f.drive_id, f.relative_path, f.name, f.extension, f.entry_type, f.size_bytes, f.modified_at, d.friendly_name FROM file_entries f JOIN drives d ON d.id = f.drive_id WHERE {$whereSql} ORDER BY {$sortSql} {$dir}, f.relative_path ASC LIMIT ? OFFSET ?");
foreach ($params as $index => $value) {
    $stmt->bindValue($index + 1, $value);
}
$stmt->bindValue(count($params) + 1, CATEGORY_PREVIEW_PAGE_SIZE, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

render_header('Category Preview');
?>
<div class="card">
    <h2>Category preview</h2>
    <p class="muted">Preview effective category membership before any future move/reorganise planning. Rules and manual assignments remain separate; this page only shows what currently matches.</p>
    <form method="get">
        <div class="form-grid compact-search">
            <label>Category
                <select name="category_id">
                    <option value="0">All uncategorised/category-filter off</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string)$category['id']) ?>" <?= $categoryId === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
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
            <label>Drive
                <select name="drive_id">
                    <option value="0">All drives</option>
                    <?php foreach ($drives as $drive): ?>
                        <option value="<?= e((string)$drive['id']) ?>" <?= $driveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name']) ?></option>
                    <?php endforeach; ?>
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
        <div class="button-row"><button type="submit">Preview</button> <a class="button" href="category-preview.php">Clear</a></div>
    </form>
</div>

<div class="card">
    <h2>Matched inventory</h2>
    <p class="muted">Showing <?= e(number_format($total === 0 ? 0 : $offset + 1)) ?>-<?= e(number_format(min($offset + count($items), $total))) ?> of <?= e(number_format($total)) ?> matching items.</p>
    <div class="table-scroll">
        <table class="auto-table compact-table selectable-table">
            <thead><tr>
                <?php foreach (['drive' => 'Drive', 'name' => 'Name', 'type' => 'Type', 'source' => 'Source', 'path' => 'Path', 'size' => 'Size', 'modified' => 'Modified'] as $key => $label): ?>
                    <th><?php if ($key === 'source'): ?><?= e($label) ?><?php else: ?><a href="<?= e(cp_link(['sort' => $key, 'dir' => $sort === $key ? $nextDir : 'asc', 'page' => 1])) ?>"><?= e($label) ?><?= $sort === $key ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a><?php endif; ?></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr class="selectable-row">
                    <td><?= e($item['friendly_name']) ?></td>
                    <td><?= $item['entry_type'] === 'folder' ? '📁' : '📄' ?> <?= e($item['name']) ?></td>
                    <td><?= e($item['entry_type'] === 'folder' ? 'Folder' : strtoupper((string)$item['extension']) . ' File') ?></td>
                    <td><?= $categoryId > 0 ? cp_sources_for_entry($item, $categoryId) : '' ?></td>
                    <td class="wrap-cell path-cell"><?= e($item['relative_path']) ?></td>
                    <td><?= $item['entry_type'] === 'file' ? e(format_bytes((int)$item['size_bytes'])) : '' ?></td>
                    <td><?= e($item['modified_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?><tr><td colspan="7">No matching inventory found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > CATEGORY_PREVIEW_PAGE_SIZE): ?>
        <div class="status-bar">Page <?= e(number_format($page)) ?> of <?= e(number_format($totalPages)) ?>. <a class="button" href="<?= e(cp_link(['page' => max(1, $page - 1)])) ?>">Previous</a> <a class="button" href="<?= e(cp_link(['page' => min($totalPages, $page + 1)])) ?>">Next</a></div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
