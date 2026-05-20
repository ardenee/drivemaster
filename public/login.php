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
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
<div class="card">
    <h2>Login</h2>
    <form method="post">
        <div class="form-grid">
            <label>Username <input type="text" name="username" required></label>
            <label>Access <input type="password" name="access" required></label>
        </div>
        <button type="submit">Login</button>
    </form>
</div>
<?php render_footer(); ?>
