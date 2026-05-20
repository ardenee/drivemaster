<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

render_header('Help');
?>
<div class="page-shell">
    <div class="page-heading">
        <div>
            <h2>DriveMaster Help</h2>
            <p class="muted">Usage guide, workflow order, and route reference for the current application.</p>
        </div>
        <div class="status-bar">Recommended order: scan → categorise → plan → dry-run → simulate → move</div>
    </div>

    <div class="card">
        <h2>What DriveMaster does</h2>
        <p>DriveMaster scans drives, stores a searchable inventory of files/folders, lets you assign categories, maps categories to target drives, builds safe move plans, validates those plans, performs dry-runs, prepares execution records, simulates execution, and can optionally perform guarded file moves.</p>
        <p><strong>Important:</strong> do not enable actual moves until the full workflow has been tested on a tiny disposable folder.</p>
    </div>

    <div class="card">
        <h2>Normal usage process</h2>
        <ol>
            <li><strong>Add/scan drives:</strong> use <a href="drives.php">Drives</a> and <a href="scan.php">Scan</a> to register and scan source/target storage.</li>
            <li><strong>Review inventory:</strong> use <a href="files.php">Files</a> to browse scanned files and folders.</li>
            <li><strong>Configure ignored folders:</strong> use <a href="ignored-folders.php">Ignored Folders</a> for folders that should be ignored by duplicate/category logic.</li>
            <li><strong>Create categories:</strong> use <a href="categories.php">Manage Categories</a>.</li>
            <li><strong>Assign categories:</strong> use <a href="category-workflow.php">Category Workflow</a> to visually work through uncategorised folders/files.</li>
            <li><strong>Set target drives:</strong> use <a href="category-targets.php">Target Drives</a> to tell each category where it should move.</li>
            <li><strong>Review safe candidates:</strong> use <a href="move-candidates.php">Move Candidates</a>.</li>
            <li><strong>Build a saved plan:</strong> use <a href="plan-builder.php">Plan Builder</a>. This stores a snapshot; no files move.</li>
            <li><strong>Validate the saved plan:</strong> open <a href="move-plans.php">Saved Plans</a>, view a plan, and run validation.</li>
            <li><strong>Queue/review dry-run:</strong> from the plan view, queue a dry-run and review <strong>Dry-run Reports</strong>.</li>
            <li><strong>Prepare execution:</strong> from a validated plan, prepare execution records. No files move.</li>
            <li><strong>Final safety check:</strong> from the execution view, run final safety check.</li>
            <li><strong>Simulate execution:</strong> run simulation first. Simulation only marks database audit rows; it does not move files.</li>
            <li><strong>Actual moves:</strong> only after testing, enable actual moves in <a href="move-execution-settings.php">Execution Settings</a> and use the confirmation phrase.</li>
        </ol>
    </div>

    <div class="card">
        <h2>Main routes</h2>
        <div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Path</th><th>Purpose</th><th>Use when</th></tr></thead><tbody>
            <tr><td><a href="index.php">index.php</a></td><td>Dashboard/home.</td><td>Starting point and high-level overview.</td></tr>
            <tr><td><a href="scan.php">scan.php</a></td><td>Queue drive scans.</td><td>You need to scan or rescan a drive.</td></tr>
            <tr><td><a href="jobs.php">jobs.php</a></td><td>Background job list.</td><td>Checking scans, dry-runs, or queued work.</td></tr>
            <tr><td><a href="drives.php">drives.php</a></td><td>Drive registration/status.</td><td>Managing known drives and refresh status.</td></tr>
            <tr><td><a href="files.php">files.php</a></td><td>Inventory browser.</td><td>Browsing scanned folders/files visually.</td></tr>
            <tr><td><a href="ignored-folders.php">ignored-folders.php</a></td><td>Ignored folder names/rules.</td><td>Excluding noise folders such as metadata/cache folders.</td></tr>
            <tr><td><a href="errors.php">errors.php</a></td><td>Error list.</td><td>Reviewing scan/app errors.</td></tr>
            <tr><td><a href="health.php">health.php</a></td><td>Health/status checks.</td><td>Checking app/database state.</td></tr>
            <tr><td><a href="help.php">help.php</a></td><td>This help page.</td><td>Finding the correct workflow/page.</td></tr>
        </tbody></table></div>
    </div>

    <div class="card">
        <h2>Category routes</h2>
        <div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Path</th><th>Purpose</th><th>Status / notes</th></tr></thead><tbody>
            <tr><td><a href="category-workflow.php">category-workflow.php</a></td><td>Main visual categorisation workflow.</td><td>Primary category working page.</td></tr>
            <tr><td><a href="category-workflow-summary.php">category-workflow-summary.php</a></td><td>Summary of workflow/categorisation progress.</td><td>Useful but can be slower on large data.</td></tr>
            <tr><td><a href="categories.php">categories.php</a></td><td>Create/manage categories and colours.</td><td>Required before assigning/moving.</td></tr>
            <tr><td><a href="category-targets.php">category-targets.php</a></td><td>Map categories to target drives.</td><td>Required before move planning.</td></tr>
            <tr><td><a href="category-preview.php">category-preview.php</a></td><td>Preview category matching.</td><td>Diagnostic/review page.</td></tr>
            <tr><td><a href="category-coverage.php">category-coverage.php</a></td><td>Coverage report.</td><td>May be expensive on large databases; use sparingly.</td></tr>
            <tr><td><a href="category-conflicts.php">category-conflicts.php</a></td><td>Category conflict review.</td><td>Required to keep unsafe moves blocked.</td></tr>
            <tr><td><a href="category-drive-usage.php">category-drive-usage.php</a></td><td>Drive usage by category/target.</td><td>Planning aid.</td></tr>
        </tbody></table></div>
    </div>

    <div class="card">
        <h2>Planning and execution routes</h2>
        <div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Path</th><th>Purpose</th><th>Use when</th></tr></thead><tbody>
            <tr><td><a href="planning-dashboard.php">planning-dashboard.php</a></td><td>Planning/execution status dashboard.</td><td>Checking overall state.</td></tr>
            <tr><td><a href="planner-readiness.php">planner-readiness.php</a></td><td>Readiness checks.</td><td>Before planning moves.</td></tr>
            <tr><td><a href="move-planner.php">move-planner.php</a></td><td>Original/planning interface.</td><td>Review; may overlap with Plan Builder.</td></tr>
            <tr><td><a href="plan-builder.php">plan-builder.php</a></td><td>Build saved move-plan snapshots in batches.</td><td>Primary page for creating plans.</td></tr>
            <tr><td><a href="move-candidates.php">move-candidates.php</a></td><td>Safe candidate list.</td><td>Verify what can move before snapshotting.</td></tr>
            <tr><td><a href="move-plans.php">move-plans.php</a></td><td>Saved move plans.</td><td>Open/continue/validate saved plans.</td></tr>
            <tr><td>move-plan-view.php?id=...</td><td>Single saved plan view.</td><td>Validate plan, queue dry-run, prepare execution.</td></tr>
            <tr><td>move-plan-dry-runs.php?id=...</td><td>Dry-run reports for a plan.</td><td>Review dry-run issues before execution prep.</td></tr>
            <tr><td>move-plan-queue.php</td><td>Queues dry-run jobs.</td><td>Internal POST action from plan view.</td></tr>
            <tr><td><a href="move-executions.php">move-executions.php</a></td><td>Execution run list.</td><td>Review prepared/simulated/complete execution runs.</td></tr>
            <tr><td>move-execution-view.php?id=...</td><td>Single execution run view.</td><td>Final safety check, simulate, or actual move.</td></tr>
            <tr><td>move-execution-prepare.php</td><td>Creates/reuses execution records.</td><td>Internal POST action from plan view.</td></tr>
            <tr><td>move-execution-queue.php</td><td>Runs simulation or guarded actual execution.</td><td>Internal POST action from execution view.</td></tr>
            <tr><td><a href="move-execution-settings.php">move-execution-settings.php</a></td><td>Execution safety settings.</td><td>Enable/disable actual move mode and confirmation requirements.</td></tr>
            <tr><td><a href="planning-maintenance.php">planning-maintenance.php</a></td><td>Planning database cleanup/index tools.</td><td>Fix duplicate rows, add indexes, resync counts.</td></tr>
        </tbody></table></div>
    </div>

    <div class="card">
        <h2>User/account routes</h2>
        <div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Path</th><th>Purpose</th><th>Notes</th></tr></thead><tbody>
            <tr><td><a href="users.php">users.php</a></td><td>User management.</td><td>Admins only once users exist. Default admin is protected.</td></tr>
            <tr><td><a href="login.php">login.php</a></td><td>Sign in.</td><td>Used once at least one user exists.</td></tr>
            <tr><td><a href="logout.php">logout.php</a></td><td>Sign out.</td><td>Ends current session.</td></tr>
            <tr><td><a href="setup-admin.php">setup-admin.php</a></td><td>Legacy setup placeholder.</td><td>Likely redundant now that users.php handles first account creation.</td></tr>
        </tbody></table></div>
    </div>

    <div class="card danger-zone">
        <h2>Code review: pages that may not be needed long-term</h2>
        <p class="muted">Do not delete these until the current workflow has been tested. These are candidates for consolidation, not confirmed safe deletions.</p>
        <ul>
            <li><strong>setup-admin.php</strong> — likely redundant because <a href="users.php">users.php</a> can create the first account and user management now lives there.</li>
            <li><strong>move-planner.php</strong> — may be partly superseded by <a href="plan-builder.php">plan-builder.php</a> and <a href="move-candidates.php">move-candidates.php</a>. Keep until you confirm no unique features remain.</li>
            <li><strong>category-coverage.php</strong> — previously caused 504s on large data. Keep only if the optimised/current version is genuinely useful.</li>
            <li><strong>category-workflow-summary.php</strong> — useful but slow on large datasets. Consider keeping as a report, not part of the normal workflow.</li>
            <li><strong>move-execution-settings.php</strong> — keep while actual move mode exists; not optional.</li>
            <li><strong>planning-maintenance.php</strong> — admin/maintenance only; keep hidden or admin-only, but do not delete yet.</li>
        </ul>
    </div>
</div>
<?php render_footer(); ?>
