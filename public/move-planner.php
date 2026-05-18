<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

function mp_match_sql(): string
{
    return "
        SELECT category_id, category_name, color_hex, file_entry_id, drive_id, entry_type, size_bytes, MAX(is_manual) AS is_manual, MAX(is_rule) AS is_rule
        FROM (
            SELECT cma.category_id, c.name AS category_name, c.color_hex, f.id AS file_entry_id, f.drive_id, f.entry_type, f.size_bytes, 1 AS is_manual, 0 AS is_rule
            FROM category_manual_assignments cma
            JOIN categories c ON c.id = cma.category_id AND c.is_active = 1
            JOIN file_entries f ON f.is_missing = 0
                AND (cma.file_entry_id = f.id OR (cma.assignment_type = 'folder' AND cma.drive_id = f.drive_id AND cma.path_prefix IS NOT NULL AND (f.relative_path = cma.path_prefix OR f.relative_path LIKE CONCAT(cma.path_prefix, '/%'))))
            UNION ALL
            SELECT cr.category_id, c.name AS category_name, c.color_hex, f.id AS file_entry_id, f.drive_id, f.entry_type, f.size_bytes, 0 AS is_manual, 1 AS is_rule
            FROM category_rules cr
            JOIN categories c ON c.id = cr.category_id AND c.is_active = 1
            JOIN file_entries f ON f.is_missing = 0
                AND cr.is_enabled = 1
                AND (cr.drive_id IS NULL OR cr.drive_id = f.drive_id)
                AND ((cr.rule_type = 'extension' AND cr.pattern = f.extension) OR (cr.rule_type = 'folder_path' AND (f.relative_path = cr.pattern OR f.relative_path LIKE CONCAT(cr.pattern, '/%'))) OR (cr.rule_type = 'path_contains' AND f.relative_path LIKE CONCAT('%', cr.pattern, '%')) OR (cr.rule_type = 'name_contains' AND f.name LIKE CONCAT('%', cr.pattern, '%')))
        ) raw_matches
        GROUP BY category_id, category_name, color_hex, file_entry_id, drive_id, entry_type, size_bytes
    ";
}

function mp_colour(string $value): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : '#dbeafe';
}

function mp_selected_ids(string $key): array
{
    $raw = $_GET[$key] ?? [];
    if (!is_array($raw)) {
        $raw = [$raw];
    }
    return array_values(array_unique(array_filter(array_map('intval', $raw), static fn(int $id): bool => $id > 0)));
}

function mp_drive_summary(array $driveRows): string
{
    $parts = [];
    foreach ($driveRows as $row) {
        $parts[] = $row['drive_name'] . ': ' . format_bytes((int)$row['planner_size']);
    }
    return $parts ? implode(', ', $parts) : 'None';
}

function mp_target_drive_ids(array $targets): array
{
    return array_values(array_unique(array_map(static fn(array $target): int => (int)$target['drive']['id'], $targets)));
}

function mp_already_on_targets(array $currentDriveRows, array $targetDriveIds): int
{
    $targetSet = array_fill_keys($targetDriveIds, true);
    $size = 0;
    foreach ($currentDriveRows as $row) {
        if (isset($targetSet[(int)$row['drive_id']])) {
            $size += (int)$row['planner_size'];
        }
    }
    return $size;
}

$selectedCategoryIds = mp_selected_ids('category_ids');
$selectedDriveIds = mp_selected_ids('drive_ids');
$allowSpanning = isset($_GET['allow_spanning']);
$includeAccepted = isset($_GET['include_accepted']);
$includeUncategorised = isset($_GET['include_uncategorised']);
$useCategoryTargets = isset($_GET['use_category_targets']);
$runPlanner = isset($_GET['plan']);

