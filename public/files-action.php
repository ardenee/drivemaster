<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DriveMaster\Repository\DriveRepository;
use DriveMaster\Repository\JobRepository;
use DriveMaster\Support\IgnoredFolderSql;

function fa_redirect(string $url): never
{
    $url = trim($url);
    if ($url === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) || str_starts_with($url, '//')) {
        $url = 'files.php';
    }
    header('Location: ' . $url);
    exit;
}

function fa_selected_ids(): array
{
    $raw = trim((string)($_POST['selected_ids'] ?? ''));
    if ($raw === '') {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn(int $id): bool => $id > 0)));
}

function fa_assert_not_ignored(array $selectedIds): void
{
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds), static fn(int $id): bool => $id > 0)));
    if (!$selectedIds) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $visibleSql = IgnoredFolderSql::excludeCondition('f');
    $stmt = db()->prepare("SELECT COUNT(*) FROM file_entries f WHERE f.id IN ({$placeholders}) AND {$visibleSql}");
    $stmt->execute($selectedIds);

    if ((int)$stmt->fetchColumn() !== count($selectedIds)) {
        throw new RuntimeException('Selected item is inside an ignored folder and cannot be searched, categorised, moved, renamed, or deleted from this workflow.');
    }
}

function fa_load_entries(array $selectedIds): array
{
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds), static fn(int $id): bool => $id > 0)));
    if (!$selectedIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $visibleSql = IgnoredFolderSql::excludeCondition('f');
    $stmt = db()->prepare("SELECT f.*, d.friendly_name, d.mount_path FROM file_entries f JOIN drives d ON d.id = f.drive_id WHERE f.id IN ({$placeholders}) AND f.is_missing = 0 AND {$visibleSql} ORDER BY LENGTH(f.relative_path) DESC, f.relative_path DESC");
    $stmt->execute($selectedIds);
    $entries = $stmt->fetchAll();
    if (count($entries) !== count($selectedIds)) {
        throw new RuntimeException('One or more selected items were not found, are missing, or are ignored. Refresh the page and try again.');
    }
    return $entries;
}

function fa_join_path(string $base, string $relative): string
{
    $base = rtrim($base, "/\\");
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    return $relative === '' ? $base : $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
}

function fa_validate_mount(array $entry): string
{
    $mount = rtrim((string)($entry['mount_path'] ?? ''), "/\\");
    if ($mount === '' || !is_dir($mount)) {
        throw new RuntimeException('Connect/verify drive "' . (string)$entry['friendly_name'] . '" first. Mount path is not available: ' . (string)($entry['mount_path'] ?? ''));
    }
    return $mount;
}

function fa_entry_disk_path(array $entry): string
{
    return fa_join_path(fa_validate_mount($entry), (string)$entry['relative_path']);
}

function fa_assert_disk_entry_exists(array $entry, string $path): void
{
    if ((string)$entry['entry_type'] === 'folder') {
        if (!is_dir($path)) {
            throw new RuntimeException('Connect/verify drive "' . (string)$entry['friendly_name'] . '" first. Folder was not found on disk: ' . $path);
        }
        return;
    }
    if (!is_file($path)) {
        throw new RuntimeException('Connect/verify drive "' . (string)$entry['friendly_name'] . '" first. File was not found on disk: ' . $path);
    }
}

function fa_safe_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('New name is required.');
    }
    if ($name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\')) {
        throw new RuntimeException('New name must be a single file/folder name, not a path.');
    }
    return $name;
}

function fa_parent_path(string $relativePath): string
{
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || !str_contains($relativePath, '/')) {
        return '';
    }
    return substr($relativePath, 0, strrpos($relativePath, '/')) ?: '';
}

function fa_extension_for_name(string $name, string $entryType): ?string
{
    if ($entryType === 'folder') {
        return null;
    }
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    return $ext !== '' ? strtolower($ext) : null;
}

function fa_delete_tree(string $path): void
{
    if (is_file($path) || is_link($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Could not delete file: ' . $path);
        }
        return;
    }
    if (!is_dir($path)) {
        throw new RuntimeException('Path is not available for delete: ' . $path);
    }
    $items = scandir($path);
    if ($items === false) {
        throw new RuntimeException('Could not read folder for delete: ' . $path);
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        fa_delete_tree($path . DIRECTORY_SEPARATOR . $item);
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Could not delete folder: ' . $path);
    }
}

