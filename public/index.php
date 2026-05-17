<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$stats = [
    'drives' => (int)db()->query('SELECT COUNT(*) FROM drives')->fetchColumn(),
    'files' => (int)db()->query('SELECT COUNT(*) FROM file_entries WHERE entry_type = "file" AND is_missing = 0')->fetchColumn(),
    'folders' => (int)db()->query('SELECT COUNT(*) FROM file_entries WHERE entry_type = "folder" AND is_missing = 0')->fetchColumn(),
    'bytes' => (int)db()->query('SELECT COALESCE(SUM(size_bytes), 0) FROM file_entries WHERE entry_type = "file" AND is_missing = 0')->fetchColumn(),
];

render_header('Dashboard');
?>
<div class="card">
    <h2>Dashboard</h2>
    <p class="muted">Phase 1 inventory overview.</p>
</div>

<div class="card">
    <table>
        <tr><th>Known drives</th><td><?= e((string)$stats['drives']) ?></td></tr>
        <tr><th>Files indexed</th><td><?= e((string)$stats['files']) ?></td></tr>
        <tr><th>Folders indexed</th><td><?= e((string)$stats['folders']) ?></td></tr>
        <tr><th>Total indexed size</th><td><?= e(format_bytes($stats['bytes'])) ?></td></tr>
    </table>
</div>
<?php render_footer(); ?>