$categories = db()->query('SELECT id, name, color_hex FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$drives = db()->query('SELECT id, friendly_name, form_factor, free_bytes, total_bytes, serial_number, identity_source FROM drives WHERE is_active = 1 ORDER BY friendly_name')->fetchAll();

if (!$selectedCategoryIds) {
    $selectedCategoryIds = array_map(static fn(array $row): int => (int)$row['id'], $categories);
}
if (!$selectedDriveIds) {
    $selectedDriveIds = array_map(static fn(array $row): int => (int)$row['id'], $drives);
}

$selectedDrives = array_values(array_filter($drives, static fn(array $drive): bool => in_array((int)$drive['id'], $selectedDriveIds, true)));
$selectedCategories = array_values(array_filter($categories, static fn(array $category): bool => in_array((int)$category['id'], $selectedCategoryIds, true)));
$selectedDriveIdSet = array_fill_keys($selectedDriveIds, true);

$categoryTargets = [];
if ($selectedCategoryIds) {
    $targetPlaceholders = implode(',', array_fill(0, count($selectedCategoryIds), '?'));
    $targetStmt = db()->prepare("SELECT cdt.category_id, cdt.drive_id, cdt.priority, d.friendly_name, d.free_bytes FROM category_drive_targets cdt JOIN drives d ON d.id = cdt.drive_id AND d.is_active = 1 WHERE cdt.is_enabled = 1 AND cdt.category_id IN ({$targetPlaceholders}) ORDER BY cdt.category_id, cdt.priority, d.friendly_name");
    $targetStmt->execute($selectedCategoryIds);
    foreach ($targetStmt->fetchAll() as $target) {
        $categoryTargets[(int)$target['category_id']][] = $target;
    }
}

$matchSql = mp_match_sql();
$activeConflictSql = "SELECT x.file_entry_id FROM (SELECT file_entry_id FROM ({$matchSql}) cm GROUP BY file_entry_id HAVING COUNT(DISTINCT category_id) > 1) x LEFT JOIN category_conflict_ignores cci ON cci.file_entry_id = x.file_entry_id WHERE cci.id IS NULL";
$acceptedConflictSql = "SELECT x.file_entry_id FROM (SELECT file_entry_id FROM ({$matchSql}) cm GROUP BY file_entry_id HAVING COUNT(DISTINCT category_id) > 1) x JOIN category_conflict_ignores cci ON cci.file_entry_id = x.file_entry_id";

$categoryStats = [];
$categoryDriveStats = [];
if ($selectedCategoryIds) {
    $placeholders = implode(',', array_fill(0, count($selectedCategoryIds), '?'));
    $sql = "
        SELECT m.category_id, m.category_name, m.color_hex,
            COUNT(m.file_entry_id) AS item_count,
            COALESCE(SUM(m.entry_type = 'file'), 0) AS file_count,
            COALESCE(SUM(m.entry_type = 'folder'), 0) AS folder_count,
            COALESCE(SUM(CASE WHEN m.entry_type = 'file' THEN m.size_bytes ELSE 0 END), 0) AS raw_size,
            COALESCE(SUM(CASE WHEN ac.file_entry_id IS NULL AND (ic.file_entry_id IS NULL OR ? = 1) AND m.entry_type = 'file' THEN m.size_bytes ELSE 0 END), 0) AS planner_size,
            COALESCE(SUM(CASE WHEN ac.file_entry_id IS NOT NULL AND m.entry_type = 'file' THEN m.size_bytes ELSE 0 END), 0) AS active_conflict_size,
            COALESCE(SUM(CASE WHEN ic.file_entry_id IS NOT NULL AND m.entry_type = 'file' THEN m.size_bytes ELSE 0 END), 0) AS accepted_conflict_size
        FROM ({$matchSql}) m
        LEFT JOIN ({$activeConflictSql}) ac ON ac.file_entry_id = m.file_entry_id
        LEFT JOIN ({$acceptedConflictSql}) ic ON ic.file_entry_id = m.file_entry_id
        WHERE m.category_id IN ({$placeholders})
        GROUP BY m.category_id, m.category_name, m.color_hex
        ORDER BY planner_size DESC, m.category_name
    ";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(1, $includeAccepted ? 1 : 0, PDO::PARAM_INT);
    foreach ($selectedCategoryIds as $index => $categoryId) {
        $stmt->bindValue($index + 2, $categoryId, PDO::PARAM_INT);
    }
    $stmt->execute();
    $categoryStats = $stmt->fetchAll();

    $driveSql = "
        SELECT m.category_id, m.drive_id, d.friendly_name AS drive_name,
            COUNT(m.file_entry_id) AS item_count,
            COALESCE(SUM(m.entry_type = 'file'), 0) AS file_count,
            COALESCE(SUM(CASE WHEN ac.file_entry_id IS NULL AND (ic.file_entry_id IS NULL OR ? = 1) AND m.entry_type = 'file' THEN m.size_bytes ELSE 0 END), 0) AS planner_size
        FROM ({$matchSql}) m
        JOIN drives d ON d.id = m.drive_id
        LEFT JOIN ({$activeConflictSql}) ac ON ac.file_entry_id = m.file_entry_id
        LEFT JOIN ({$acceptedConflictSql}) ic ON ic.file_entry_id = m.file_entry_id
        WHERE m.category_id IN ({$placeholders})
        GROUP BY m.category_id, m.drive_id, d.friendly_name
        HAVING planner_size > 0
        ORDER BY m.category_id, planner_size DESC, d.friendly_name
    ";
    $stmt = db()->prepare($driveSql);
    $stmt->bindValue(1, $includeAccepted ? 1 : 0, PDO::PARAM_INT);
    foreach ($selectedCategoryIds as $index => $categoryId) {
        $stmt->bindValue($index + 2, $categoryId, PDO::PARAM_INT);
    }
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $categoryDriveStats[(int)$row['category_id']][] = $row;
    }
}

