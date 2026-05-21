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
            <p class="muted">Operational guide, safe workflow order, and route reference.</p>
        </div>
        <div class="status-bar">Process: scan → categorise → assign targets → build plan → dry-run → verify drives → execute → verify → undo if needed</div>
    </div>

    <div class="card">
        <h2>What DriveMaster is for</h2>
        <p>DriveMaster is designed to manage large collections spread across many removable or occasionally connected drives. It scans each drive into a database, lets you categorise folders/files, calculates where data should live, builds move plans, checks what can safely be moved, records all move activity, and provides an undo process for logged jobs.</p>
        <p><strong>Important:</strong> DriveMaster should be treated as a guided move planner, not a blind file mover. Always use dry-run, simulation, and verification before enabling actual moves.</p>
    </div>

    <div class="card">
        <h2>Basic terms</h2>
        <div class="table-scroll">
            <table class="auto-table compact-table">
                <thead><tr><th>Term</th><th>Meaning</th></tr></thead>
                <tbody>
                    <tr><td>Drive</td><td>A physical/removable/source/target disk registered in DriveMaster.</td></tr>
                    <tr><td>Scan</td><td>Reads a connected drive and stores file/folder names, paths, sizes, and timestamps in the database.</td></tr>
                    <tr><td>Ignored folder</td><td>A folder name that should be hidden from inventory logic, category logic, preview, and move planning. Examples: metadata/cache/system folders.</td></tr>
                    <tr><td>Category</td><td>A label for a type of data, such as Music, Photos, Movies, Documents, Backups, or Projects.</td></tr>
                    <tr><td>Manual assignment</td><td>A category manually assigned to a specific file or folder. Folder assignments also cover the folder contents.</td></tr>
                    <tr><td>Rule assignment</td><td>A category matched automatically from rules such as extension, folder path, name contains, or path contains.</td></tr>
                    <tr><td>Target drive</td><td>The drive where a category should eventually live.</td></tr>
                    <tr><td>Conflict</td><td>An item that matches more than one category. Conflicts are excluded from safe move planning until resolved or accepted.</td></tr>
                    <tr><td>Move plan</td><td>A saved snapshot of intended moves. Creating a plan does not move files.</td></tr>
                    <tr><td>Dry-run</td><td>A check that verifies required files/drives/paths before moving anything.</td></tr>
                    <tr><td>Execution run</td><td>The logged move job created from a validated plan.</td></tr>
                    <tr><td>Undo</td><td>A logged reverse operation based on a previous execution run.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Full recommended workflow</h2>
        <ol>
            <li><strong>Configure ignored folders first:</strong> open <a href="ignored-folders.php">Ignored Folders</a> and add names that should not be included in category/move logic, such as NAS metadata folders. Do this before trusting category or move reports.</li>
            <li><strong>Register and scan each drive:</strong> connect one drive, use <a href="drives.php">Drives</a> and <a href="scan.php">Scan</a>, wait for the scan job in <a href="jobs.php">Jobs</a>, then repeat for the next drive.</li>
            <li><strong>Review the scanned inventory:</strong> use <a href="files.php">Files</a> to visually confirm the drive contents look correct and ignored folders are not shown.</li>
            <li><strong>Create categories:</strong> use <a href="categories.php">Manage Categories</a> to create your data groups. Use clear names because these drive the move-planning process.</li>
            <li><strong>Assign folders/files to categories:</strong> use <a href="category-status.php?mode=unassigned">Category Status</a>. Start with one drive, review uncategorised folders, and assign the highest useful folder level to a category. Once assigned, it should leave the uncategorised view.</li>
            <li><strong>Use rules only where safe:</strong> rules are useful for obvious cases such as extensions or known folder names, but broad rules can create conflicts. Preview rules before trusting them.</li>
            <li><strong>Review categorisation progress:</strong> use <a href="category-status-summary.php">Status Summary</a>, <a href="category-preview.php">Category Preview</a>, and <a href="category-coverage.php">Coverage</a> to check what is categorised and what remains.</li>
            <li><strong>Resolve conflicts:</strong> use <a href="category-conflicts.php">Conflicts</a>. Start with one drive/filter. Conflicted items are unsafe for automatic moving because DriveMaster cannot know which category should win.</li>
            <li><strong>Assign target drives:</strong> use <a href="category-targets.php">Target Drives</a> to say where each category should be moved. A category without a target cannot produce safe move candidates.</li>
            <li><strong>Check planning readiness:</strong> use <a href="planner-readiness.php">Planner Readiness</a>. Fix blockers before building a move plan.</li>
            <li><strong>Review candidates:</strong> use <a href="move-candidates.php">Move Candidates</a> to confirm the files DriveMaster thinks are safe to move.</li>
            <li><strong>Build a saved move plan:</strong> use <a href="plan-builder.php">Plan Builder</a>. This creates a database snapshot only; it does not move files.</li>
            <li><strong>Validate the plan:</strong> open <a href="move-plans.php">Saved Plans</a>, choose the plan, and run validation from the plan view.</li>
            <li><strong>Run a dry-run:</strong> queue a dry-run and review the dry-run report. This is where missing drives/files/path mismatches should be found before any move is attempted.</li>
            <li><strong>Prepare execution:</strong> once validation and dry-run look correct, prepare an execution run. This creates logged operation records and still does not move files by itself.</li>
            <li><strong>Simulate first:</strong> run simulation from the execution view. Simulation should prove the process path without moving files.</li>
            <li><strong>Enable actual moves only when ready:</strong> use <a href="move-execution-settings.php">Execution Settings</a> to enable actual move mode and required confirmation. Leave actual moves disabled while testing.</li>
            <li><strong>Run actual execution in batches:</strong> execute only when the required source/target drives are connected and verified.</li>
            <li><strong>Verify drive changes before swapping drives:</strong> use <a href="drive-verify.php">Verify Drive</a> after a batch completes and before disconnecting/changing drives.</li>
            <li><strong>Use undo if needed:</strong> use <a href="undo.php">Undo</a> to dry-run and then execute a reverse operation from a logged run.</li>
        </ol>
    </div>

    <div class="card">
        <h2>Scanning drives</h2>
        <ol>
            <li>Connect the drive you want to scan.</li>
            <li>Open <a href="drives.php">Drives</a> and confirm the drive exists with the correct friendly name and mount/path details.</li>
            <li>Open <a href="scan.php">Scan</a> and start the scan for that drive.</li>
            <li>Open <a href="jobs.php">Jobs</a> and wait for the scan to finish.</li>
            <li>Open <a href="files.php">Files</a> and browse the drive to confirm the inventory is correct.</li>
            <li>Repeat for every drive. DriveMaster can only plan correctly from the data it has scanned.</li>
        </ol>
        <p><strong>Why scanning matters:</strong> move planning is database-driven. If a drive has not been scanned recently, DriveMaster may plan from stale file paths/sizes. Rescan any drive that has been changed outside DriveMaster.</p>
    </div>

    <div class="card">
        <h2>Ignored folders</h2>
        <p>Ignored folders are used to keep system/cache/metadata folders out of normal inventory views and category/move logic.</p>
        <ol>
            <li>Open <a href="ignored-folders.php">Ignored Folders</a>.</li>
            <li>Add folder names that should be excluded everywhere practical.</li>
            <li>Review <a href="files.php">Files</a>, <a href="category-status.php">Category Status</a>, and <a href="category-preview.php">Category Preview</a> to confirm ignored folders are not showing.</li>
        </ol>
        <p><strong>Important:</strong> ignored folder rules work by folder name/path. If something still appears, it usually means that page has a direct inventory query that still needs to use the shared ignore-filter helper.</p>
    </div>

    <div class="card">
        <h2>Creating and assigning categories</h2>
        <ol>
            <li>Open <a href="categories.php">Manage Categories</a>.</li>
            <li>Create broad, stable categories first. Examples: Music, Photos, Video, Documents, Software, Backups.</li>
            <li>Use colours to make categories easier to recognise in file/folder views.</li>
            <li>Open <a href="category-status.php?mode=unassigned">Category Status</a>.</li>
            <li>Select one drive and work through uncategorised folders visually.</li>
            <li>Right-click a folder/file and add it to the correct category.</li>
            <li>Prefer assigning the highest correct folder instead of hundreds of individual files.</li>
            <li>Switch to <a href="category-status.php?mode=assigned">Show categorised</a> to confirm the assignment took effect.</li>
        </ol>
        <p><strong>Why categories matter:</strong> DriveMaster does not move files because of their current location alone. The safe automated process depends on category membership plus the target drive mapping for that category.</p>
    </div>

    <div class="card">
        <h2>Rules, previews, and conflicts</h2>
        <ol>
            <li>Use rules for repeatable patterns only, such as file extensions or a known folder path.</li>
            <li>Use <a href="category-preview.php">Category Preview</a> to check what a category/rule currently matches.</li>
            <li>Use <a href="category-conflicts.php">Conflicts</a> to find items that match more than one category.</li>
            <li>Resolve conflicts by changing assignments/rules, or accepting a conflict only when you intentionally want it ignored by safety checks.</li>
        </ol>
        <p><strong>Safety rule:</strong> a file with multiple category matches should not be moved automatically because DriveMaster cannot safely decide which target drive is correct.</p>
    </div>

    <div class="card">
        <h2>Target drives and automated assignment</h2>
        <ol>
            <li>Open <a href="category-targets.php">Target Drives</a>.</li>
            <li>For each category, choose the drive where that category should live.</li>
            <li>Use <a href="category-drive-usage.php">Drive Usage</a> to review where category data currently exists versus where it should go.</li>
            <li>Use <a href="planner-readiness.php">Planner Readiness</a> to check whether categories, targets, and assignments are sufficient for planning.</li>
            <li>Use <a href="move-candidates.php">Move Candidates</a> to review files that are safe to move according to the current category/target rules.</li>
        </ol>
        <p><strong>Automated assignment process:</strong> once an item has exactly one effective category and that category has an enabled target drive, DriveMaster can calculate whether the file is already on the correct drive or should be moved to the target drive. Conflicts, missing targets, missing files, ignored folders, and same-drive items are excluded from safe candidates.</p>
    </div>

    <div class="card">
        <h2>Building a move plan</h2>
        <ol>
            <li>Open <a href="plan-builder.php">Plan Builder</a>.</li>
            <li>Choose the category/target/current-drive filters you want to plan.</li>
            <li>Build the plan in batches if the data set is large.</li>
            <li>Open <a href="move-plans.php">Saved Plans</a> and review the saved plan.</li>
            <li>Validate the plan from the plan view.</li>
        </ol>
        <p><strong>What a plan is:</strong> a plan is a snapshot of intended moves. It lets you review and validate before any execution records or actual file moves are made.</p>
    </div>

    <div class="card">
        <h2>Dry-run and drive-swapping process</h2>
        <p>DriveMaster is designed for situations where not every drive is connected at the same time. The correct process is to let the dry-run tell you what is needed, connect the required drives, verify, then continue.</p>
        <ol>
            <li>From the saved plan view, queue a dry-run.</li>
            <li>Open the dry-run report and check which source and target drives are required.</li>
            <li>Connect the first required source/target drive pair or group that you can physically attach.</li>
            <li>Use <a href="drive-verify.php">Verify Drive</a> to confirm DriveMaster sees the expected drive and expected files.</li>
            <li>If the dry-run reports missing files or mismatched paths, decide whether to stop and rescan/fix, or continue knowing only safe available work can be done.</li>
            <li>When the current connected-drive batch is complete, verify the drive changes before disconnecting it.</li>
            <li>Swap to the next required drive, verify again, and continue the job.</li>
        </ol>
        <p><strong>Do not skip verification:</strong> removable drives can mount differently, contain stale data, or be the wrong physical disk. Verification protects against moving the wrong data or updating the database incorrectly.</p>
    </div>

    <div class="card">
        <h2>Executing moves</h2>
        <ol>
            <li>Keep actual moves disabled while testing. Use <a href="move-execution-settings.php">Execution Settings</a> to confirm the current safety mode.</li>
            <li>Prepare an execution run only after plan validation and dry-run review.</li>
            <li>Run simulation first. Simulation proves the execution path without moving files.</li>
            <li>When ready, enable actual moves and use the required confirmation phrase.</li>
            <li>Execute only the available safe work for the drives currently connected.</li>
            <li>Review <a href="operation-log.php">Operation Log</a> and <a href="move-executions.php">Execution Runs</a> after each run.</li>
            <li>Use <a href="drive-verify.php">Verify Drive</a> before disconnecting or replacing any drive.</li>
        </ol>
        <p><strong>Database updates:</strong> every successful move/undo should update the database so DriveMaster's inventory matches the filesystem. If something is changed outside DriveMaster, rescan the affected drive.</p>
    </div>

    <div class="card">
        <h2>Undo process</h2>
        <p>Undo works from logged operation runs. It should not be treated as a blind reverse copy; it must be dry-run and verified first.</p>
        <ol>
            <li>Open <a href="operation-log.php">Operation Log</a> or <a href="undo.php">Undo</a>.</li>
            <li>Select the execution run you want to undo.</li>
            <li>Run an undo dry-run first.</li>
            <li>Connect the required drives listed by the undo dry-run.</li>
            <li>Use <a href="drive-verify.php">Verify Drive</a> to confirm the files required for undo are present.</li>
            <li>Review the undo report. If differences are reported, decide whether to cancel or continue with only the safe available work.</li>
            <li>Execute the undo only after review.</li>
            <li>After undo finishes, verify the changed drives and review the operation log.</li>
        </ol>
        <p><strong>Expected behaviour:</strong> if files are missing or paths differ, undo should report what cannot be done. If you continue despite differences, DriveMaster should do only the work that can be safely verified.</p>
    </div>

    <div class="card">
        <h2>Safe operating checklist</h2>
        <ul>
            <li>Do not move data from a stale scan. Rescan drives that changed outside DriveMaster.</li>
            <li>Do not enable actual moves until dry-run and simulation have been tested on a small disposable sample.</li>
            <li>Do not ignore conflicts unless you understand why the item matches multiple categories.</li>
            <li>Do not disconnect a drive until the current run is complete and the drive has been verified.</li>
            <li>Do not use broad rules without previewing their matches.</li>
            <li>Keep ignored-folder rules up to date before planning large moves.</li>
            <li>Use the logs and execution records as the source of truth for undo.</li>
        </ul>
    </div>

    <div class="card">
        <h2>Main routes</h2>
        <div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Path</th><th>Purpose</th><th>Use when</th></tr></thead><tbody>
            <tr><td><a href="index.php">index.php</a></td><td>Dashboard/home.</td><td>Starting point and high-level overview.</td></tr>
            <tr><td><a href="scan.php">scan.php</a></td><td>Queue drive scans.</td><td>You need to scan or rescan a drive.</td></tr>
            <tr><td><a href="jobs.php">jobs.php</a></td><td>Background job list.</td><td>Checking scans, dry-runs, or queued work.</td></tr>
            <tr><td><a href="drives.php">drives.php</a></td><td>Drive registration/status.</td><td>Managing known drives and refresh status.</td></tr>
            <tr><td><a href="files.php">files.php</a></td><td>Inventory browser and search.</td><td>Browsing scanned folders/files visually.</td></tr>
            <tr><td><a href="ignored-folders.php">ignored-folders.php</a></td><td>Ignored folder names/rules.</td><td>Excluding noise folders such as metadata/cache folders.</td></tr>
            <tr><td><a href="errors.php">errors.php</a></td><td>Error list.</td><td>Reviewing scan/app errors.</td></tr>
            <tr><td><a href="health.php">health.php</a></td><td>Health/status checks.</td><td>Checking app/database state.</td></tr>
            <tr><td><a href="help.php">help.php</a></td><td>This help page.</td><td>Finding the correct process/page.</td></tr>
        </tbody></table></div>
    </div>

    <div class="card">
        <h2>Category routes</h2>
        <div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Path</th><th>Purpose</th><th>Use when</th></tr></thead><tbody>
            <tr><td><a href="category-status.php">category-status.php</a></td><td>Category status browser.</td><td>Working through one drive visually, assigning uncategorised folders/files.</td></tr>
            <tr><td><a href="category-status-summary.php">category-status-summary.php</a></td><td>Summary of categorisation progress.</td><td>Checking how much category work remains.</td></tr>
            <tr><td><a href="categories.php">categories.php</a></td><td>Create/manage categories, colours, and rules.</td><td>Defining category logic.</td></tr>
            <tr><td><a href="category-targets.php">category-targets.php</a></td><td>Map categories to target drives.</td><td>Required before move planning.</td></tr>
            <tr><td><a href="category-preview.php">category-preview.php</a></td><td>Preview category matching.</td><td>Checking manual/rule results before planning.</td></tr>
            <tr><td><a href="category-coverage.php">category-coverage.php</a></td><td>Coverage report.</td><td>Reviewing categorisation coverage; use filters where possible.</td></tr>
            <tr><td><a href="category-conflicts.php">category-conflicts.php</a></td><td>Category conflict review.</td><td>Finding items with multiple category matches.</td></tr>
            <tr><td><a href="category-drive-usage.php">category-drive-usage.php</a></td><td>Drive usage by category/target.</td><td>Checking where category data currently lives.</td></tr>
            <tr><td>category-status-data.php</td><td>AJAX endpoint for Category Status table.</td><td>Internal route.</td></tr>
            <tr><td>category-status-tree-children.php</td><td>AJAX endpoint for lazy tree children.</td><td>Internal route.</td></tr>
            <tr><td>category-status-tree-branch.php</td><td>AJAX endpoint for tree branch sync.</td><td>Internal route.</td></tr>
        </tbody></table></div>
    </div>

    <div class="card">
        <h2>Planning, execution, and undo routes</h2>
        <div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Path</th><th>Purpose</th><th>Use when</th></tr></thead><tbody>
            <tr><td><a href="planning-dashboard.php">planning-dashboard.php</a></td><td>Planning/execution status dashboard.</td><td>Checking overall planning state.</td></tr>
            <tr><td><a href="planner-readiness.php">planner-readiness.php</a></td><td>Readiness checks.</td><td>Before planning moves.</td></tr>
            <tr><td><a href="move-planner.php">move-planner.php</a></td><td>Legacy/planning interface.</td><td>Review only if still needed.</td></tr>
            <tr><td><a href="plan-builder.php">plan-builder.php</a></td><td>Build saved move-plan snapshots in batches.</td><td>Primary page for creating plans.</td></tr>
            <tr><td><a href="move-candidates.php">move-candidates.php</a></td><td>Safe candidate list.</td><td>Verifying what can move before snapshotting.</td></tr>
            <tr><td><a href="move-plans.php">move-plans.php</a></td><td>Saved move plans.</td><td>Open, continue, validate, dry-run, or prepare plans.</td></tr>
            <tr><td>move-plan-view.php?id=...</td><td>Single saved plan view.</td><td>Validate plan, queue dry-run, prepare execution.</td></tr>
            <tr><td>move-plan-dry-runs.php?id=...</td><td>Dry-run reports for a plan.</td><td>Review dry-run issues before execution prep.</td></tr>
            <tr><td>move-plan-queue.php</td><td>Queues dry-run jobs.</td><td>Internal POST action from plan view.</td></tr>
            <tr><td><a href="move-executions.php">move-executions.php</a></td><td>Execution run list.</td><td>Review prepared/simulated/complete execution runs.</td></tr>
            <tr><td>move-execution-view.php?id=...</td><td>Single execution run view.</td><td>Final safety check, simulate, or actual move.</td></tr>
            <tr><td>move-execution-prepare.php</td><td>Creates/reuses execution records.</td><td>Internal POST action from plan view.</td></tr>
            <tr><td>move-execution-queue.php</td><td>Runs simulation or guarded actual execution.</td><td>Internal POST action from execution view.</td></tr>
            <tr><td><a href="operation-log.php">operation-log.php</a></td><td>Operation/audit log.</td><td>Reviewing logged moves and undo source runs.</td></tr>
            <tr><td><a href="undo.php">undo.php</a></td><td>Undo workflow.</td><td>Dry-run and execute reverse operations from logged runs.</td></tr>
            <tr><td><a href="drive-verify.php">drive-verify.php</a></td><td>Drive verification.</td><td>Before/after execution and before swapping drives.</td></tr>
            <tr><td><a href="move-execution-settings.php">move-execution-settings.php</a></td><td>Execution safety settings.</td><td>Enable/disable actual move mode and confirmation requirements.</td></tr>
            <tr><td><a href="planning-maintenance.php">planning-maintenance.php</a></td><td>Planning database cleanup/index tools.</td><td>Admin maintenance only.</td></tr>
        </tbody></table></div>
    </div>

    <div class="card">
        <h2>User/account routes</h2>
        <div class="table-scroll"><table class="auto-table compact-table"><thead><tr><th>Path</th><th>Purpose</th><th>Notes</th></tr></thead><tbody>
            <tr><td><a href="users.php">users.php</a></td><td>User management.</td><td>Admins only once users exist. Default admin is protected.</td></tr>
            <tr><td><a href="user-edit.php">user-edit.php</a></td><td>Edit user.</td><td>Update username, display name, or access value.</td></tr>
            <tr><td><a href="login.php">login.php</a></td><td>Sign in.</td><td>Shown under Users menu when logged out.</td></tr>
            <tr><td><a href="logout.php">logout.php</a></td><td>Sign out.</td><td>Shown under Users menu when logged in.</td></tr>
            <tr><td><a href="setup-admin.php">setup-admin.php</a></td><td>Legacy setup placeholder.</td><td>Likely redundant now that users.php handles first account creation.</td></tr>
        </tbody></table></div>
    </div>

    <div class="card danger-zone">
        <h2>Pages that may be consolidated later</h2>
        <p class="muted">Do not delete these until the current process has been tested. These are candidates for consolidation, not confirmed safe deletions.</p>
        <ul>
            <li><strong>setup-admin.php</strong> — likely redundant because <a href="users.php">users.php</a> can create the first account and user management now lives there.</li>
            <li><strong>move-planner.php</strong> — may be partly superseded by <a href="plan-builder.php">plan-builder.php</a> and <a href="move-candidates.php">move-candidates.php</a>. Keep until you confirm no unique features remain.</li>
            <li><strong>category-coverage.php</strong> — keep only if the optimised/current version remains useful.</li>
            <li><strong>category-status-summary.php</strong> — useful but potentially slower on large datasets. Keep as a reporting page if it remains useful.</li>
            <li><strong>planning-maintenance.php</strong> — admin/maintenance only; keep hidden or admin-only, but do not delete yet.</li>
        </ul>
    </div>
</div>
<?php render_footer(); ?>
