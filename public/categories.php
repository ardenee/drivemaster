<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

const MANUAL_ASSIGNMENTS_MAX_PAGE_SIZE = 990;

$message = null;
$error = null;
$ruleTypes = [
    'extension' => 'Extension',
    'folder_path' => 'Folder/path prefix',
    'path_contains' => 'Path contains',
    'name_contains' => 'Name contains',
];

function selected_int(string $key): int
{
    return max(0, (int)($_GET[$key] ?? 0));
}

function category_page_link(array $params = [], string $anchor = ''): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        }
    }
    return 'categories.php?' . http_build_query($query) . $anchor;
}

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

        if ($action === 'delete_manual_assignment') {
            $stmt = db()->prepare('DELETE FROM category_manual_assignments WHERE id = ?');
            $stmt->execute([(int)$_POST['id']]);
            header('Location: categories.php#manual');
            exit;
        }

        if ($action === 'delete_selected_manual_assignments') {
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))), static fn(int $id): bool => $id > 0)));
            if (!$ids) {
                throw new RuntimeException('No manual assignments selected.');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("DELETE FROM category_manual_assignments WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            header('Location: categories.php#manual');
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

$manualCategoryId = selected_int('manual_category_id');
$manualDriveId = selected_int('manual_drive_id');
$manualType = (string)($_GET['manual_type'] ?? 'all');
if (!in_array($manualType, ['all', 'file', 'folder'], true)) {
    $manualType = 'all';
}
$manualPath = trim((string)($_GET['manual_path'] ?? ''));
$manualLimit = (string)($_GET['manual_limit'] ?? '200');
$manualLimitOptions = [
    ['25', '25 per page'],
    ['50', '50 per page'],
    ['100', '100 per page'],
    ['200', '200 per page'],
    ['500', '500 per page'],
    ['990', '990 per page'],
    ['none', 'No Limit, paged'],
];
$manualLimitValues = array_map(static fn(array $option): string => $option[0], $manualLimitOptions);
if (!in_array($manualLimit, $manualLimitValues, true)) {
    $manualLimit = '200';
}
$manualPage = max(1, (int)($_GET['manual_page'] ?? 1));
$manualPageSize = $manualLimit === 'none' ? MANUAL_ASSIGNMENTS_MAX_PAGE_SIZE : max(1, min(MANUAL_ASSIGNMENTS_MAX_PAGE_SIZE, (int)$manualLimit));
$manualOffset = ($manualPage - 1) * $manualPageSize;

$categories = db()->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$drives = db()->query('SELECT id, friendly_name FROM drives ORDER BY friendly_name')->fetchAll();
$rules = db()->query('SELECT cr.*, c.name AS category_name, d.friendly_name AS drive_name FROM category_rules cr JOIN categories c ON c.id = cr.category_id LEFT JOIN drives d ON d.id = cr.drive_id ORDER BY c.name, cr.rule_type, cr.pattern')->fetchAll();
$manualSummary = db()->query('SELECT c.id, c.name, COUNT(cma.id) AS assignment_count FROM categories c LEFT JOIN category_manual_assignments cma ON cma.category_id = c.id GROUP BY c.id, c.name ORDER BY c.name')->fetchAll();

$manualWhere = ['1=1'];
$manualParams = [];
if ($manualCategoryId > 0) {
    $manualWhere[] = 'cma.category_id = ?';
    $manualParams[] = $manualCategoryId;
}
if ($manualDriveId > 0) {
    $manualWhere[] = '(cma.drive_id = ? OR fe.drive_id = ?)';
    $manualParams[] = $manualDriveId;
    $manualParams[] = $manualDriveId;
}
if ($manualType !== 'all') {
    $manualWhere[] = 'cma.assignment_type = ?';
    $manualParams[] = $manualType;
}
if ($manualPath !== '') {
    $manualWhere[] = '(cma.path_prefix LIKE ? OR fe.relative_path LIKE ? OR fe.name LIKE ?)';
    $manualParams[] = '%' . $manualPath . '%';
    $manualParams[] = '%' . $manualPath . '%';
    $manualParams[] = '%' . $manualPath . '%';
}
$manualWhereSql = implode(' AND ', $manualWhere);
$manualCountStmt = db()->prepare("SELECT COUNT(*) FROM category_manual_assignments cma JOIN categories c ON c.id = cma.category_id LEFT JOIN file_entries fe ON fe.id = cma.file_entry_id LEFT JOIN drives d1 ON d1.id = cma.drive_id LEFT JOIN drives d2 ON d2.id = fe.drive_id WHERE {$manualWhereSql}");
$manualCountStmt->execute($manualParams);
$manualTotal = (int)$manualCountStmt->fetchColumn();
$manualTotalPages = max(1, (int)ceil($manualTotal / $manualPageSize));
if ($manualPage > $manualTotalPages) {
    $manualPage = $manualTotalPages;
    $manualOffset = ($manualPage - 1) * $manualPageSize;
}
$manualStmt = db()->prepare("SELECT cma.*, c.name AS category_name, COALESCE(d1.friendly_name, d2.friendly_name) AS drive_name, fe.relative_path AS file_relative_path, fe.name AS file_name FROM category_manual_assignments cma JOIN categories c ON c.id = cma.category_id LEFT JOIN file_entries fe ON fe.id = cma.file_entry_id LEFT JOIN drives d1 ON d1.id = cma.drive_id LEFT JOIN drives d2 ON d2.id = fe.drive_id WHERE {$manualWhereSql} ORDER BY c.name, COALESCE(d1.friendly_name, d2.friendly_name), COALESCE(cma.path_prefix, fe.relative_path) LIMIT ? OFFSET ?");
foreach ($manualParams as $index => $value) {
    $manualStmt->bindValue($index + 1, $value);
}
$manualStmt->bindValue(count($manualParams) + 1, $manualPageSize, PDO::PARAM_INT);
$manualStmt->bindValue(count($manualParams) + 2, $manualOffset, PDO::PARAM_INT);
$manualStmt->execute();
$manualAssignments = $manualStmt->fetchAll();

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

<div class="card" id="manual">
    <h2>Manual assignments</h2>
    <p class="muted">These are explicit category choices made from the file explorer. They remain active even when optional category rules are disabled.</p>
    <form method="get">
        <div class="form-grid compact-search">
            <label>Category
                <select name="manual_category_id">
                    <option value="0">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string)$category['id']) ?>" <?= $manualCategoryId === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Drive
                <select name="manual_drive_id">
                    <option value="0">All drives</option>
                    <?php foreach ($drives as $drive): ?>
                        <option value="<?= e((string)$drive['id']) ?>" <?= $manualDriveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Type
                <select name="manual_type">
                    <option value="all" <?= $manualType === 'all' ? 'selected' : '' ?>>Files and folders</option>
                    <option value="file" <?= $manualType === 'file' ? 'selected' : '' ?>>Files only</option>
                    <option value="folder" <?= $manualType === 'folder' ? 'selected' : '' ?>>Folders only</option>
                </select>
            </label>
            <label>Limit
                <select name="manual_limit">
                    <?php foreach ($manualLimitOptions as $option): ?>
                        <option value="<?= e($option[0]) ?>" <?= $manualLimit === $option[0] ? 'selected' : '' ?>><?= e($option[1]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="wide">Path/name contains <input type="text" name="manual_path" value="<?= e($manualPath) ?>"></label>
        </div>
        <input type="hidden" name="manual_page" value="1">
        <div class="button-row"><button type="submit">Filter assignments</button> <a class="button" href="categories.php#manual">Clear</a></div>
    </form>

    <div class="button-row">
        <form method="post" id="manual-delete-selected-form" onsubmit="return prepareManualAssignmentDelete();"><input type="hidden" name="action" value="delete_selected_manual_assignments"><input type="hidden" name="ids" id="selected-manual-assignment-ids"><button class="danger" type="submit">Delete selected</button></form>
    </div>

    <p class="muted">Showing <?= e(number_format($manualTotal === 0 ? 0 : $manualOffset + 1)) ?>-<?= e(number_format(min($manualOffset + count($manualAssignments), $manualTotal))) ?> of <?= e(number_format($manualTotal)) ?> matching manual assignments.</p>
    <div class="table-scroll">
        <table class="auto-table compact-table selectable-table" data-status-target="manual-assignment-status">
            <thead><tr><th>Select</th><th>Category</th><th>Type</th><th>Drive</th><th>Path / file</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($manualAssignments as $assignment): ?>
                <?php $path = $assignment['assignment_type'] === 'folder' ? (string)$assignment['path_prefix'] : (string)($assignment['file_relative_path'] ?? $assignment['file_name'] ?? ''); ?>
                <tr class="selectable-row">
                    <td><input type="checkbox" class="manual-assignment-check" value="<?= e((string)$assignment['id']) ?>"></td>
                    <td><?= e($assignment['category_name']) ?></td>
                    <td><?= e($assignment['assignment_type']) ?></td>
                    <td><?= e($assignment['drive_name'] ?? '') ?></td>
                    <td class="wrap-cell path-cell"><?= e($path) ?></td>
                    <td><?= e($assignment['created_at']) ?></td>
                    <td class="actions"><form method="post" onsubmit="return confirm('Delete this manual assignment?');"><input type="hidden" name="action" value="delete_manual_assignment"><input type="hidden" name="id" value="<?= e((string)$assignment['id']) ?>"><button class="danger" type="submit">Delete</button></form></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$manualAssignments): ?><tr><td colspan="7">No manual assignments found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($manualTotal > $manualPageSize): ?>
        <div class="status-bar">Page <?= e(number_format($manualPage)) ?> of <?= e(number_format($manualTotalPages)) ?>. <a class="button" href="<?= e(category_page_link(['manual_page' => max(1, $manualPage - 1)], '#manual')) ?>">Previous</a> <a class="button" href="<?= e(category_page_link(['manual_page' => min($manualTotalPages, $manualPage + 1)], '#manual')) ?>">Next</a></div>
    <?php endif; ?>
    <div class="status-bar" id="manual-assignment-status">Selected: 0</div>
</div>

<script>
function updateManualAssignmentStatus() {
    const count = document.querySelectorAll('.manual-assignment-check:checked').length;
    const status = document.getElementById('manual-assignment-status');
    if (status) {
        status.textContent = 'Selected: ' + count;
    }
}

function prepareManualAssignmentDelete() {
    const ids = Array.from(document.querySelectorAll('.manual-assignment-check:checked')).map(function (checkbox) { return checkbox.value; });
    if (ids.length === 0) {
        alert('No manual assignments selected.');
        return false;
    }
    if (!confirm('Delete selected manual assignments?')) {
        return false;
    }
    document.getElementById('selected-manual-assignment-ids').value = ids.join(',');
    return true;
}

document.querySelectorAll('.manual-assignment-check').forEach(function (checkbox) {
    checkbox.addEventListener('change', updateManualAssignmentStatus);
});
updateManualAssignmentStatus();
</script>
<?php render_footer(); ?>
