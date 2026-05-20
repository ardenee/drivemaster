<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Repository\MovePlanningRepository;

function pr_status_badge(string $status): string
{
    $class = match ($status) {
        'Blocker' => 'readiness-badge readiness-blocker',
        'Warning' => 'readiness-badge readiness-warning',
        default => 'readiness-badge readiness-ok',
    };
    return '<span class="' . e($class) . '">' . e($status) . '</span>';
}

function pr_reason_label(string $reason): string
{
    return match ($reason) {
        'safe_candidate' => 'Safe move candidates',
        'no_category' => 'No category',
        'multiple_categories' => 'Multiple categories / conflict risk',
        'active_conflict' => 'Active conflict',
        'accepted_conflict' => 'Accepted conflict',
        'category_has_no_target_drive' => 'Category has no target drive',
        'already_on_target_drive' => 'Already on configured target drive',
        default => $reason,
    };
}

$error = null;
$planner = new MovePlanningRepository(db());
$reasonRows = [];

try {
    $reasonRows = $planner->blockerSummary();
} catch (Throwable $e) {
    $error = 'Could not load planner safety summary: ' . $e->getMessage();
}

$weakDriveCount = (int)db()->query("SELECT COUNT(*) FROM drives WHERE is_active = 1 AND (identity_source IS NULL OR identity_source IN ('fallback_path_size') OR serial_number LIKE 'fallback-%' OR serial_number LIKE 'device-%')")->fetchColumn();
$unknownSpaceCount = (int)db()->query('SELECT COUNT(*) FROM drives WHERE is_active = 1 AND total_bytes <= 0')->fetchColumn();
$categoryCount = (int)db()->query('SELECT COUNT(*) FROM categories WHERE is_active = 1')->fetchColumn();
$targetCount = (int)db()->query('SELECT COUNT(*) FROM category_drive_targets cdt JOIN categories c ON c.id = cdt.category_id AND c.is_active = 1 JOIN drives d ON d.id = cdt.drive_id AND d.is_active = 1 WHERE cdt.is_enabled = 1')->fetchColumn();
$safeCount = (int)($reasonRows['safe_candidate']['item_count'] ?? 0);
$blockedCount = array_sum(array_map(static fn(array $row): int => (int)$row['item_count'], array_filter($reasonRows, static fn(array $row): bool => $row['reason'] !== 'safe_candidate' && $row['reason'] !== 'already_on_target_drive')));

$checks = [];
$checks[] = ['status' => $categoryCount <= 0 ? 'Blocker' : 'OK', 'check' => 'Active categories exist', 'details' => number_format($categoryCount) . ' active categories.', 'action' => '<a class="button" href="categories.php">Manage categories</a>'];
$checks[] = ['status' => $targetCount <= 0 ? 'Blocker' : 'OK', 'check' => 'Category target drives exist', 'details' => number_format($targetCount) . ' enabled active category/drive targets.', 'action' => '<a class="button" href="category-targets.php">Target Drives</a>'];
$checks[] = ['status' => $safeCount <= 0 ? 'Warning' : 'OK', 'check' => 'Safe move candidates exist', 'details' => number_format($safeCount) . ' files appear safe to consider for moves.', 'action' => '<a class="button" href="move-candidates.php">Move Candidates</a>'];
$checks[] = ['status' => $blockedCount > 0 ? 'Warning' : 'OK', 'check' => 'Blocked/unsafe inventory', 'details' => number_format($blockedCount) . ' items are blocked or excluded by safety rules.', 'action' => '<a class="button" href="#planner-safety">Reason counts</a>'];
$checks[] = ['status' => $weakDriveCount > 0 ? 'Warning' : 'OK', 'check' => 'Drive identity strength', 'details' => number_format($weakDriveCount) . ' active drives have weak/fallback identity.', 'action' => '<a class="button" href="drives.php">Review drives</a>'];
$checks[] = ['status' => $unknownSpaceCount > 0 ? 'Blocker' : 'OK', 'check' => 'Drive capacity/free-space data', 'details' => number_format($unknownSpaceCount) . ' active drives have unknown/zero total size.', 'action' => '<a class="button" href="drives.php">Review drives</a>'];

$blockers = count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'Blocker'));
$warnings = count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'Warning'));

render_header('Planner Readiness');
?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
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
                <tr><td><?= pr_status_badge($check['status']) ?></td><td><?= e($check['check']) ?></td><td><?= e($check['details']) ?></td><td class="actions"><?= $check['action'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="planner-safety">
    <h2>Move planning safety reason counts</h2>
    <p class="muted">Only files with exactly one effective category, an enabled target drive, no active/accepted conflict, and not already on a configured target are treated as safe move candidates.</p>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Reason</th><th>Items</th><th>File size</th><th>Planner handling</th></tr></thead>
            <tbody>
            <?php foreach ($reasonRows as $row): ?>
                <?php $reason = (string)$row['reason']; ?>
                <tr>
                    <td><?= e(pr_reason_label($reason)) ?></td>
                    <td><?= e(number_format((int)$row['item_count'])) ?></td>
                    <td><?= e(format_bytes((int)$row['total_size'])) ?></td>
                    <td><?= e($reason === 'safe_candidate' ? 'Eligible' : ($reason === 'already_on_target_drive' ? 'No move needed' : 'Blocked')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$reasonRows): ?><tr><td colspan="4">No planner safety data found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
