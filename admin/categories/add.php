<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Add Category';
$errors = [];
$name = $description = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/admin/categories/index.php');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        $errors[] = 'Please enter a category name.';
    }

    if (!$errors) {
        $slug = slugify($name);
        $stmt = $pdo->prepare('INSERT INTO categories (name, slug, description) VALUES (?, ?, ?)');
        $stmt->execute([$name, $slug, $description]);
        log_activity($pdo, $_SESSION['user_id'], 'category_created', "Created category: $name");
        set_flash('success', 'Category added.');
        redirect('/admin/categories/index.php');
    }
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head"><h2>Add category</h2></div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <form method="post" style="max-width: 480px;">
            <?= csrf_field() ?>
            <div class="field">
                <label for="name">Category name</label>
                <input type="text" id="name" name="name" value="<?= e($name) ?>" required autofocus>
            </div>
            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description"><?= e($description) ?></textarea>
            </div>
            <div class="table-actions">
                <button type="submit" class="btn btn-accent">Save category</button>
                <a href="<?= url('/admin/categories/index.php') ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
