<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\Auth;

Auth::ensureTable(db());
if (Auth::userCount(db()) > 0) {
    require_admin();
}

$message = null;
$error = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $id = max(0, (int)($_POST['id'] ?? 0));
        if ($action === 'toggle' && $id > 0) {
            db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            $message = 'User status updated.';
        }
        if ($action === 'role' && $id > 0) {
            $role = (string)($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
            $message = 'User role updated.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$users = db()->query('SELECT id, username, display_name, role, is_active, last_login_at, created_at FROM users ORDER BY username')->fetchAll();
render_header('Users');
?>
<?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong></div><?php endif; ?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
<?php if (!$users): ?><div class="card"><strong>No users exist yet.</strong> Add the first row directly in the database, then this page can manage status and role.</div><?php endif; ?>
<div class="card">
    <h2>Users</h2>
    <p class="muted">Administrators can enable/disable users and change roles here.</p>
    <div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Active</th><th>Last login</th><th>Created</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($users as $user): ?>
        <tr>
            <td><?= e($user['username']) ?></td><td><?= e($user['display_name']) ?></td><td><?= e($user['role']) ?></td><td><?= (int)$user['is_active'] === 1 ? 'Yes' : 'No' ?></td><td><?= e($user['last_login_at'] ?? '') ?></td><td><?= e($user['created_at']) ?></td>
            <td class="actions"><form method="post" style="display:inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= e((string)$user['id']) ?>"><button type="submit"><?= (int)$user['is_active'] === 1 ? 'Disable' : 'Enable' ?></button></form> <form method="post" style="display:inline"><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= e((string)$user['id']) ?>"><input type="hidden" name="role" value="<?= $user['role'] === 'admin' ? 'user' : 'admin' ?>"><button type="submit">Make <?= $user['role'] === 'admin' ? 'User' : 'Admin' ?></button></form></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?><tr><td colspan="7">No users found.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php render_footer(); ?>
