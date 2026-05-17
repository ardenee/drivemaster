<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$message = null;
$error = null;
$limit = (int)($_GET['limit'] ?? 200);
$limit = max(25, min(1000, $limit));
$driveId = (int)($_GET['drive_id'] ?? 0);
$scanRunId = (int)($_GET['scan_run_id'] ?? 0);
$stage = trim((string)($_GET['stage'] ?? ''));
$pathSearch = trim((string)($_GET['path'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'delete_one') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('No error ID supplied.');
            }
            $stmt = db()->prepare('DELETE FROM scan_entry_errors WHERE id = ?');
            $stmt->execute([$id]);
            $message = 'Error removed.';
        }

        if ($action === 'delete_selected') {
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))), static fn(int $id): bool => $id > 0)));
            if (!$ids) {
                throw new RuntimeException('No selected errors supplied.');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("DELETE FROM scan_entry_errors WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $message = 'Selected errors removed.';
        }

        if ($action === 'delete_filtered') {
            [$where, $params] = build_error_filter($driveId, $scanRunId, $stage, $pathSearch);
            $stmt = db()->prepare('DELETE FROM scan_entry_errors WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);
            $message = 'Filtered errors removed.';
        }

        if ($action === 'delete_all') {
            db()->exec('DELETE FROM scan_entry_errors');
            $message = 'All scan errors removed.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function build_error_filter(int $driveId, int $scanRunId, string $stage, string $pathSearch): array
{
    $where = ['1=1'];
    $params = [];

    if ($driveId > 0) {
        $where[] = 'see.drive_id = ?';
        $params[] = $driveId;
    }

    if ($scanRunId > 0) {
        $where[] = 'see.scan_run_id = ?';
        $params[] = $scanRunId;
    }

    if ($stage !== '') {
        $where[] = 'see.error_stage = ?';
        $params[] = $stage;
    }

    if ($pathSearch !== '') {
        $where[] = 'see.path LIKE ?';
        $params[] = '%' . $pathSearch . '%';
    }

    return [$where, $params];
}

function build_error_query_filter(int $driveId, int $scanRunId, string $stage, string $pathSearch): array
{
    [$where, $params] = build_error_filter($driveId, $scanRunId, $stage, $pathSearch);
    return [$where, $params];
}

$drives = db()->query('SELECT id, friendly_name FROM drives ORDER BY friendly_name')->fetchAll();
$stages = db()->query('SELECT DISTINCT error_stage FROM scan_entry_errors ORDER BY error_stage')->fetchAll();
[$where, $params] = build_error_query_filter($driveId, $scanRunId, $stage, $pathSearch);

$countStmt = db()->prepare('SELECT COUNT(*) FROM scan_entry_errors see WHERE ' . implode(' AND ', $where));
$countStmt->execute($params);
$totalErrors = (int)$countStmt->fetchColumn();

$sql = 'SELECT see.*, d.friendly_name FROM scan_entry_errors see LEFT JOIN drives d ON d.id = see.drive_id WHERE ' . implode(' AND ', $where) . ' ORDER BY see.id DESC LIMIT ?';
$stmt = db()->prepare($sql);
foreach ($params as $index => $value) {
    $stmt->bindValue($index + 1, $value);
}
$stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
$stmt->execute();
$errors = $stmt->fetchAll();

render_header('Errors');
?>
<?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong></div><?php endif; ?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>

<div class="card">
    <h2>Scan errors</h2>
    <p class="muted">These are item-level scan warnings. They do not necessarily mean the scan failed; they identify files/folders where metadata had to be defaulted, skipped, or truncated.</p>
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
            <label>Scan run ID <input type="number" name="scan_run_id" min="0" value="<?= e($scanRunId > 0 ? (string)$scanRunId : '') ?>"></label>
            <label>Error stage
                <select name="stage">
                    <option value="">All stages</option>
                    <?php foreach ($stages as $stageRow): ?>
                        <option value="<?= e($stageRow['error_stage']) ?>" <?= $stage === $stageRow['error_stage'] ? 'selected' : '' ?>><?= e($stageRow['error_stage']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Path contains <input type="text" name="path" value="<?= e($pathSearch) ?>"></label>
            <label>Limit
                <select name="limit">
                    <?php foreach ([25, 50, 100, 200, 500, 1000] as $option): ?>
                        <option value="<?= e((string)$option) ?>" <?= $limit === $option ? 'selected' : '' ?>><?= e((string)$option) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <p><button type="submit">Filter</button> <a class="button" href="errors.php">Clear</a></p>
    </form>
</div>

<div class="card">
    <h2>Error log</h2>
    <p class="muted">Showing <?= e((string)count($errors)) ?> of <?= e((string)$totalErrors) ?> matching errors.</p>
    <form method="post" id="delete-selected-form" onsubmit="return prepareSelectedDelete();">
        <input type="hidden" name="action" value="delete_selected">
        <input type="hidden" name="ids" id="selected-error-ids">
        <button class="danger" type="submit">Delete selected</button>
    </form>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete all currently filtered errors?');">
        <input type="hidden" name="action" value="delete_filtered">
        <button class="danger" type="submit">Delete filtered</button>
    </form>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete ALL scan errors?');">
        <input type="hidden" name="action" value="delete_all">
        <button class="danger" type="submit">Delete all</button>
    </form>

    <div class="search-results-scroll error-results-scroll">
        <table class="errors-table selectable-table" data-status-target="errors-status">
            <thead><tr><th>Select</th><th>ID</th><th>Drive</th><th>Scan</th><th>Stage</th><th>Path</th><th>Error</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($errors as $row): ?>
                <tr class="selectable-row" data-id="<?= e((string)$row['id']) ?>">
                    <td><input type="checkbox" class="error-check" value="<?= e((string)$row['id']) ?>"></td>
                    <td><?= e((string)$row['id']) ?></td>
                    <td><?= e($row['friendly_name'] ?? ('Drive #' . $row['drive_id'])) ?></td>
                    <td><?= e((string)$row['scan_run_id']) ?></td>
                    <td><?= e($row['error_stage']) ?></td>
                    <td class="wrap-cell path-cell"><?= e($row['path']) ?></td>
                    <td class="wrap-cell"><?= e($row['error_message']) ?></td>
                    <td><?= e($row['created_at']) ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Delete this error?');">
                            <input type="hidden" name="action" value="delete_one">
                            <input type="hidden" name="id" value="<?= e((string)$row['id']) ?>">
                            <button class="danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$errors): ?><tr><td colspan="9">No scan errors found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="status-bar" id="errors-status">Selected: 0</div>
</div>

<script>
function updateErrorStatus() {
    const count = document.querySelectorAll('.error-check:checked').length;
    const status = document.getElementById('errors-status');
    if (status) {
        status.textContent = 'Selected: ' + count;
    }
}

function prepareSelectedDelete() {
    const ids = Array.from(document.querySelectorAll('.error-check:checked')).map(function (checkbox) { return checkbox.value; });
    if (ids.length === 0) {
        alert('No errors selected.');
        return false;
    }
    if (!confirm('Delete selected errors?')) {
        return false;
    }
    document.getElementById('selected-error-ids').value = ids.join(',');
    return true;
}

document.querySelectorAll('.error-check').forEach(function (checkbox) {
    checkbox.addEventListener('change', updateErrorStatus);
});
updateErrorStatus();
</script>
<?php render_footer(); ?>
