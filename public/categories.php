<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$message = null;
$error = null;
$ruleTypes = [
    'extension' => 'Extension',
    'folder_path' => 'Folder/path prefix',
    'path_contains' => 'Path contains',
    'name_contains' => 'Name contains',
];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_category') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($name === '') {
                throw new RuntimeException('Category name is required.');
            }

            if ($id > 0) {
                $stmt = db()->prepare('UPDATE categories SET name = ?, description = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$name, $description ?: null, $isActive, $id]);
            } else {
                $stmt = db()->prepare('INSERT INTO categories (name, description, is_active) VALUES (?, ?, ?)');
                $stmt->execute([$name, $description ?: null, $isActive]);
            }

            header('Location: categories.php');
            exit;
        }

        if ($action === 'delete_category') {
            $stmt = db()->prepare('DELETE FROM categories WHERE id = ?');
            $stmt->execute([(int)$_POST['id']]);
            header('Location: categories.php');
            exit;
        }

        if ($action === 'save_rule') {
            $id = (int)($_POST['id'] ?? 0);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $driveId = (int)($_POST['drive_id'] ?? 0);
            $ruleType = (string)($_POST['rule_type'] ?? '');
            $pattern = trim((string)($_POST['pattern'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;

            if ($categoryId <= 0) {
                throw new RuntimeException('Category is required for a rule.');
            }
            if (!array_key_exists($ruleType, $ruleTypes)) {
                throw new RuntimeException('Invalid rule type.');
            }
            if ($pattern === '') {
                throw new RuntimeException('Rule pattern is required.');
            }
            if ($ruleType === 'extension') {
                $pattern = strtolower(ltrim($pattern, '.'));
            }

            if ($id > 0) {
                $stmt = db()->prepare('UPDATE category_rules SET category_id = ?, drive_id = ?, rule_type = ?, pattern = ?, is_enabled = ?, notes = ? WHERE id = ?');
                $stmt->execute([$categoryId, $driveId > 0 ? $driveId : null, $ruleType, $pattern, $isEnabled, $notes ?: null, $id]);
            } else {
                $stmt = db()->prepare('INSERT INTO category_rules (category_id, drive_id, rule_type, pattern, is_enabled, notes) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$categoryId, $driveId > 0 ? $driveId : null, $ruleType, $pattern, $isEnabled, $notes ?: null]);
            }

            header('Location: categories.php#rules');
            exit;
        }

        if ($action === 'toggle_rule') {
            $stmt = db()->prepare('UPDATE category_rules SET is_enabled = 1 - is_enabled WHERE id = ?');
            $stmt->execute([(int)$_POST['id']]);
            header('Location: categories.php#rules');
            exit;
        }

        if ($action === 'delete_rule') {
            $stmt = db()->prepare('DELETE FROM category_rules WHERE id = ?');
            $stmt->execute([(int)$_POST['id']]);
            header('Location: categories.php#rules');
            exit;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

$editRule = null;
if (isset($_GET['edit_rule'])) {
    $stmt = db()->prepare('SELECT * FROM category_rules WHERE id = ?');
    $stmt->execute([(int)$_GET['edit_rule']]);
    $editRule = $stmt->fetch() ?: null;
}

$categories = db()->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$drives = db()->query('SELECT id, friendly_name FROM drives ORDER BY friendly_name')->fetchAll();
$rules = db()->query('SELECT cr.*, c.name AS category_name, d.friendly_name AS drive_name FROM category_rules cr JOIN categories c ON c.id = cr.category_id LEFT JOIN drives d ON d.id = cr.drive_id ORDER BY c.name, cr.rule_type, cr.pattern')->fetchAll();
$manualSummary = db()->query('SELECT c.id, c.name, COUNT(cma.id) AS assignment_count FROM categories c LEFT JOIN category_manual_assignments cma ON cma.category_id = c.id GROUP BY c.id, c.name ORDER BY c.name')->fetchAll();

render_header('Categories');
?>
<?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong></div><?php endif; ?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>

<div class="card">
    <h2><?= $edit ? 'Edit category' : 'Add category' ?></h2>
    <form method="post">
        <input type="hidden" name="action" value="save_category">
        <input type="hidden" name="id" value="<?= e((string)($edit['id'] ?? 0)) ?>">
        <div class="form-grid">
            <label>Name <input type="text" name="name" required value="<?= e($edit['name'] ?? '') ?>"></label>
            <label>Active <input type="checkbox" name="is_active" value="1" <?= !isset($edit) || (int)($edit['is_active'] ?? 1) === 1 ? 'checked' : '' ?>></label>
            <label class="wide">Description <textarea name="description" rows="3"><?= e($edit['description'] ?? '') ?></textarea></label>
        </div>
        <p><button type="submit">Save category</button> <?php if ($edit): ?><a class="button" href="categories.php">Cancel</a><?php endif; ?></p>
    </form>
</div>

<div class="card">
    <h2>Categories</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Name</th><th>Description</th><th>Active</th><th>Manual assignments</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($categories as $category): ?>
                <?php $assignmentCount = 0; foreach ($manualSummary as $summary) { if ((int)$summary['id'] === (int)$category['id']) { $assignmentCount = (int)$summary['assignment_count']; break; } } ?>
                <tr>
                    <td><?= e($category['name']) ?></td>
                    <td><?= e($category['description']) ?></td>
                    <td><?= (int)$category['is_active'] === 1 ? 'Yes' : 'No' ?></td>
                    <td><?= e(number_format($assignmentCount)) ?></td>
                    <td class="actions">
                        <a class="button" href="categories.php?edit=<?= e((string)$category['id']) ?>">Edit</a>
                        <form method="post" onsubmit="return confirm('Delete this category?');"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?= e((string)$category['id']) ?>"><button class="danger" type="submit">Delete</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="rules">
    <h2><?= $editRule ? 'Edit category rule' : 'Add category rule' ?></h2>
    <p class="muted">Rules are optional and can be enabled/disabled. Manual folder/file assignments are kept separately, so rules do not overwrite your manual decisions.</p>
    <form method="post">
        <input type="hidden" name="action" value="save_rule">
        <input type="hidden" name="id" value="<?= e((string)($editRule['id'] ?? 0)) ?>">
        <div class="form-grid compact-search">
            <label>Category
                <select name="category_id" required>
                    <option value="0">Select category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string)$category['id']) ?>" <?= (int)($editRule['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Drive scope
                <select name="drive_id">
                    <option value="0">All drives</option>
                    <?php foreach ($drives as $drive): ?>
                        <option value="<?= e((string)$drive['id']) ?>" <?= (int)($editRule['drive_id'] ?? 0) === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Rule type
                <select name="rule_type">
                    <?php foreach ($ruleTypes as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= (($editRule['rule_type'] ?? 'extension') === $value) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Enabled <input type="checkbox" name="is_enabled" value="1" <?= !isset($editRule) || (int)($editRule['is_enabled'] ?? 1) === 1 ? 'checked' : '' ?>></label>
            <label class="wide">Pattern <input type="text" name="pattern" required value="<?= e($editRule['pattern'] ?? '') ?>" placeholder="mp3, Music/, invoice, etc."></label>
            <label class="wide">Notes <textarea name="notes" rows="2"><?= e($editRule['notes'] ?? '') ?></textarea></label>
        </div>
        <p><button type="submit">Save rule</button> <?php if ($editRule): ?><a class="button" href="categories.php#rules">Cancel</a><?php endif; ?></p>
    </form>
</div>

<div class="card">
    <h2>Category rules</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Category</th><th>Enabled</th><th>Type</th><th>Pattern</th><th>Drive scope</th><th>Notes</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rules as $rule): ?>
                <tr>
                    <td><?= e($rule['category_name']) ?></td>
                    <td><?= (int)$rule['is_enabled'] === 1 ? 'Yes' : 'No' ?></td>
                    <td><?= e($ruleTypes[$rule['rule_type']] ?? $rule['rule_type']) ?></td>
                    <td><?= e($rule['pattern']) ?></td>
                    <td><?= e($rule['drive_name'] ?? 'All drives') ?></td>
                    <td><?= e($rule['notes']) ?></td>
                    <td class="actions">
                        <a class="button" href="categories.php?edit_rule=<?= e((string)$rule['id']) ?>#rules">Edit</a>
                        <form method="post"><input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="id" value="<?= e((string)$rule['id']) ?>"><button type="submit"><?= (int)$rule['is_enabled'] === 1 ? 'Disable' : 'Enable' ?></button></form>
                        <form method="post" onsubmit="return confirm('Delete this category rule?');"><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id" value="<?= e((string)$rule['id']) ?>"><button class="danger" type="submit">Delete</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rules): ?><tr><td colspan="7">No category rules created yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