function fa_mark_deleted(array $entry): void
{
    if ((string)$entry['entry_type'] === 'folder') {
        $stmt = db()->prepare("UPDATE file_entries SET is_missing = 1, last_seen_at = NOW() WHERE drive_id = ? AND (id = ? OR relative_path LIKE CONCAT(?, '/%'))");
        $stmt->execute([(int)$entry['drive_id'], (int)$entry['id'], (string)$entry['relative_path']]);
        return;
    }
    $stmt = db()->prepare('UPDATE file_entries SET is_missing = 1, last_seen_at = NOW() WHERE id = ?');
    $stmt->execute([(int)$entry['id']]);
}

function fa_delete_entries(array $selectedIds): void
{
    $entries = fa_load_entries($selectedIds);
    $paths = [];
    foreach ($entries as $entry) {
        $path = fa_entry_disk_path($entry);
        fa_assert_disk_entry_exists($entry, $path);
        $paths[(int)$entry['id']] = $path;
    }

    foreach ($entries as $entry) {
        $path = $paths[(int)$entry['id']];
        if (!file_exists($path)) {
            fa_mark_deleted($entry);
            continue;
        }
        fa_delete_tree($path);
        fa_mark_deleted($entry);
    }
}

function fa_rename_entry(array $selectedIds): void
{
    if (count($selectedIds) !== 1) {
        throw new RuntimeException('Rename supports one selected item at a time.');
    }
    $entries = fa_load_entries($selectedIds);
    $entry = $entries[0];
    $newName = fa_safe_name((string)($_POST['new_name'] ?? ''));
    $oldName = (string)$entry['name'];
    if ($newName === $oldName) {
        return;
    }

    $mount = fa_validate_mount($entry);
    $oldRelative = trim((string)$entry['relative_path'], '/');
    $parentPath = fa_parent_path($oldRelative);
    $newRelative = $parentPath === '' ? $newName : $parentPath . '/' . $newName;
    $oldDiskPath = fa_join_path($mount, $oldRelative);
    $newDiskPath = fa_join_path($mount, $newRelative);

    fa_assert_disk_entry_exists($entry, $oldDiskPath);
    if (file_exists($newDiskPath)) {
        throw new RuntimeException('Cannot rename because the target already exists on disk: ' . $newDiskPath);
    }
    if (!rename($oldDiskPath, $newDiskPath)) {
        throw new RuntimeException('Filesystem rename failed. Check permissions and verify the drive is writable: ' . $oldDiskPath);
    }

    db()->beginTransaction();
    try {
        $stmt = db()->prepare('UPDATE file_entries SET parent_path = ?, relative_path = ?, full_path = ?, name = ?, extension = ?, last_seen_at = NOW() WHERE id = ?');
        $stmt->execute([$parentPath, $newRelative, $newDiskPath, $newName, fa_extension_for_name($newName, (string)$entry['entry_type']), (int)$entry['id']]);

        if ((string)$entry['entry_type'] === 'folder') {
            $oldPrefix = $oldRelative . '/';
            $newPrefix = $newRelative . '/';
            $desc = db()->prepare('SELECT id, relative_path FROM file_entries WHERE drive_id = ? AND relative_path LIKE ?');
            $desc->execute([(int)$entry['drive_id'], $oldPrefix . '%']);
            $update = db()->prepare('UPDATE file_entries SET parent_path = ?, relative_path = ?, full_path = ?, last_seen_at = NOW() WHERE id = ?');
            foreach ($desc->fetchAll() as $row) {
                $newDescRelative = $newPrefix . substr((string)$row['relative_path'], strlen($oldPrefix));
                $update->execute([fa_parent_path($newDescRelative), $newDescRelative, fa_join_path($mount, $newDescRelative), (int)$row['id']]);
            }

            $assignments = db()->prepare('SELECT id, path_prefix FROM category_manual_assignments WHERE drive_id = ? AND path_prefix IS NOT NULL AND (path_prefix = ? OR path_prefix LIKE ?)');
            $assignments->execute([(int)$entry['drive_id'], $oldRelative, $oldPrefix . '%']);
            $updateAssignment = db()->prepare('UPDATE category_manual_assignments SET path_prefix = ? WHERE id = ?');
            foreach ($assignments->fetchAll() as $assignment) {
                $pathPrefix = (string)$assignment['path_prefix'];
                $newPrefixValue = $pathPrefix === $oldRelative ? $newRelative : $newPrefix . substr($pathPrefix, strlen($oldPrefix));
                $updateAssignment->execute([$newPrefixValue, (int)$assignment['id']]);
            }
        }

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        if (file_exists($newDiskPath) && !file_exists($oldDiskPath)) {
            @rename($newDiskPath, $oldDiskPath);
        }
        throw $e;
    }
}

