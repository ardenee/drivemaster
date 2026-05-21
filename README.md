# DriveMaster

DriveMaster is a PHP and MariaDB drive inventory, categorisation, move-planning, execution, logging, and undo application for managing large file collections spread across many hard drives.

The app is intended for situations where not all drives are connected at the same time. It scans each drive into a database, lets you categorise files and folders, maps categories to target drives, builds safe move plans, validates/dry-runs those plans, records execution activity, and provides an undo workflow based on logged operations.

## Core goals

- Keep a database inventory of many drives.
- Work through uncategorised files/folders one drive at a time.
- Assign categories manually or through rules.
- Map categories to target drives.
- Identify safe move candidates.
- Avoid moving conflicted, ignored, missing, or unsafe items.
- Support dry-run and simulation before actual file moves.
- Track execution activity with user IDs and operation logs.
- Support undo from logged move jobs.
- Support a practical workflow where different drives are connected at different times.

## Main features

### Drive management

- Register and manage drives.
- Store friendly drive names.
- Track drive identity information.
- Review drive status and capacity information.
- Verify connected drives before/after move jobs.

### Scanning and inventory

- Recursive file/folder scanning.
- Scan run history through jobs.
- File and folder inventory stored in MariaDB.
- Browser-based file explorer.
- File search by text, drive, type, extension, path, size, modified date, and category.
- Category badges shown in file/folder views.

### Ignored folders

- Manage ignored folder names.
- Exclude ignored folders from normal file/category/planning views where supported.
- Useful for metadata/cache/system folders such as NAS-generated folders.

### Categories

- Create and manage categories.
- Assign colours to categories.
- Manually assign files/folders to categories.
- Folder assignments cover folder contents.
- Create category rules based on extension, folder path, path contains, or name contains.
- Preview category matches before planning moves.

### Category status workflow

- Browse one drive at a time.
- Switch between uncategorised and categorised views.
- Assign categories by right-clicking files/folders.
- Review category progress with status and summary pages.
- Use category colours/badges consistently across inventory views.

### Conflict handling

- Detect items that match more than one category.
- Review active and accepted conflicts.
- Exclude active conflicts from automated move planning.
- Accept known/intentional conflicts when required.

### Target drives

- Map each category to one or more target drives.
- Use category/drive usage reports to see where data currently lives.
- Planner uses category + target-drive mapping to decide whether a file should move.

### Move planning

- Readiness checks before planning.
- Safe candidate list.
- Plan Builder for saved move-plan snapshots.
- Saved plans can be validated, dry-run, and prepared for execution.
- Planning is database-driven and does not move files by itself.

### Dry-run, execution, and drive swapping

- Dry-run checks required files, paths, source drives, and target drives before execution.
- Supports workflows where only some drives are connected at a time.
- Execution records can be prepared before actual moves.
- Simulation mode runs the execution path without moving files.
- Actual moves require explicit enabling and confirmation.
- Drive verification should be run before swapping/disconnecting drives.

### Logging and undo

- Operation log records move/undo activity.
- Execution runs are tracked.
- Undo is based on logged operation runs.
- Undo supports dry-run before execution.
- If files/drives differ, undo should report what cannot be safely reversed and only continue with verified safe work.

### User management

- User accounts with admin/user roles.
- Login/logout flow.
- Admin user-management page.
- Edit user page for username/display-name/password updates.
- Default admin protections.
- Activity tracking uses stable user IDs rather than usernames.

## Recommended workflow

1. Configure ignored folders.
2. Register and scan each drive.
3. Review scanned inventory in the file explorer.
4. Create categories and colours.
5. Assign categories manually from Category Status.
6. Add rules only where they are safe and predictable.
7. Preview category matching.
8. Resolve or accept category conflicts.
9. Assign category target drives.
10. Check planner readiness.
11. Review move candidates.
12. Build a saved move plan.
13. Validate the plan.
14. Run a dry-run.
15. Connect/verify required drives.
16. Prepare execution records.
17. Run simulation.
18. Enable actual moves only when ready.
19. Execute available safe work.
20. Verify changed drives before swapping/disconnecting.
21. Use undo from logged runs if needed.

