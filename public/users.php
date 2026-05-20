<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\Auth;

function users_is_default_admin(array $user): bool
{
    return (int)$user['id'] === 1 || strcasecmp((string)$user['username'], 'admin') === 0;
}

function users_load(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, username, display_name, role, is_active FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function users_admin_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
}

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

        if ($action === 'create') {
            $username = trim((string)($_POST['username'] ?? ''));
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $role = (string)($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $secret = (string)($_POST['access_value'] ?? '');
            $confirm = (string)($_POST['access_confirm'] ?? '');
            if ($secret !== $confirm) {
                throw new RuntimeException('Access entries do not match.');
            }
            if (strlen($secret) < 10) {
                throw new RuntimeException('Access value must be at least 10 characters.');
            }
            Auth::createUser(db(), $username, $displayName, $secret, $role, 1);
            $message = 'User created.';
        }

        if ($action === 'toggle' && $id > 0) {
            $user = users_load($id);
            if (!$user) {
                throw new RuntimeException('User not found.');
            }
            if (users_is_default_admin($user)) {
                throw new RuntimeException('The default admin account cannot be disabled.');
            }
            if ((int)$user['is_active'] === 1 && (string)$user['role'] === 'admin' && users_admin_count() <= 1) {
                throw new RuntimeException('Cannot disable the last active administrator.');
            }
            db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            $message = 'User status updated.';
        }

        if ($action === 'role' && $id > 0) {
            $user = users_load($id);
            if (!$user) {
                throw new RuntimeException('User not found.');
            }
            $role = (string)($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            if (users_is_default_admin($user) && $role !== 'admin') {
                throw new RuntimeException('The default admin account cannot be changed to a user.');
            }
            if ((string)$user['role'] === 'admin' && $role !== 'admin' && users_admin_count() <= 1) {
                throw new RuntimeException('Cannot demote the last active administrator.');
            }
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
            $message = 'User role updated.';
        }

        if ($action === 'delete' && $id > 0) {
            $user = users_load($id);
            if (!$user) {
                throw new RuntimeException('User not found.');
            }
            if (users_is_default_admin($user)) {
                throw new RuntimeException('The default admin account cannot be deleted.');
            }
            if ($id === (int)(current_user()['id'] ?? 0)) {
                throw new RuntimeException('You cannot delete the account you are currently using.');
            }
            if ((string)$user['role'] === 'admin' && (int)$user['is_active'] === 1 && users_admin_count() <= 1) {
                throw new RuntimeException('Cannot delete the last active administrator.');
            }
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $message = 'User deleted.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$users = db()->query('SELECT id, username, display_name, role, is_active, last_login_at, created_at FROM users ORDER BY username')->fetchAll();
render_header('Users');
?>
<div class="page-shell">
    <div class="page-heading">
        <div>
            <h2>User management</h2>
            <p class="muted">Create users, assign roles, and disable access without deleting history.</p>
        </div>
        <div class="status-bar">Users: <?= e(number_format(count($users))) ?> | Active admins: <?= e(number_format(users_admin_count())) ?></div>
    </div>

    <?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong></div><?php endif; ?>
    <?php if ($error): ?><div class="card danger-zone"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
    <?php if (!$users): ?><div class="card"><strong>No users exist yet.</strong> Create the first admin account below. Until a user exists, DriveMaster remains accessible to avoid lockout.</div><?php endif; ?>

    <div class="admin-grid">
        <div class="card admin-card">
            <h2>Create user</h2>
            <p class="muted">New accounts are active immediately. Use at least 10 characters for the access value.</p>
            <form method="post" class="admin-form">
                <input type="hidden" name="action" value="create">
                <label>Username
                    <input type="text" name="username" required autocomplete="username">
                </label>
                <label>Display name
                    <input type="text" name="display_name">
                </label>
                <label>Role
                    <select name="role"><option value="user">User</option><option value="admin" <?= !$users ? 'selected' : '' ?>>Admin</option></select>
                </label>
                <label>Access value
                    <input type="password" name="access_value" required autocomplete="new-password">
                </label>
                <label>Confirm access value
                    <input type="password" name="access_confirm" required autocomplete="new-password">
                </label>
                <button type="submit">Create user</button>
            </form>
        </div>

        <div class="card admin-card">
            <h2>Existing users</h2>
            <p class="muted">The default admin account is protected and cannot be demoted, disabled, or deleted.</p>
            <div class="table-scroll">
                <table class="auto-table compact-table">
                    <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last login</th><th>Created</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php $isDefaultAdmin = users_is_default_admin($user); ?>
                        <tr>
                            <td><strong><?= e($user['username']) ?></strong><?= $isDefaultAdmin ? ' <span class="protected-badge">protected</span>' : '' ?><br><span class="muted"><?= e($user['display_name']) ?></span></td>
                            <td><span class="user-role-badge <?= e($user['role']) ?>"><?= e(ucfirst((string)$user['role'])) ?></span></td>
                            <td><span class="user-status-badge <?= (int)$user['is_active'] === 1 ? 'active' : 'disabled' ?>"><?= (int)$user['is_active'] === 1 ? 'Active' : 'Disabled' ?></span></td>
                            <td><?= e($user['last_login_at'] ?? 'Never') ?></td>
                            <td><?= e($user['created_at']) ?></td>
                            <td>
                                <?php if (!$isDefaultAdmin): ?>
                                    <div class="table-actions">
                                        <form method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= e((string)$user['id']) ?>"><button type="submit"><?= (int)$user['is_active'] === 1 ? 'Disable' : 'Enable' ?></button></form>
                                        <form method="post"><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= e((string)$user['id']) ?>"><input type="hidden" name="role" value="<?= $user['role'] === 'admin' ? 'user' : 'admin' ?>"><button type="submit">Make <?= $user['role'] === 'admin' ? 'User' : 'Admin' ?></button></form>
                                        <form method="post" onsubmit="return confirm('Delete this user?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e((string)$user['id']) ?>"><button class="danger" type="submit">Delete</button></form>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">Protected</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$users): ?><tr><td colspan="6">No users found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>
