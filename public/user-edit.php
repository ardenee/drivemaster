<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\Auth;

function ue_is_default_admin(array $user): bool
{
    return (int)$user['id'] === 1 || strcasecmp((string)$user['username'], 'admin') === 0;
}

function ue_load_user(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, username, display_name, role, is_active, last_login_at, created_at FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function ue_admin_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
}

Auth::ensureTable(db());
require_admin();

$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$user = $id > 0 ? ue_load_user($id) : null;
$message = null;
$error = null;

try {
    if (!$user) {
        throw new RuntimeException('User not found.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim((string)($_POST['username'] ?? ''));
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $newAccess = (string)($_POST['access_value'] ?? '');
        $confirmAccess = (string)($_POST['access_confirm'] ?? '');

        if ($username === '') {
            throw new RuntimeException('Username is required.');
        }
        if (strlen($username) > 80) {
            throw new RuntimeException('Username must be 80 characters or less.');
        }
        if (strlen($displayName) > 120) {
            throw new RuntimeException('Display name must be 120 characters or less.');
        }
        if (ue_is_default_admin($user) && strcasecmp($username, 'admin') !== 0) {
            throw new RuntimeException('The default admin username cannot be changed.');
        }

        $dupe = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
        $dupe->execute([$username, $id]);
        if ($dupe->fetch()) {
            throw new RuntimeException('That username is already in use.');
        }

        if ($newAccess !== '' || $confirmAccess !== '') {
            if ($newAccess !== $confirmAccess) {
                throw new RuntimeException('Access entries do not match.');
            }
            if (strlen($newAccess) < 10) {
                throw new RuntimeException('Access value must be at least 10 characters.');
            }
            $stmt = db()->prepare('UPDATE users SET username = ?, display_name = ?, password_hash = ? WHERE id = ?');
            $stmt->execute([$username, $displayName !== '' ? $displayName : $username, password_hash($newAccess, PASSWORD_DEFAULT), $id]);
        } else {
            $stmt = db()->prepare('UPDATE users SET username = ?, display_name = ? WHERE id = ?');
            $stmt->execute([$username, $displayName !== '' ? $displayName : $username, $id]);
        }

        $message = 'User updated.';
        $user = ue_load_user($id);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

render_header('Edit User');
?>
<div class="page-shell">
    <div class="page-heading">
        <div>
            <h2>Edit user</h2>
            <p class="muted">Update username, display name, or access value.</p>
        </div>
        <div class="button-row"><a class="button" href="users.php">Back to Users</a></div>
    </div>

    <?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong></div><?php endif; ?>
    <?php if ($error): ?><div class="card danger-zone"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>

    <?php if ($user): ?>
        <div class="card admin-card">
            <h2><?= e((string)$user['username']) ?></h2>
            <p class="muted">Leave the access value fields blank to keep the current password.</p>
            <?php if (ue_is_default_admin($user)): ?><p><span class="protected-badge">protected</span> The default admin username cannot be changed.</p><?php endif; ?>
            <form method="post" class="admin-form">
                <input type="hidden" name="id" value="<?= e((string)$user['id']) ?>">
                <label>Username
                    <input type="text" name="username" value="<?= e((string)$user['username']) ?>" required autocomplete="username" <?= ue_is_default_admin($user) ? 'readonly' : '' ?>>
                </label>
                <label>Display name
                    <input type="text" name="display_name" value="<?= e((string)$user['display_name']) ?>">
                </label>
                <label>New access value
                    <input type="password" name="access_value" autocomplete="new-password">
                </label>
                <label>Confirm new access value
                    <input type="password" name="access_confirm" autocomplete="new-password">
                </label>
                <div class="status-bar">Role: <?= e(ucfirst((string)$user['role'])) ?> | Status: <?= (int)$user['is_active'] === 1 ? 'Active' : 'Disabled' ?> | Active admins: <?= e(number_format(ue_admin_count())) ?></div>
                <button type="submit">Save user</button>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
