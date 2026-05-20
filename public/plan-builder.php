<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

const PLAN_BUILDER_BATCH_SIZE = 250;

function pb_json(array $data): string
{
    return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

$error = null;
$message = null;
$categoryId = max(0, (int)($_REQUEST['category_id'] ?? 0));
$targetDriveId = max(0, (int)($_REQUEST['target_drive_id'] ?? 0));
$planId = max(0, (int)($_REQUEST['plan_id'] ?? 0));
$added = 0;
$checked = 0;
$remaining = 0;
$complete = false;

$categories = db()->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$drives = db()->query('SELECT id, friendly_name, free_bytes FROM drives WHERE is_active = 1 ORDER BY friendly_name')->fetchAll();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($categoryId <= 0 || $targetDriveId <= 0) {
            throw new RuntimeException('Select one category and one target drive.');
        }

        $targetCheck = db()->prepare('SELECT COUNT(*) FROM category_drive_targets cdt JOIN categories c ON c.id = cdt.category_id AND c.is_active = 1 JOIN drives d ON d.id = cdt.drive_id AND d.is_active = 1 WHERE cdt.is_enabled = 1 AND cdt.category_id = ? AND cdt.drive_id = ?');
        $targetCheck->execute([$categoryId, $targetDriveId]);
        if ((int)$targetCheck->fetchColumn() <= 0) {
            throw new RuntimeException('That category is not enabled for the selected target drive. Configure it in Target Drives first.');
        }

        if ($planId > 0) {
            $planCheck = db()->prepare('SELECT selected_category_ids_json, selected_drive_ids_json, status FROM move_plans WHERE id = ?');
            $planCheck->execute([$planId]);
            $existingPlan = $planCheck->fetch();
            if (!$existingPlan) {
                throw new RuntimeException('The selected saved plan no longer exists.');
            }
            if ((string)$existingPlan['status'] !== 'building') {
                throw new RuntimeException('This plan is no longer in building status. Open the saved plan instead.');
            }
        }

        if ($planId <= 0) {
            $insertPlan = db()->prepare('INSERT INTO move_plans (status, selected_category_ids_json, selected_drive_ids_json, options_json, warning_summary_json, blocker_summary_json) VALUES (?, ?, ?, ?, ?, ?)');
            $insertPlan->execute(['building', pb_json([$categoryId]), pb_json([$targetDriveId]), pb_json(['paged_builder' => true, 'batch_size' => PLAN_BUILDER_BATCH_SIZE]), pb_json([]), pb_json([])]);
            $planId = (int)db()->lastInsertId();
        }

        $sql = "SELECT f.id, f.drive_id, f.size_bytes, f.relative_path FROM category_manual_assignments cma JOIN file_entries f ON f.id = cma.file_entry_id AND f.is_missing = 0 AND f.entry_type = 'file' LEFT JOIN move_plan_items mpi ON mpi.move_plan_id = ? AND mpi.file_entry_id = f.id WHERE cma.assignment_type = 'file' AND cma.category_id = ? AND f.drive_id <> ? AND mpi.id IS NULL ORDER BY f.id ASC LIMIT ?";
        $stmt = db()->prepare($sql);
        $stmt->bindValue(1, $planId, PDO::PARAM_INT);
        $stmt->bindValue(2, $categoryId, PDO::PARAM_INT);
        $stmt->bindValue(3, $targetDriveId, PDO::PARAM_INT);
        $stmt->bindValue(4, PLAN_BUILDER_BATCH_SIZE, PDO::PARAM_INT);
        $stmt->execute();
        $batch = $stmt->fetchAll();
        $checked = count($batch);

        $insertItem = db()->prepare('INSERT INTO move_plan_items (move_plan_id, category_id, file_entry_id, source_drive_id, target_drive_id, entry_type, size_bytes, relative_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($batch as $item) {
            $insertItem->execute([$planId, $categoryId, (int)$item['id'], (int)$item['drive_id'], $targetDriveId, 'file', (int)$item['size_bytes'], (string)$item['relative_path'], 'planned']);
            $added++;
        }

        $totals = db()->prepare('SELECT COUNT(*) AS candidate_count, COALESCE(SUM(size_bytes), 0) AS total_size FROM move_plan_items WHERE move_plan_id = ?');
        $totals->execute([$planId]);
        $row = $totals->fetch() ?: ['candidate_count' => 0, 'total_size' => 0];
        $update = db()->prepare('UPDATE move_plans SET candidate_count = ?, total_size_bytes = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([(int)$row['candidate_count'], (int)$row['total_size'], $planId]);

        $remainingStmt = db()->prepare("SELECT COUNT(*) FROM category_manual_assignments cma JOIN file_entries f ON f.id = cma.file_entry_id AND f.is_missing = 0 AND f.entry_type = 'file' LEFT JOIN move_plan_items mpi ON mpi.move_plan_id = ? AND mpi.file_entry_id = f.id WHERE cma.assignment_type = 'file' AND cma.category_id = ? AND f.drive_id <> ? AND mpi.id IS NULL");
        $remainingStmt->execute([$planId, $categoryId, $targetDriveId]);
        $remaining = (int)$remainingStmt->fetchColumn();

        if ($remaining <= 0) {
            $complete = true;
            db()->prepare("UPDATE move_plans SET status = 'draft', updated_at = NOW() WHERE id = ?")->execute([$planId]);
            $message = 'Snapshot build complete.';
        } else {
            $message = 'Batch added. Add the next batch to continue.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$plan = null;
if ($planId > 0) {
    $stmt = db()->prepare('SELECT * FROM move_plans WHERE id = ?');
    $stmt->execute([$planId]);
    $plan = $stmt->fetch() ?: null;
}

render_header('Plan Builder');
?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
<?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong></div><?php endif; ?>
<div class="card">
    <h2>Build move plan snapshot</h2>
    <p class="muted">Builds one category to one target drive in small resume-safe batches. The selected category must be enabled for the selected target drive. This stores read-only plan items only; it does not move files.</p>
    <form method="post">
        <div class="form-grid">
            <label>Category<select name="category_id" required><option value="0">Select category</option><?php foreach ($categories as $category): ?><option value="<?= e((string)$category['id']) ?>" <?= $categoryId === (int)$category['id'] ? 'selected' : '' ?>><?= e((string)$category['name']) ?></option><?php endforeach; ?></select></label>
            <label>Target drive<select name="target_drive_id" required><option value="0">Select target drive</option><?php foreach ($drives as $drive): ?><option value="<?= e((string)$drive['id']) ?>" <?= $targetDriveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name'] . ' - free ' . format_bytes((int)$drive['free_bytes'])) ?></option><?php endforeach; ?></select></label>
        </div>
        <input type="hidden" name="plan_id" value="<?= e((string)$planId) ?>">
        <?php if (!$complete): ?><button type="submit"><?= $planId > 0 ? 'Add next batch' : 'Create snapshot' ?></button><?php endif; ?>
        <a class="button" href="move-planner.php">Move Planner</a>
        <a class="button" href="category-targets.php">Target Drives</a>
        <?php if ($categoryId > 0 && $targetDriveId > 0): ?><a class="button" href="move-candidates.php?category_id=<?= e((string)$categoryId) ?>&target_drive_id=<?= e((string)$targetDriveId) ?>">Review candidates</a><?php endif; ?>
        <?php if ($planId > 0): ?><a class="button" href="move-plan-view.php?id=<?= e((string)$planId) ?>">View saved plan</a><?php endif; ?>
    </form>
</div>
<?php if ($plan): ?><div class="card"><h2>Snapshot status</h2><div class="status-bar">Plan #<?= e((string)$plan['id']) ?> | Status: <?= e((string)$plan['status']) ?> | Checked this batch: <?= e(number_format($checked)) ?> | Added this batch: <?= e(number_format($added)) ?> | Remaining: <?= e(number_format($remaining)) ?> | Items saved: <?= e(number_format((int)$plan['candidate_count'])) ?> | Total size: <?= e(format_bytes((int)$plan['total_size_bytes'])) ?></div></div><?php endif; ?>
<?php render_footer(); ?>
