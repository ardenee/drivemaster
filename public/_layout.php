<?php

function render_header(string $title): void
{
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> - DriveMaster</title>
        <link rel="stylesheet" href="assets/style.css">
        <script src="assets/files-extra.js" defer></script>
    </head>
    <body>
    <header><h1>DriveMaster</h1></header>
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="scan.php">Scan</a>
        <a href="jobs.php">Jobs</a>
        <a href="drives.php">Drives</a>
        <a href="files.php">Files</a>
        <a href="categories.php">Categories</a>
        <a href="errors.php">Errors</a>
        <a href="health.php">Health</a>
    </nav>
    <main>
    <?php
}

function render_footer(): void
{
    ?>
    </main>
    </body>
    </html>
    <?php
}
