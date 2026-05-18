<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$message = null;
$error = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_target') {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $driveId = (int)($_POST['drive_id'] ?? 0);
            $priority = (int)($_POST['priority'] ?? 100);
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($categoryId <= 0 || $driveId <= 0) {
                throw new RuntimeException('Category and drive are required.');
            }

            $stmt = db()->prepare("INSERT INTO category_drive_targets (category_id, drive_id, priority, is_enabled, notes) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE priority = VALUES(priority), is_enabled = VALUES(is_enabled), notes = VALUES(notes), updated_at = NOW()");
            $stmt->execute([$categoryId, $driveId, $priority, $isEnabled, $notes !== '' ? $notes : null]);
            header('Location: category-targets.php');
            exit;
        }

        if ($action === 'delete_target') {
            $stmt = db()->prepare('DELETE FROM category_drive_targets WHERE id = ?');
            $stmt->execute([(int)($_POST['id'] ?? 0)]);
            header('Location: category-targets.php');
            exit;
        }

        if ($action === 'toggle_target') {
            $stmt = db()->prepare('UPDATE category_drive_targets SET is_enabled = 1 - is_enabled WHERE id = ?');
            $stmt->execute([(int)($_POST['id'] ?? 0)]);
            header('Location: category-targets.php');
            exit;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$categories = db()->query('SELECT id, name, color_hex FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$drives = db()->query('SELECT id, friendly_name, form_factor, free_bytes, total_bytes FROM drives WHERE is_active = 1 ORDER BY friendly_name')->fetchAll();
$targets = db()->query("SELECT cdt.*, c.name AS category_name, c.color_hex, d.friendly_name, d.form_factor, d.free_bytes, d.total_bytes FROM category_drive_targets cdt JOIN categories c ON c.id = cdt.category_id JOIN drives d ON d.id = cdt.drive_id ORDER BY c.name, cdt.priority, d.friendly_name")->fetchAll();
$categoryTargetSummary = db()->query("SELECT c.id, c.name, c.color_hex, COUNT(cdt.id) AS target_count, COALESCE(SUM(cdt.is_enabled = 1), 0) AS enabled_count FROM categories c LEFT JOIN category_drive_targets cdt ON cdt.category_id = c.id WHERE c.is_active = 1 GROUP BY c.id, c.name, c.color_hex ORDER BY c.name")->fetchAll();

function target_colour(?string $value): string
{
    $value = (string)$value;
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : '#dbeafe';
}

render_header('Category Targets');
?>
<?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong></div><?php endif; ?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>

<div class="card">
    <h2>Category target drives</h2>
    <p class="muted">Assign preferred destination drives for each category. The move planner can use these targets instead of treating every selected drive as equal.</p>
    <form method="post">
        <input type="hidden" name="action" value="save_target">
        <div class="form-grid compact-search">
            <label>Category
                <select name="category_id" required>
                    <option value="0">Select category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string)$category['id']) ?>"><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Target drive
                <select name="drive_id" required>
                    <option value="0">Select drive</option>
                    <?php foreach ($drives as $drive): ?>
                        <option value="<?= e((string)$drive['id']) ?>"><?= e($drive['friendly_name'] . ' - free ' . format_bytes((int)$drive['free_bytes'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Priority <input type="number" name="priority" value="100" min="1" max="9999"></label>
            <label>Enabled <input type="checkbox" name="is_enabled" value="1" checked></label>
            <label class="wide">Notes <textarea name="notes" rows="2"></textarea></label>
        </div>
        <div class="button-row"><button type="submit">Save target</button></div>
    </form>
</div>

<div class="card">
    <h2>Category target summary</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Category</th><th>Total targets</th><th>Enabled targets</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($categoryTargetSummary as $row): ?>
                <?php $color = target_colour($row['color_hex'] ?? null); ?>
                <tr>
                    <td><span class="category-badge" style="background-color: <?= e($color) ?>; border-color: <?= e($color) ?>"><?= e($row['name']) ?></span></td>
                    <td><?= e(number_format((int)$row['target_count'])) ?></td>
                    <td><?= e(number_format((int)$row['enabled_count'])) ?></td>
                    <td><?= (int)$row['enabled_count'] > 0 ? 'Ready for targeted planning' : 'No enabled target drive' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$categoryTargetSummary): ?><tr><td colspan="4">No active categories.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Configured targets</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Category</th><th>Drive</th><th>Priority</th><th>Enabled</th><th>Drive free</th><th>Drive size</th><th>Notes</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($targets as $target): ?>
                <?php $color = target_colour($target['color_hex'] ?? null); ?>
                <tr>
                    <td><span class="category-badge" style="background-color: <?= e($color) ?>; border-color: <?= e($color) ?>"><?= e($target['category_name']) ?></span></td>
                    <td>💽 <?= e($target['friendly_name']) ?><?= $target['form_factor'] ? ' (' . e($target['form_factor']) . ')' : '' ?></td>
                    <td><?= e((string)$target['priority']) ?></td>
                    <td><?= (int)$target['is_enabled'] === 1 ? 'Yes' : 'No' ?></td>
                    <td><?= e(format_bytes((int)$target['free_bytes'])) ?></td>
                    <td><?= e(format_bytes((int)$target['total_bytes'])) ?></td>
                    <td class="wrap-cell"><?= e($target['notes'] ?? '') ?></td>
                    <td class="actions">
                        <form method="post"><input type="hidden" name="action" value="toggle_target"><input type="hidden" name="id" value="<?= e((string)$target['id']) ?>"><button type="submit"><?= (int)$target['is_enabled'] === 1 ? 'Disable' : 'Enable' ?></button></form>
                        <form method="post" onsubmit="return confirm('Delete this category target?');"><input type="hidden" name="action" value="delete_target"><input type="hidden" name="id" value="<?= e((string)$target['id']) ?>"><button class="danger" type="submit">Delete</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$targets): ?><tr><td colspan="8">No category target drives configured yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
