<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\MovePlanningSafety;

const MOVE_CANDIDATES_PAGE_SIZE = 990;

function mc_link(array $params = []): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        }
    }
    return 'move-candidates.php?' . http_build_query($query);
}

function mc_colour(string $value): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : '#dbeafe';
}

$categoryId = max(0, (int)($_GET['category_id'] ?? 0));
$targetDriveId = max(0, (int)($_GET['target_drive_id'] ?? 0));
$currentDriveId = max(0, (int)($_GET['current_drive_id'] ?? 0));
$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * MOVE_CANDIDATES_PAGE_SIZE;
$sort = (string)($_GET['sort'] ?? 'path');
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$nextDir = $dir === 'asc' ? 'desc' : 'asc';

$categories = db()->query('SELECT id, name, color_hex FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$drives = db()->query('SELECT id, friendly_name, free_bytes, total_bytes FROM drives WHERE is_active = 1 ORDER BY friendly_name')->fetchAll();
$effectiveSql = MovePlanningSafety::effectiveCategorySql();
$activeConflictSql = MovePlanningSafety::activeConflictSql();
$acceptedConflictSql = MovePlanningSafety::acceptedConflictSql();

$where = ['f.is_missing = 0', "f.entry_type = 'file'", 'ec.category_count = 1', 'td.id IS NOT NULL', 'td.is_active = 1', 'ac.file_entry_id IS NULL', 'ic.file_entry_id IS NULL', 'f.drive_id <> td.id'];
$params = [];
if ($categoryId > 0) {
    $where[] = 'ec.category_id = ?';
    $params[] = $categoryId;
}
if ($targetDriveId > 0) {
    $where[] = 'td.id = ?';
    $params[] = $targetDriveId;
}
if ($currentDriveId > 0) {
    $where[] = 'f.drive_id = ?';
    $params[] = $currentDriveId;
}
if ($search !== '') {
    $where[] = '(f.name LIKE ? OR f.relative_path LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);
$sortSql = match ($sort) {
    'drive' => 'current_drive_name',
    'name' => 'f.name',
    'type' => 'f.entry_type',
    'size' => 'f.size_bytes',
    'modified' => 'f.modified_at',
    default => 'f.relative_path',
};

$baseSql = "FROM ({$effectiveSql}) ec JOIN file_entries f ON f.id = ec.file_entry_id JOIN drives d ON d.id = f.drive_id JOIN category_drive_targets cdt ON cdt.category_id = ec.category_id AND cdt.is_enabled = 1 JOIN drives td ON td.id = cdt.drive_id LEFT JOIN ({$activeConflictSql}) ac ON ac.file_entry_id = f.id LEFT JOIN ({$acceptedConflictSql}) ic ON ic.file_entry_id = f.id WHERE {$whereSql}";

$countStmt = db()->prepare("SELECT COUNT(*) {$baseSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / MOVE_CANDIDATES_PAGE_SIZE));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * MOVE_CANDIDATES_PAGE_SIZE;
}

$summaryStmt = db()->prepare("SELECT COALESCE(SUM(f.size_bytes), 0) AS total_size, COUNT(*) AS file_count {$baseSql}");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch() ?: ['total_size' => 0, 'file_count' => 0];

$stmt = db()->prepare("SELECT f.id, f.drive_id, f.relative_path, f.name, f.extension, f.entry_type, f.size_bytes, f.modified_at, d.friendly_name AS current_drive_name, td.id AS target_drive_id, td.friendly_name AS target_drive_name, ec.category_name, ec.color_hex, ec.is_manual, ec.is_rule {$baseSql} ORDER BY {$sortSql} {$dir}, f.relative_path ASC LIMIT ? OFFSET ?");
foreach ($params as $index => $value) {
    $stmt->bindValue($index + 1, $value);
}
$stmt->bindValue(count($params) + 1, MOVE_CANDIDATES_PAGE_SIZE, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stmt->execute();
$candidates = $stmt->fetchAll();

$targetDriveName = '';
foreach ($drives as $drive) {
    if ((int)$drive['id'] === $targetDriveId) {
        $targetDriveName = (string)$drive['friendly_name'];
        break;
    }
}

render_header('Move Candidates');
?>
<div class="card">
    <h2>Safe move candidates</h2>
    <p class="muted">Only files with exactly one effective category, an enabled category target drive, no active or accepted conflict, and not already on the target drive are shown.</p>
    <form method="get">
        <div class="form-grid compact-search">
            <label>Category<select name="category_id"><option value="0">Any safe category</option><?php foreach ($categories as $category): ?><option value="<?= e((string)$category['id']) ?>" <?= $categoryId === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
            <label>Target drive<select name="target_drive_id"><option value="0">Any configured target drive</option><?php foreach ($drives as $drive): ?><option value="<?= e((string)$drive['id']) ?>" <?= $targetDriveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name'] . ' - free ' . format_bytes((int)$drive['free_bytes'])) ?></option><?php endforeach; ?></select></label>
            <label>Current drive<select name="current_drive_id"><option value="0">Any current drive</option><?php foreach ($drives as $drive): ?><option value="<?= e((string)$drive['id']) ?>" <?= $currentDriveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name']) ?></option><?php endforeach; ?></select></label>
            <label class="wide">Path/name contains <input type="text" name="search" value="<?= e($search) ?>"></label>
        </div>
        <input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>"><input type="hidden" name="page" value="1">
        <div class="button-row"><button type="submit">Show safe candidates</button> <a class="button" href="move-candidates.php">Clear</a> <a class="button" href="planner-readiness.php">Readiness</a> <a class="button" href="move-planner.php">Move Planner</a></div>
    </form>
</div>

<div class="card"><h2>Candidate summary</h2><div class="status-bar">Target drive: <?= e($targetDriveName ?: 'Any configured target') ?> | Safe candidates: <?= e(number_format($total)) ?> | Files: <?= e(number_format((int)$summary['file_count'])) ?> | Estimated file data to move: <?= e(format_bytes((int)$summary['total_size'])) ?></div></div>

<div class="card">
    <h2>Candidate list</h2>
    <p class="muted">Showing <?= e(number_format($total === 0 ? 0 : $offset + 1)) ?>-<?= e(number_format(min($offset + count($candidates), $total))) ?> of <?= e(number_format($total)) ?> safe candidates.</p>
    <div class="table-scroll"><table class="auto-table compact-table selectable-table"><thead><tr>
        <?php foreach (['drive' => 'Current drive', 'target' => 'Target drive', 'name' => 'Name', 'type' => 'Type', 'category' => 'Category', 'path' => 'Path', 'size' => 'Size', 'modified' => 'Modified'] as $key => $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
    </tr></thead><tbody>
    <?php foreach ($candidates as $item): ?><?php $color = mc_colour((string)$item['color_hex']); ?><tr class="selectable-row"><td>💽 <?= e($item['current_drive_name']) ?></td><td>💽 <?= e($item['target_drive_name']) ?></td><td>📄 <?= e($item['name']) ?></td><td><?= e(strtoupper((string)$item['extension']) . ' File') ?></td><td><span class="category-badge" style="background-color: <?= e($color) ?>; border-color: <?= e($color) ?>"><?= e($item['category_name']) ?></span></td><td class="wrap-cell path-cell"><?= e($item['relative_path']) ?></td><td><?= e(format_bytes((int)$item['size_bytes'])) ?></td><td><?= e($item['modified_at']) ?></td></tr><?php endforeach; ?>
    <?php if (!$candidates): ?><tr><td colspan="8">No safe move candidates found.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($total > MOVE_CANDIDATES_PAGE_SIZE): ?><div class="status-bar">Page <?= e(number_format($page)) ?> of <?= e(number_format($totalPages)) ?>. <a class="button" href="<?= e(mc_link(['page' => max(1, $page - 1)])) ?>">Previous</a> <a class="button" href="<?= e(mc_link(['page' => min($totalPages, $page + 1)])) ?>">Next</a></div><?php endif; ?>
</div>
<?php render_footer(); ?>