$uncategorised = ['planner_size' => 0, 'item_count' => 0, 'file_count' => 0, 'folder_count' => 0];
if ($includeUncategorised) {
    $stmt = db()->query("SELECT COUNT(*) AS item_count, COALESCE(SUM(entry_type = 'file'), 0) AS file_count, COALESCE(SUM(entry_type = 'folder'), 0) AS folder_count, COALESCE(SUM(CASE WHEN entry_type = 'file' THEN size_bytes ELSE 0 END), 0) AS planner_size FROM file_entries f WHERE f.is_missing = 0 AND NOT EXISTS (SELECT 1 FROM ({$matchSql}) m WHERE m.file_entry_id = f.id)");
    $uncategorised = $stmt->fetch() ?: $uncategorised;
}

$activeConflictCount = (int)db()->query("SELECT COUNT(*) FROM ({$activeConflictSql}) ac")->fetchColumn();
$weakDriveCount = 0;
foreach ($selectedDrives as $drive) {
    $identity = (string)($drive['identity_source'] ?? '');
    $serial = (string)($drive['serial_number'] ?? '');
    if ($identity === '' || $identity === 'fallback_path_size' || str_starts_with($serial, 'fallback-') || str_starts_with($serial, 'device-')) {
        $weakDriveCount++;
    }
}

$driveRemaining = [];
foreach ($selectedDrives as $drive) {
    $driveRemaining[(int)$drive['id']] = [
        'drive' => $drive,
        'remaining' => max(0, (int)$drive['free_bytes']),
        'allocated' => 0,
        'categories' => [],
    ];
}

