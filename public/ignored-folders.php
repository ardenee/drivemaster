<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$error = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add') {
            $folderName = trim((string)($_POST['folder_name'] ?? ''));
            if ($folderName === '') {
                throw new RuntimeException('Folder name is required.');
            }
            if (str_contains($folderName, '/') || str_contains($folderName, '\\')) {
                throw new RuntimeException('Enter only a folder name, not a path.');
            }
            $stmt = db()->prepare('INSERT INTO ignored_folder_names (folder_name, is_enabled) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_enabled = 1, updated_at = NOW()');
            $stmt->execute([$folderName]);
            header('Location: ignored-folders.php');
            exit;
        }
        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
            if ($id <= 0) {
                throw new RuntimeException('Invalid ignored folder ID.');
            }
            $stmt = db()->prepare('UPDATE ignored_folder_names SET is_enabled = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$enabled, $id]);
            header('Location: ignored-folders.php');
            exit;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid ignored folder ID.');
            }
            $stmt = db()->prepare('DELETE FROM ignored_folder_names WHERE id = ?');
            $stmt->execute([$id]);
            header('Location: ignored-folders.php');
            exit;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

try {
    $folders = db()->query('SELECT * FROM ignored_folder_names ORDER BY is_enabled DESC, folder_name ASC')->fetchAll();
} catch (Throwable $e) {
    $folders = [];
    $error = trim(($error ? $error . ' | ' : '') . 'Could not load ignored folders. Run migration 012_ignored_folder_names.sql first. ' . $e->getMessage());
}

render_header('Ignored Folders');
?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>

<div class="card">
    <h2>Ignored folders</h2>
    <p class="muted">Ignored folder names stay in scan data and drive usage totals, but are hidden from normal file browsing/search/category/planning workflows.</p>
    <form method="post">
        <input type="hidden" name="action" value="add">
        <div class="form-grid compact-search">
            <label>Folder name <input type="text" name="folder_name" placeholder="Example: @eaDir"></label>
        </div>
        <p><button type="submit">Add ignored folder</button> <a class="button" href="files.php">Files</a></p>
    </form>
</div>

<div class="card">
    <h2>Configured ignored folders</h2>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>Folder name</th><th>Enabled</th><th>Updated</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($folders as $folder): ?>
                <tr>
                    <td><?= e($folder['folder_name']) ?></td>
                    <td><?= (int)$folder['is_enabled'] === 1 ? 'Yes' : 'No' ?></td>
                    <td><?= e($folder['updated_at']) ?></td>
                    <td class="actions">
                        <form method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= e((string)$folder['id']) ?>"><input type="hidden" name="enabled" value="<?= (int)$folder['is_enabled'] === 1 ? '0' : '1' ?>"><button type="submit"><?= (int)$folder['is_enabled'] === 1 ? 'Disable' : 'Enable' ?></button></form>
                        <form method="post" onsubmit="return confirm('Delete this ignored folder name?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e((string)$folder['id']) ?>"><button class="danger" type="submit">Delete</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$folders): ?><tr><td colspan="4">No ignored folder names configured.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
