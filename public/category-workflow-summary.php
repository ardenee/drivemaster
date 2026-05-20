<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

use DriveMaster\Repository\CategoryWorkflowRepository;

$error = null;
$rows = [];

try {
    $rows = (new CategoryWorkflowRepository(db()))->driveCoverageSummary();
} catch (Throwable $e) {
    $error = 'Could not load workflow summary: ' . $e->getMessage();
}

render_header('Category Workflow Summary');
?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>

<div class="card">
    <h2>Manual Assignment Coverage by Drive</h2>
    <p class="muted">This summary counts visible inventory items and shows how much manual category work remains per drive. It checks manual assignments only, not category rules.</p>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Drive</th>
                <th>Total visible</th>
                <th>Total files</th>
                <th>Total folders</th>
                <th>Assigned files</th>
                <th>Assigned folders</th>
                <th>Unassigned files</th>
                <th>Unassigned folders</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $driveId = (int)$row['drive_id']; ?>
                <tr>
                    <td><?= e((string)$row['friendly_name']) ?></td>
                    <td><?= e(number_format((int)$row['total_items'])) ?></td>
                    <td><?= e(number_format((int)$row['total_files'])) ?></td>
                    <td><?= e(number_format((int)$row['total_folders'])) ?></td>
                    <td><?= e(number_format((int)$row['assigned_files'])) ?></td>
                    <td><?= e(number_format((int)$row['assigned_folders'])) ?></td>
                    <td><?= e(number_format((int)$row['unassigned_files'])) ?></td>
                    <td><?= e(number_format((int)$row['unassigned_folders'])) ?></td>
                    <td><a class="button" href="category-workflow.php?drive_id=<?= e((string)$driveId) ?>&path=&page=1#workflow-explorer">Open workflow</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows && !$error): ?><tr><td colspan="9">No drives have been scanned yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php render_footer(); ?>
