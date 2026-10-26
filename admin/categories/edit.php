<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
$stmt->execute([$id]);
$category = $stmt->fetch();

if (!$category) {
    set_flash('error', 'Category not found.');
    redirect('/admin/categories/index.php');
}

$pageTitle = 'Edit Category';
$errors = [];
$name = $category['name'];
$description = $category['description'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/admin/categories/index.php');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        $errors[] = 'Please enter a category name.';
    }

    if (!$errors) {
        $slug = slugify($name);
        $stmt = $pdo->prepare('UPDATE categories SET name = ?, slug = ?, description = ? WHERE id = ?');
        $stmt->execute([$name, $slug, $description, $id]);
        log_activity($pdo, $_SESSION['user_id'], 'category_updated', "Updated category #$id");
        set_flash('success', 'Category updated.');
        redirect('/admin/categories/index.php');
    }
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head"><h2>Edit category</h2></div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <form method="post" style="max-width: 480px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $category['id'] ?>">
            <div class="field">
                <label for="name">Category name</label>
                <input type="text" id="name" name="name" value="<?= e($name) ?>" required autofocus>
            </div>
            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description"><?= e($description) ?></textarea>
            </div>
            <div class="table-actions">
                <button type="submit" class="btn btn-accent">Save changes</button>
                <a href="<?= url('/admin/categories/index.php') ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
