<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/_layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($id > 0) {
            $stmt = db()->prepare('UPDATE categories SET name = ?, description = ?, is_active = ? WHERE id = ?');
            $stmt->execute([$name, $description ?: null, $isActive, $id]);
        } else {
            $stmt = db()->prepare('INSERT INTO categories (name, description, is_active) VALUES (?, ?, ?)');
            $stmt->execute([$name, $description ?: null, $isActive]);
        }

        header('Location: categories.php');
        exit;
    }

    if ($action === 'delete') {
        $stmt = db()->prepare('DELETE FROM categories WHERE id = ?');
        $stmt->execute([(int)$_POST['id']]);
        header('Location: categories.php');
        exit;
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

$categories = db()->query('SELECT * FROM categories ORDER BY name')->fetchAll();

render_header('Categories');
?>
<div class="card">
    <h2><?= $edit ? 'Edit category' : 'Add category' ?></h2>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= e((string)($edit['id'] ?? 0)) ?>">
        <div class="form-grid">
            <label>Name <input type="text" name="name" required value="<?= e($edit['name'] ?? '') ?>"></label>
            <label>Active <input type="checkbox" name="is_active" value="1" <?= !isset($edit) || (int)($edit['is_active'] ?? 1) === 1 ? 'checked' : '' ?>></label>
            <label class="wide">Description <textarea name="description" rows="3"><?= e($edit['description'] ?? '') ?></textarea></label>
        </div>
        <p><button type="submit">Save category</button></p>
    </form>
</div>

<div class="card">
    <h2>Categories</h2>
    <table>
        <thead><tr><th>Name</th><th>Description</th><th>Active</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($categories as $category): ?>
            <tr>
                <td><?= e($category['name']) ?></td>
                <td><?= e($category['description']) ?></td>
                <td><?= (int)$category['is_active'] === 1 ? 'Yes' : 'No' ?></td>
                <td class="actions">
                    <a class="button" href="categories.php?edit=<?= e((string)$category['id']) ?>">Edit</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this category?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= e((string)$category['id']) ?>">
                        <button class="danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php render_footer(); ?>