function fa_add_or_remove_category(string $action, array $selectedIds): void
{
    $categoryId = (int)($_POST['context_category_id'] ?? 0);
    if ($categoryId <= 0) {
        throw new RuntimeException('No category was selected.');
    }

    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $visibleSql = IgnoredFolderSql::excludeCondition('f');
    $stmt = db()->prepare("SELECT f.id, f.drive_id, f.relative_path, f.entry_type FROM file_entries f WHERE f.id IN ({$placeholders}) AND {$visibleSql}");
    $stmt->execute($selectedIds);

    foreach ($stmt->fetchAll() as $entry) {
        if ($action === 'add_category') {
            if ($entry['entry_type'] === 'folder') {
                $insert = db()->prepare("INSERT INTO category_manual_assignments (category_id, drive_id, path_prefix, assignment_type) SELECT ?, ?, ?, 'folder' WHERE NOT EXISTS (SELECT 1 FROM category_manual_assignments WHERE category_id = ? AND drive_id = ? AND path_prefix = ? AND assignment_type = 'folder')");
                $insert->execute([$categoryId, $entry['drive_id'], $entry['relative_path'], $categoryId, $entry['drive_id'], $entry['relative_path']]);
            } else {
                $insert = db()->prepare("INSERT INTO category_manual_assignments (category_id, file_entry_id, assignment_type) SELECT ?, ?, 'file' WHERE NOT EXISTS (SELECT 1 FROM category_manual_assignments WHERE category_id = ? AND file_entry_id = ? AND assignment_type = 'file')");
                $insert->execute([$categoryId, $entry['id'], $categoryId, $entry['id']]);
            }
        } else {
            if ($entry['entry_type'] === 'folder') {
                $delete = db()->prepare("DELETE FROM category_manual_assignments WHERE category_id = ? AND drive_id = ? AND path_prefix = ? AND assignment_type = 'folder'");
                $delete->execute([$categoryId, $entry['drive_id'], $entry['relative_path']]);
            } else {
                $delete = db()->prepare("DELETE FROM category_manual_assignments WHERE category_id = ? AND file_entry_id = ? AND assignment_type = 'file'");
                $delete->execute([$categoryId, $entry['id']]);
            }
        }
    }
}

function fa_queue_file_job(string $action, array $selectedIds): void
{
    $payload = ['file_entry_ids' => $selectedIds];

    if ($action === 'queue_move') {
        $payload['destination_drive_id'] = (int)($_POST['destination_drive_id'] ?? 0);
    }

    (new JobRepository(db()))->create(match ($action) {
        'queue_move' => 'file_move',
        default => throw new RuntimeException('Unknown file job action.'),
    }, $payload);
}

$returnUrl = (string)($_POST['return_url'] ?? 'files.php');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fa_redirect('files.php');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'remove_drive') {
        $driveId = (int)($_POST['drive_id'] ?? 0);
        if ($driveId <= 0) {
            throw new RuntimeException('No drive was selected.');
        }
        (new DriveRepository(db()))->delete($driveId);
        fa_redirect('files.php');
    }

    $selectedIds = fa_selected_ids();
    if (!$selectedIds) {
        throw new RuntimeException('No file/folder selection was supplied.');
    }

    fa_assert_not_ignored($selectedIds);

    if ($action === 'add_category' || $action === 'remove_category') {
        fa_add_or_remove_category($action, $selectedIds);
        fa_redirect($returnUrl);
    }

    if ($action === 'rename' || $action === 'queue_rename') {
        fa_rename_entry($selectedIds);
        fa_redirect($returnUrl);
    }

    if ($action === 'delete' || $action === 'queue_delete') {
        fa_delete_entries($selectedIds);
        fa_redirect($returnUrl);
    }

    if ($action === 'move' || $action === 'queue_move') {
        fa_queue_file_job('queue_move', $selectedIds);
        fa_redirect($returnUrl);
    }

    throw new RuntimeException('Unknown file action.');
} catch (Throwable $e) {
    $separator = str_contains($returnUrl, '?') ? '&' : '?';
    fa_redirect($returnUrl . $separator . 'error=' . rawurlencode($e->getMessage()));
}
