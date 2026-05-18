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
        <span class="nav-dropdown">
            <button type="button" class="nav-dropdown-button">Categories ▾</button>
            <span class="nav-dropdown-menu">
                <a href="categories.php">Manage Categories</a>
                <a href="category-targets.php">Target Drives</a>
                <a href="category-preview.php">Preview</a>
                <a href="category-coverage.php">Coverage</a>
                <a href="category-conflicts.php">Conflicts</a>
                <a href="category-drive-usage.php">Drive Usage</a>
            </span>
        </span>
        <span class="nav-dropdown">
            <button type="button" class="nav-dropdown-button">Planning ▾</button>
            <span class="nav-dropdown-menu">
                <a href="planner-readiness.php">Planner Readiness</a>
                <a href="move-planner.php">Move Planner</a>
                <a href="move-candidates.php">Move Candidates</a>
            </span>
        </span>
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
