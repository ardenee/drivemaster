<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

render_header('Create Admin User');
?>
<div class="card"><h2>Create first admin user</h2><p>This page is ready for the admin creation form.</p></div>
<?php render_footer(); ?>
