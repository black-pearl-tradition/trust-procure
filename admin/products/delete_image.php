<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/products/index.php');
}
verify_csrf('/admin/products/index.php');

$id = (int) ($_POST['id'] ?? 0);
$productId = (int) ($_POST['product_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM product_images WHERE id = ?');
$stmt->execute([$id]);
$image = $stmt->fetch();

if ($image) {
    $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$id]);
    $path = __DIR__ . '/../../uploads/products/' . $image['image'];
    if (is_file($path)) {
        @unlink($path);
    }
    log_activity($pdo, $_SESSION['user_id'], 'product_image_deleted', "Removed gallery image #$id from product #$productId");
    set_flash('success', 'Gallery image removed.');
} else {
    set_flash('error', 'Image not found.');
}

redirect('/admin/products/edit.php?id=' . $productId);