$plannerRows = [];
$plannerErrors = [];
if ($runPlanner) {
    if (!$selectedDrives) {
        $plannerErrors[] = 'No target drives selected.';
    }
    if (!$selectedCategories && !$includeUncategorised) {
        $plannerErrors[] = 'No categories selected.';
    }
    if ($activeConflictCount > 0) {
        $plannerErrors[] = 'Active category conflicts exist. Conflicted files are excluded from size calculations and should be resolved or accepted before real moves.';
    }
    if ($weakDriveCount > 0) {
        $plannerErrors[] = 'One or more selected drives have weak/fallback identity. Read-only planning is allowed, but real moves should wait until drive identity is strong.';
    }

    foreach ($categoryStats as $cat) {
        $size = (int)$cat['planner_size'];
        $categoryId = (int)$cat['category_id'];
        $targetMode = $useCategoryTargets ? 'Configured target drive' : 'Any selected drive';
        $currentDriveRows = $categoryDriveStats[$categoryId] ?? [];

        if ($size <= 0) {
            $plannerRows[] = ['category' => $cat, 'status' => 'No planner data', 'target_mode' => $targetMode, 'targets' => [], 'current_drives' => $currentDriveRows, 'already_on_target' => 0, 'move_size' => 0, 'move_status' => 'No move data'];
            continue;
        }

        $candidateDriveIds = array_keys($driveRemaining);
        if ($useCategoryTargets) {
            $configured = array_values(array_filter($categoryTargets[$categoryId] ?? [], static fn(array $target): bool => isset($selectedDriveIdSet[(int)$target['drive_id']])));
            if (!$configured) {
                $plannerRows[] = ['category' => $cat, 'status' => 'No enabled selected target drive', 'target_mode' => 'Configured target drive', 'targets' => [], 'current_drives' => $currentDriveRows, 'already_on_target' => 0, 'move_size' => $size, 'move_status' => 'Blocked'];
                continue;
            }
            $candidateDriveIds = array_map(static fn(array $target): int => (int)$target['drive_id'], $configured);
        } else {
            uasort($driveRemaining, static fn(array $a, array $b): int => $b['remaining'] <=> $a['remaining']);
            $candidateDriveIds = array_keys($driveRemaining);
        }

        $targets = [];
        $remainingSize = $size;

        if ($allowSpanning) {
            foreach ($candidateDriveIds as $driveId) {
                if (!isset($driveRemaining[$driveId]) || $remainingSize <= 0) {
                    continue;
                }
                $take = min($driveRemaining[$driveId]['remaining'], $remainingSize);
                if ($take <= 0) {
                    continue;
                }
                $driveRemaining[$driveId]['remaining'] -= $take;
                $driveRemaining[$driveId]['allocated'] += $take;
                $driveRemaining[$driveId]['categories'][] = $cat['category_name'];
                $targets[] = ['drive' => $driveRemaining[$driveId]['drive'], 'size' => $take];
                $remainingSize -= $take;
            }
            $status = $remainingSize <= 0 ? 'Planned' : 'Not enough target space';
        } else {
            $targetDriveId = null;
            foreach ($candidateDriveIds as $driveId) {
                if (isset($driveRemaining[$driveId]) && $driveRemaining[$driveId]['remaining'] >= $size) {
                    $targetDriveId = $driveId;
                    break;
                }
            }
            if ($targetDriveId !== null) {
                $driveRemaining[$targetDriveId]['remaining'] -= $size;
                $driveRemaining[$targetDriveId]['allocated'] += $size;
                $driveRemaining[$targetDriveId]['categories'][] = $cat['category_name'];
                $targets[] = ['drive' => $driveRemaining[$targetDriveId]['drive'], 'size' => $size];
                $status = 'Planned';
            } else {
                $status = $useCategoryTargets ? 'No selected target drive fits' : 'No single drive fits';
            }
        }

        $targetDriveIds = mp_target_drive_ids($targets);
        $alreadyOnTarget = min($size, mp_already_on_targets($currentDriveRows, $targetDriveIds));
        $moveSize = max(0, $size - $alreadyOnTarget);
        $moveStatus = match (true) {
            $status !== 'Planned' => 'Blocked',
            $moveSize === 0 => 'No move needed',
            $allowSpanning && count($targets) > 1 => 'Split move estimate',
            default => 'Move needed',
        };

        $plannerRows[] = ['category' => $cat, 'status' => $status, 'target_mode' => $targetMode, 'targets' => $targets, 'current_drives' => $currentDriveRows, 'already_on_target' => $alreadyOnTarget, 'move_size' => $moveSize, 'move_status' => $moveStatus];
    }
}

