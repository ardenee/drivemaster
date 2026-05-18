<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

const MOVE_PLAN_VIEW_PAGE_SIZE = 990;

function mpv_json(?string $json): array
{
    if (!$json) {
        return [];
    }
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (Throwable) {
        return [];
    }
}

function mpv_link(array $params = []): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        }
    }
    return 'move-plan-view.php?' . http_build_query($query);
}

function mpv_option_flags(array $options): string
{
    $flags = [];
    foreach (['use_category_targets' => 'Category targets', 'allow_spanning' => 'Spanning', 'include_accepted' => 'Accepted conflicts', 'include_uncategorised' => 'Uncategorised'] as $key => $label) {
        if (!empty($options[$key])) {
            $flags[] = $label;
        }
    }
    return $flags ? implode(', ', $flags) : 'Default options';
}

$id = max(0, (int)($_GET['id'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * MOVE_PLAN_VIEW_PAGE_SIZE;
$sort = (string)($_GET['sort'] ?? 'path');
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$nextDir = $dir === 'asc' ? 'desc' : 'asc';

$plan = null;
if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM move_plans WHERE id = ?');
    $stmt->execute([$id]);
    $plan = $stmt->fetch() ?: null;
}

render_header('Move Plan View');
?>
<?php if (!$plan): ?>
<div class="card">
    <h2>Move plan not found</h2>
    <p>The requested saved plan snapshot could not be found.</p>
    <div class="button-row"><a class="button" href="move-plans.php">Saved Plans</a> <a class="button" href="move-planner.php">Move Planner</a></div>
</div>
<?php render_footer(); return; ?>
<?php endif; ?>
<?php
$options = mpv_json($plan['options_json'] ?? null);
$warnings = mpv_json($plan['warning_summary_json'] ?? null);
$blockers = mpv_json($plan['blocker_summary_json'] ?? null);
$selectedCategoryIds = mpv_json($plan['selected_category_ids_json'] ?? null);
$selectedDriveIds = mpv_json($plan['selected_drive_ids_json'] ?? null);
$sortSql = match ($sort) {
    'category' => 'c.name',
    'source' => 'source_drive_name',
    'target' => 'target_drive_name',
    'size' => 'mpi.size_bytes',
    'status' => 'mpi.status',
    default => 'mpi.relative_path',
};

$countStmt = db()->prepare('SELECT COUNT(*) FROM move_plan_items WHERE move_plan_id = ?');
$countStmt->execute([$id]);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / MOVE_PLAN_VIEW_PAGE_SIZE));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * MOVE_PLAN_VIEW_PAGE_SIZE;
}

$itemStmt = db()->prepare("SELECT mpi.*, c.name AS category_name, c.color_hex, sd.friendly_name AS source_drive_name, td.friendly_name AS target_drive_name FROM move_plan_items mpi JOIN categories c ON c.id = mpi.category_id JOIN drives sd ON sd.id = mpi.source_drive_id JOIN drives td ON td.id = mpi.target_drive_id WHERE mpi.move_plan_id = ? ORDER BY {$sortSql} {$dir}, mpi.relative_path ASC LIMIT ? OFFSET ?");
$itemStmt->bindValue(1, $id, PDO::PARAM_INT);
$itemStmt->bindValue(2, MOVE_PLAN_VIEW_PAGE_SIZE, PDO::PARAM_INT);
$itemStmt->bindValue(3, $offset, PDO::PARAM_INT);
$itemStmt->execute();
$items = $itemStmt->fetchAll();

$driveStmt = db()->prepare('SELECT td.id, td.friendly_name, COUNT(*) AS item_count, COALESCE(SUM(mpi.size_bytes), 0) AS total_size FROM move_plan_items mpi JOIN drives td ON td.id = mpi.target_drive_id WHERE mpi.move_plan_id = ? GROUP BY td.id, td.friendly_name ORDER BY total_size DESC, td.friendly_name');
$driveStmt->execute([$id]);
$targetSummary = $driveStmt->fetchAll();

$categoryStmt = db()->prepare('SELECT c.id, c.name, c.color_hex, COUNT(*) AS item_count, COALESCE(SUM(mpi.size_bytes), 0) AS total_size FROM move_plan_items mpi JOIN categories c ON c.id = mpi.category_id WHERE mpi.move_plan_id = ? GROUP BY c.id, c.name, c.color_hex ORDER BY total_size DESC, c.name');
$categoryStmt->execute([$id]);
$categorySummary = $categoryStmt->fetchAll();
?>