## Typical drive-swapping process

DriveMaster is designed to support multi-drive workflows where source and target drives may not all be online at once.

1. Build and validate the move plan.
2. Run the dry-run report.
3. Note which source/target drives are required.
4. Connect a required source/target drive pair or group.
5. Verify the connected drives.
6. Execute only the safe available work.
7. Verify changes before disconnecting drives.
8. Swap to the next required drive group.
9. Continue until the execution run is complete.

## Safety model

DriveMaster should not blindly move files. The move process is designed around safety checks:

- Ignored folders are excluded from normal planning logic.
- Missing files are excluded.
- Items with multiple category matches are conflicts.
- Conflicted items are excluded unless accepted where appropriate.
- Categories must have target drives before they can produce move candidates.
- Files already on the correct target drive are not move candidates.
- Dry-run and simulation should happen before actual moves.
- Database inventory should be updated after successful move/undo operations.
- Drives should be verified before and after each execution batch.

## Requirements

- PHP 8.2 or newer
- MariaDB 10.x or newer
- PHP extensions:
  - pdo_mysql
  - json
  - mbstring

## Initial setup

1. Create a MariaDB database.
2. Import `database/schema.sql`.
3. Apply any files in `database/migrations/` that are not already included in the schema.
4. Copy `config/config.example.php` to `config/config.php`.
5. Edit database credentials in `config/config.php`.
6. Point the web server document root at `public/`.
7. Open the app in a browser.
8. Create the first admin user from `users.php` if no users exist yet.

## CLI scanning

Run the scanner with a drive path, friendly name, and optional serial number.

```bash
php bin/scan-drive.php --path=/path/to/drive --name="Drive 01" --serial="SERIAL123"
```

If no serial number is supplied, DriveMaster creates a fallback identity from the scan path and drive size. That is useful for development, but proper hardware serial detection should be used before relying on it for production inventory.

## Main application areas

### General

- `index.php` - dashboard/home
- `drives.php` - drive management
- `scan.php` - queue scans
- `jobs.php` - background job history/status
- `files.php` - file/folder explorer and search
- `ignored-folders.php` - ignored folder rules
- `errors.php` - error list
- `health.php` - health checks
- `help.php` - in-app usage guide

### Categories

- `categories.php` - category/rule management
- `category-status.php` - categorised/uncategorised status browser
- `category-status-summary.php` - category progress summary
- `category-targets.php` - category target drives
- `category-preview.php` - category match preview
- `category-coverage.php` - category coverage report
- `category-conflicts.php` - conflict review
- `category-drive-usage.php` - category usage by drive

### Planning and execution

- `planning-dashboard.php` - planning overview
- `planner-readiness.php` - readiness checks
- `move-candidates.php` - safe move candidates
- `plan-builder.php` - build saved move plans
- `move-plans.php` - saved plans
- `move-executions.php` - execution runs
- `operation-log.php` - operation/audit log
- `undo.php` - undo workflow
- `drive-verify.php` - drive verification
- `move-execution-settings.php` - execution safety settings
- `planning-maintenance.php` - maintenance tools

### Users

- `users.php` - user management
- `user-edit.php` - edit user details/access value
- `login.php` - login
- `logout.php` - logout

## Current structure

- `bin/` - command-line tools
- `config/` - configuration files
- `database/` - schema and migrations
- `public/` - browser UI and web endpoints
- `src/` - PHP application code

## Development notes

This project is actively evolving. Some older pages may overlap with newer workflows while the application is being consolidated. The Help page inside the app should be treated as the current operational guide for normal use.

Before using actual file moves on important data, test the full process on a small disposable folder/drive set:

1. Scan.
2. Categorise.
3. Assign a target.
4. Build a plan.
5. Dry-run.
6. Simulate.
7. Execute.
8. Verify.
9. Undo.
10. Verify again.
