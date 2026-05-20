<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$message = null;
$error = null;
$saved = (int)($_GET['saved'] ?? 0);
if ($saved > 0) {
    $message = 'Plan snapshot saved: ' . $saved;
}

function mp_json_array(?string $json): array
{
    if (!$json) {
        return [];
    }
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (Throwable) {
        return [];
    }
}

function mp_json_count(?string $json): int
{
    return count(mp_json_array($json));
}

function mp_builder_url(array $plan): string
{
    $categories = mp_json_array($plan['selected_category_ids_json'] ?? null);
    $drives = mp_json_array($plan['selected_drive_ids_json'] ?? null);
    $categoryId = (int)($categories[0] ?? 0);
    $driveId = (int)($drives[0] ?? 0);
    return 'plan-builder.php?plan_id=' . (int)$plan['id'] . '&category_id=' . $categoryId . '&target_drive_id=' . $driveId;
}

function mp_json_flags(?string $json): string
{
    if (!$json) {
        return '';
    }
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return '';
    }
    if (!is_array($data)) {
        return '';
    }
    $flags = [];
    foreach (['safe_candidates' => 'Safe candidates', 'paged_builder' => 'Paged builder', 'use_category_targets' => 'Category targets', 'allow_spanning' => 'Spanning', 'include_accepted' => 'Accepted conflicts', 'include_uncategorised' => 'Uncategorised'] as $key => $label) {
        if (!empty($data[$key])) {
            $flags[] = $label;
        }
    }
    return $flags ? implode(', ', $flags) : 'Default options';
}

function mp_json_warning_count(?string $json): int
{
    return count(array_filter(mp_json_array($json)));
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'archive_plan' && $id > 0) {
            $stmt = db()->prepare("UPDATE move_plans SET status = 'archived' WHERE id = ? AND status <> 'archived'");
            $stmt->execute([$id]);
            header('Location: move-plans.php');
            exit;
        }
        if ($action === 'delete_plan' && $id > 0) {
            $stmt = db()->prepare('DELETE FROM move_plans WHERE id = ?');
            $stmt->execute([$id]);
            header('Location: move-plans.php');
            exit;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$plans = db()->query('SELECT * FROM move_plans ORDER BY id DESC LIMIT 100')->fetchAll();

render_header('Saved Move Plans');
?>
<?php if ($message): ?><div class="card"><strong><?= e($message) ?></strong></div><?php endif; ?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>

<div class="card">
    <h2>Saved move plans</h2>
    <p class="muted">Read-only move plan snapshots. Building plans can be resumed from here.</p>
    <div class="button-row"><a class="button" href="move-planner.php">Move Planner</a> <a class="button" href="plan-builder.php">Plan Builder</a></div>
    <div class="table-scroll">
        <table class="auto-table compact-table">
            <thead><tr><th>ID</th><th>Status</th><th>Created</th><th>Categories</th><th>Target drives</th><th>Options</th><th>Candidates</th><th>Total size</th><th>Warnings</th><th>Blockers</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($plans as $plan): ?>
                <tr>
                    <td><a href="move-plan-view.php?id=<?= e((string)$plan['id']) ?>"><?= e((string)$plan['id']) ?></a></td>
                    <td><?= e($plan['status']) ?></td>
                    <td><?= e($plan['created_at']) ?></td>
                    <td><?= e(number_format(mp_json_count($plan['selected_category_ids_json'] ?? null))) ?></td>
                    <td><?= e(number_format(mp_json_count($plan['selected_drive_ids_json'] ?? null))) ?></td>
                    <td><?= e(mp_json_flags($plan['options_json'] ?? null)) ?></td>
                    <td><?= e(number_format((int)$plan['candidate_count'])) ?></td>
                    <td><?= e(format_bytes((int)$plan['total_size_bytes'])) ?></td>
                    <td><?= e(number_format(mp_json_warning_count($plan['warning_summary_json'] ?? null))) ?></td>
                    <td><?= e(number_format(mp_json_warning_count($plan['blocker_summary_json'] ?? null))) ?></td>
                    <td class="actions">
                        <a class="button" href="move-plan-view.php?id=<?= e((string)$plan['id']) ?>">View</a>
                        <?php if ($plan['status'] === 'building'): ?><a class="button" href="<?= e(mp_builder_url($plan)) ?>">Continue</a><?php endif; ?>
                        <?php if ($plan['status'] !== 'archived'): ?>
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="archive_plan"><input type="hidden" name="id" value="<?= e((string)$plan['id']) ?>"><button type="submit">Archive</button></form>
                        <?php endif; ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this saved plan snapshot?');"><input type="hidden" name="action" value="delete_plan"><input type="hidden" name="id" value="<?= e((string)$plan['id']) ?>"><button class="danger" type="submit">Delete</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$plans): ?><tr><td colspan="11">No saved move plan snapshots yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
