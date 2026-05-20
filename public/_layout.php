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
        <script src="assets/files-tree-context.js" defer></script>
    </head>
    <body>
    <header><h1>DriveMaster</h1></header>
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="scan.php">Scan</a>
        <a href="jobs.php">Jobs</a>
        <a href="drives.php">Drives</a>
        <a href="files.php">Files</a>
        <a href="ignored-folders.php">Ignored Folders</a>
        <span class="nav-dropdown">
            <button type="button" class="nav-dropdown-button">Categories ▾</button>
            <span class="nav-dropdown-menu">
                <a href="category-workflow.php">Category Workflow</a>
                <a href="category-workflow-summary.php">Workflow Summary</a>
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
                <a href="planning-dashboard.php">Planning Dashboard</a>
                <a href="planner-readiness.php">Planner Readiness</a>
                <a href="move-planner.php">Move Planner</a>
                <a href="plan-builder.php">Plan Builder</a>
                <a href="move-candidates.php">Move Candidates</a>
                <a href="move-plans.php">Saved Plans</a>
                <a href="move-executions.php">Execution Runs</a>
                <a href="planning-maintenance.php">Maintenance</a>
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
