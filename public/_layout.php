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
    </head>
    <body>
    <header><h1>DriveMaster</h1></header>
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="drives.php">Drives</a>
        <a href="files.php">Files</a>
        <a href="categories.php">Categories</a>
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