<div class="card">
    <h2>Saved move plan #<?= e((string)$plan['id']) ?></h2>
    <p class="muted">Read-only snapshot. This page does not validate, queue jobs, or move files.</p>
    <div class="status-bar">Status: <?= e($plan['status']) ?> | Created: <?= e($plan['created_at']) ?> | Updated: <?= e($plan['updated_at']) ?> | Candidates: <?= e(number_format((int)$plan['candidate_count'])) ?> | Total size: <?= e(format_bytes((int)$plan['total_size_bytes'])) ?></div>
    <div class="status-bar">Selected categories: <?= e(number_format(count($selectedCategoryIds))) ?> | Selected target drives: <?= e(number_format(count($selectedDriveIds))) ?> | Options: <?= e(mpv_option_flags($options)) ?></div>
    <div class="button-row"><a class="button" href="move-plans.php">Saved Plans</a> <a class="button" href="move-planner.php">Move Planner</a></div>
</div>

<div class="card">
    <h2>Warnings and blockers</h2>
    <div class="form-grid">
        <div>
            <h3>Warnings</h3>
            <?php if ($warnings): ?>
                <ul><?php foreach ($warnings as $warning): ?><li><?= e(is_scalar($warning) ? (string)$warning : json_encode($warning, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></li><?php endforeach; ?></ul>
            <?php else: ?>
                <p>No warnings stored.</p>
            <?php endif; ?>
        </div>
        <div>
            <h3>Blockers</h3>
            <?php if ($blockers): ?>
                <ul><?php foreach ($blockers as $blocker): ?><li><?= e(is_scalar($blocker) ? (string)$blocker : json_encode($blocker, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></li><?php endforeach; ?></ul>
            <?php else: ?>
                <p>No blockers stored.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <h2>Summary by category</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Category</th><th>Candidate files</th><th>Total size</th></tr></thead>
            <tbody>
            <?php foreach ($categorySummary as $row): ?>
                <?php $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$row['color_hex']) ? strtolower((string)$row['color_hex']) : '#dbeafe'; ?>
                <tr>
                    <td><span class="category-badge" style="background-color: <?= e($color) ?>; border-color: <?= e($color) ?>"><?= e($row['name']) ?></span></td>
                    <td><?= e(number_format((int)$row['item_count'])) ?></td>
                    <td><?= e(format_bytes((int)$row['total_size'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$categorySummary): ?><tr><td colspan="3">No saved candidate items.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Summary by target drive</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Target drive</th><th>Candidate files</th><th>Total size</th></tr></thead>
            <tbody>
            <?php foreach ($targetSummary as $row): ?>
                <tr><td>💽 <?= e($row['friendly_name']) ?></td><td><?= e(number_format((int)$row['item_count'])) ?></td><td><?= e(format_bytes((int)$row['total_size'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$targetSummary): ?><tr><td colspan="3">No target drive summary.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Saved candidate items</h2>
    <p class="muted">Showing <?= e(number_format($total === 0 ? 0 : $offset + 1)) ?>-<?= e(number_format(min($offset + count($items), $total))) ?> of <?= e(number_format($total)) ?> saved candidates.</p>
    <div class="table-scroll">
        <table class="auto-table compact-table selectable-table">
            <thead><tr>
                <?php foreach (['category' => 'Category', 'source' => 'Source drive', 'target' => 'Target drive', 'path' => 'Path', 'size' => 'Size', 'status' => 'Status'] as $key => $label): ?>
                    <th><a href="<?= e(mpv_link(['sort' => $key, 'dir' => $sort === $key ? $nextDir : 'asc', 'page' => 1])) ?>"><?= e($label) ?><?= $sort === $key ? e($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <?php $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$item['color_hex']) ? strtolower((string)$item['color_hex']) : '#dbeafe'; ?>
                <tr class="selectable-row">
                    <td><span class="category-badge" style="background-color: <?= e($color) ?>; border-color: <?= e($color) ?>"><?= e($item['category_name']) ?></span></td>
                    <td>💽 <?= e($item['source_drive_name']) ?></td>
                    <td>💽 <?= e($item['target_drive_name']) ?></td>
                    <td class="wrap-cell path-cell"><?= e($item['relative_path']) ?></td>
                    <td><?= e(format_bytes((int)$item['size_bytes'])) ?></td>
                    <td><?= e($item['status']) ?><?= $item['reason'] ? ': ' . e($item['reason']) : '' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?><tr><td colspan="6">No saved candidate items.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > MOVE_PLAN_VIEW_PAGE_SIZE): ?>
        <div class="status-bar">Page <?= e(number_format($page)) ?> of <?= e(number_format($totalPages)) ?>. <a class="button" href="<?= e(mpv_link(['page' => max(1, $page - 1)])) ?>">Previous</a> <a class="button" href="<?= e(mpv_link(['page' => min($totalPages, $page + 1)])) ?>">Next</a></div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
