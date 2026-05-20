<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Support\Auth;

$error = null;
$next = (string)($_GET['next'] ?? 'index.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $next = 'index.php';
    $username = (string)($_POST['username'] ?? '');
    $access = (string)($_POST['access'] ?? '');
    if (Auth::checkLogin(db(), $username, $access)) {
        header('Location: ' . $next);
        exit;
    }
    $error = 'Sign-in failed.';
}

render_header('Login');
?>
<div class="auth-shell">
    <div class="card auth-card">
        <h2>Sign in</h2>
        <p class="muted">Enter your DriveMaster user details.</p>
        <?php if ($error): ?><div class="status-bar"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
        <form method="post" class="auth-form">
            <label>Username
                <input type="text" name="username" required autocomplete="username" autofocus>
            </label>
            <label>Access
                <input type="password" name="access" required autocomplete="current-password">
            </label>
            <div class="auth-actions">
                <button type="submit">Login</button>
            </div>
        </form>
    </div>
</div>
<?php render_footer(); ?>
