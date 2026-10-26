<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/categories/index.php');
}
verify_csrf('/admin/categories/index.php');

$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT name FROM categories WHERE id = ?');
$stmt->execute([$id]);
$category = $stmt->fetch();

if ($category) {
    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
    log_activity($pdo, $_SESSION['user_id'], 'category_deleted', "Deleted category: {$category['name']}");
    set_flash('success', 'Category deleted.');
} else {
    set_flash('error', 'Category not found.');
}

redirect('/admin/categories/index.php');
