<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Categories';

$categories = $pdo->query(
    "SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
     FROM categories c ORDER BY c.name"
)->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>All categories</h2>
        <a href="<?= url('/admin/categories/add.php') ?>" class="btn btn-accent btn-sm">Add category</a>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr><th>Name</th><th>Description</th><th>Products</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $c): ?>
                <tr>
                    <td><?= e($c['name']) ?></td>
                    <td class="muted"><?= e($c['description'] ?? '—') ?></td>
                    <td><?= (int) $c['product_count'] ?></td>
                    <td>
                        <div class="table-actions">
                            <a href="<?= url('/admin/categories/edit.php?id=' . $c['id']) ?>" class="btn btn-outline btn-sm">Edit</a>
                            <form method="post" action="<?= url('/admin/categories/delete.php') ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this category? Products in it will become uncategorised.">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$categories): ?>
                    <tr><td colspan="4" class="empty-state">No categories yet. Add your first category to start organising products.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