render_header('Move Planner');
?>
<div class="card">
    <h2>Read-only move planner</h2>
    <p class="muted">This is a planning preview only. It does not queue jobs and does not move files.</p>
    <form method="get">
        <input type="hidden" name="plan" value="1">
        <div class="form-grid">
            <label>Categories
                <select name="category_ids[]" multiple size="8">
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string)$category['id']) ?>" <?= in_array((int)$category['id'], $selectedCategoryIds, true) ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Target drives
                <select name="drive_ids[]" multiple size="8">
                    <?php foreach ($drives as $drive): ?>
                        <option value="<?= e((string)$drive['id']) ?>" <?= in_array((int)$drive['id'], $selectedDriveIds, true) ? 'selected' : '' ?>><?= e($drive['friendly_name'] . ' - free ' . format_bytes((int)$drive['free_bytes'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><input type="checkbox" name="use_category_targets" value="1" <?= $useCategoryTargets ? 'checked' : '' ?>> Use configured category target drives</label>
            <label><input type="checkbox" name="allow_spanning" value="1" <?= $allowSpanning ? 'checked' : '' ?>> Allow a category to span multiple drives</label>
            <label><input type="checkbox" name="include_accepted" value="1" <?= $includeAccepted ? 'checked' : '' ?>> Include accepted conflicts in planner size</label>
            <label><input type="checkbox" name="include_uncategorised" value="1" <?= $includeUncategorised ? 'checked' : '' ?>> Include uncategorised inventory as a holding group</label>
        </div>
        <div class="button-row"><button type="submit">Preview plan</button> <a class="button" href="move-planner.php">Reset</a> <a class="button" href="planner-readiness.php">Readiness</a> <a class="button" href="category-targets.php">Target Drives</a></div>
    </form>
</div>

<div class="card">
    <h2>Planner guardrails</h2>
    <div class="status-bar">Active conflicts: <?= e(number_format($activeConflictCount)) ?> | Weak selected drive identities: <?= e(number_format($weakDriveCount)) ?> | Selected drives: <?= e(number_format(count($selectedDrives))) ?> | Selected categories: <?= e(number_format(count($selectedCategories))) ?> | Target mode: <?= e($useCategoryTargets ? 'Configured target drives' : 'Any selected drive') ?></div>
    <?php if ($plannerErrors): ?>
        <ul>
            <?php foreach ($plannerErrors as $plannerError): ?><li><?= e($plannerError) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Category sizes used for planning</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Category</th><th>Current drives</th><th>Items</th><th>Files</th><th>Raw size</th><th>Active conflict size excluded</th><th>Accepted conflict size</th><th>Planner size</th><th>Configured targets</th></tr></thead>
            <tbody>
            <?php foreach ($categoryStats as $cat): ?>
                <?php $color = mp_colour((string)$cat['color_hex']); $targetNames = array_map(static fn(array $target): string => $target['friendly_name'], $categoryTargets[(int)$cat['category_id']] ?? []); ?>
                <tr>
                    <td><span class="category-badge" style="background-color: <?= e($color) ?>; border-color: <?= e($color) ?>"><?= e($cat['category_name']) ?></span></td>
                    <td><?= e(mp_drive_summary($categoryDriveStats[(int)$cat['category_id']] ?? [])) ?></td>
                    <td><?= e(number_format((int)$cat['item_count'])) ?></td>
                    <td><?= e(number_format((int)$cat['file_count'])) ?></td>
                    <td><?= e(format_bytes((int)$cat['raw_size'])) ?></td>
                    <td><?= e(format_bytes((int)$cat['active_conflict_size'])) ?></td>
                    <td><?= e(format_bytes((int)$cat['accepted_conflict_size'])) ?></td>
                    <td><strong><?= e(format_bytes((int)$cat['planner_size'])) ?></strong></td>
                    <td><?= e($targetNames ? implode(', ', $targetNames) : 'None') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($includeUncategorised): ?>
                <tr><td>Uncategorised holding group</td><td>All current uncategorised drives</td><td><?= e(number_format((int)$uncategorised['item_count'])) ?></td><td><?= e(number_format((int)$uncategorised['file_count'])) ?></td><td><?= e(format_bytes((int)$uncategorised['planner_size'])) ?></td><td>0 B</td><td>0 B</td><td><strong><?= e(format_bytes((int)$uncategorised['planner_size'])) ?></strong></td><td>Any selected drive</td></tr>
            <?php endif; ?>
            <?php if (!$categoryStats && !$includeUncategorised): ?><tr><td colspan="9">No selected category data found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($runPlanner): ?>
<div class="card">
    <h2>Proposed allocation</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Category</th><th>Planner size</th><th>Current drives</th><th>Target mode</th><th>Status</th><th>Target allocation</th></tr></thead>
            <tbody>
            <?php foreach ($plannerRows as $row): ?>
                <?php $cat = $row['category']; $color = mp_colour((string)$cat['color_hex']); ?>
                <tr>
                    <td><span class="category-badge" style="background-color: <?= e($color) ?>; border-color: <?= e($color) ?>"><?= e($cat['category_name']) ?></span></td>
                    <td><?= e(format_bytes((int)$cat['planner_size'])) ?></td>
                    <td><?= e(mp_drive_summary($row['current_drives'])) ?></td>
                    <td><?= e($row['target_mode']) ?></td>
                    <td><?= e($row['status']) ?></td>
                    <td>
                        <?php foreach ($row['targets'] as $target): ?>
                            <div>💽 <?= e($target['drive']['friendly_name']) ?>: <?= e(format_bytes((int)$target['size'])) ?></div>
                        <?php endforeach; ?>
                        <?php if (!$row['targets']): ?>-<?= '' ?><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$plannerRows): ?><tr><td colspan="6">No allocation generated.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Estimated move workload</h2>
    <p class="muted">This compares where category data currently lives with the proposed target allocation. It is still an estimate; exact file-level move jobs come later.</p>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Category</th><th>Move status</th><th>Planner size</th><th>Already on target</th><th>Estimated move size</th><th>Active conflict excluded</th><th>Accepted conflict</th></tr></thead>
            <tbody>
            <?php foreach ($plannerRows as $row): ?>
                <?php $cat = $row['category']; $color = mp_colour((string)$cat['color_hex']); ?>
                <tr>
                    <td><span class="category-badge" style="background-color: <?= e($color) ?>; border-color: <?= e($color) ?>"><?= e($cat['category_name']) ?></span></td>
                    <td><?= e($row['move_status']) ?></td>
                    <td><?= e(format_bytes((int)$cat['planner_size'])) ?></td>
                    <td><?= e(format_bytes((int)$row['already_on_target'])) ?></td>
                    <td><strong><?= e(format_bytes((int)$row['move_size'])) ?></strong></td>
                    <td><?= e(format_bytes((int)$cat['active_conflict_size'])) ?></td>
                    <td><?= e($includeAccepted ? 'Included: ' : 'Excluded: ') ?><?= e(format_bytes((int)$cat['accepted_conflict_size'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$plannerRows): ?><tr><td colspan="7">No move workload generated.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Drive allocation summary</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Drive</th><th>Free before</th><th>Allocated</th><th>Free after plan</th><th>Assigned categories</th></tr></thead>
            <tbody>
            <?php foreach ($driveRemaining as $bucket): ?>
                <tr>
                    <td>💽 <?= e($bucket['drive']['friendly_name']) ?></td>
                    <td><?= e(format_bytes((int)$bucket['drive']['free_bytes'])) ?></td>
                    <td><?= e(format_bytes((int)$bucket['allocated'])) ?></td>
                    <td><?= e(format_bytes((int)$bucket['remaining'])) ?></td>
                    <td><?= e(implode(', ', array_unique($bucket['categories']))) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$driveRemaining): ?><tr><td colspan="5">No drives selected.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php render_footer(); ?>
