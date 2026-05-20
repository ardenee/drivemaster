<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

$error = null;
$categories = [];
$drives = [];
$summary = [];
$runPreview = isset($_GET['preview']);
$categoryId = max(0, (int)($_GET['category_id'] ?? 0));
$driveId = max(0, (int)($_GET['drive_id'] ?? 0));

try {
    $categories = db()->query('SELECT id, name, color_hex FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
    $drives = db()->query('SELECT id, friendly_name, free_bytes, total_bytes FROM drives WHERE is_active = 1 ORDER BY friendly_name')->fetchAll();

    if ($runPreview && $categoryId > 0) {
        $params = [$categoryId];
        $targetSql = '';
        if ($driveId > 0) {
            $targetSql = ' AND f.drive_id <> ?';
            $params[] = $driveId;
        }
        $sql = "SELECT COUNT(DISTINCT f.id) AS file_count, COALESCE(SUM(f.size_bytes), 0) AS total_size FROM category_manual_assignments cma JOIN file_entries f ON f.id = cma.file_entry_id AND f.is_missing = 0 AND f.entry_type = 'file' WHERE cma.assignment_type = 'file' AND cma.category_id = ? {$targetSql}";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $summary = $stmt->fetch() ?: ['file_count' => 0, 'total_size' => 0];
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

render_header('Move Planner');
?>
<?php if ($error): ?><div class="card"><strong>Error:</strong> <?= e($error) ?></div><?php endif; ?>
<div class="card">
    <h2>Read-only move planner</h2>
    <p class="muted">Fast preview shell. It avoids whole-inventory planning scans on page load. Use Safe Move Candidates for exact drill-down lists.</p>
    <form method="get">
        <input type="hidden" name="preview" value="1">
        <div class="form-grid">
            <label>Category
                <select name="category_id" required>
                    <option value="0">Select one category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string)$category['id']) ?>" <?= $categoryId === (int)$category['id'] ? 'selected' : '' ?>><?= e((string)$category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Target drive
                <select name="drive_id">
                    <option value="0">No target filter</option>
                    <?php foreach ($drives as $drive): ?>
                        <option value="<?= e((string)$drive['id']) ?>" <?= $driveId === (int)$drive['id'] ? 'selected' : '' ?>><?= e($drive['friendly_name'] . ' - free ' . format_bytes((int)$drive['free_bytes'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="button-row"><button type="submit">Preview direct manual files</button> <a class="button" href="move-candidates.php<?= $categoryId > 0 ? '?category_id=' . e((string)$categoryId) . ($driveId > 0 ? '&target_drive_id=' . e((string)$driveId) : '') : '' ?>">Safe Move Candidates</a> <a class="button" href="planner-readiness.php">Readiness</a> <a class="button" href="move-plans.php">Saved Plans</a></div>
    </form>
</div>

<?php if ($runPreview): ?>
<div class="card">
    <h2>Fast preview</h2>
    <?php if ($categoryId <= 0): ?>
        <p>Select a category to preview.</p>
    <?php else: ?>
        <div class="status-bar">Direct manual files considered: <?= e(number_format((int)($summary['file_count'] ?? 0))) ?> | Estimated file size: <?= e(format_bytes((int)($summary['total_size'] ?? 0))) ?></div>
        <p class="muted">This fast preview only counts directly assigned files. Folder assignments, rule matches, conflicts, target-drive exclusions, and exact safety checks are handled by Safe Move Candidates.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>Planner rebuild status</h2>
    <p class="muted">The previous planner did heavy all-category/all-drive inventory expansion on load, which can 504 on large databases. This page is now safe to load; exact saved plan generation should be rebuilt next using paged candidate snapshots rather than one large query.</p>
</div>
<?php render_footer(); ?>
