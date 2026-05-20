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
    if ($action === 'queue_rename') {
        $payload['new_name'] = trim((string)($_POST['new_name'] ?? ''));
    }

    (new JobRepository(db()))->create(match ($action) {
        'queue_move' => 'file_move',
        'queue_delete' => 'file_delete',
        'queue_rename' => 'file_rename',
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

    if (in_array($action, ['queue_move', 'queue_delete', 'queue_rename'], true)) {
        fa_queue_file_job($action, $selectedIds);
        fa_redirect($returnUrl);
    }

    throw new RuntimeException('Unknown file action.');
} catch (Throwable $e) {
    $separator = str_contains($returnUrl, '?') ? '&' : '?';
    fa_redirect($returnUrl . $separator . 'error=' . rawurlencode($e->getMessage()));
}
