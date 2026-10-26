<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/products/index.php');
}
verify_csrf('/admin/products/index.php');

$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT name, image FROM products WHERE id = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();

if ($product) {
    $blockReason = product_delete_block_reason($pdo, $id);
    if ($blockReason !== null) {
        set_flash('error', $blockReason);
        redirect('/admin/products/index.php');
    }

    $galleryStmt = $pdo->prepare('SELECT image FROM product_images WHERE product_id = ?');
    $galleryStmt->execute([$id]);
    $galleryFiles = $galleryStmt->fetchAll(PDO::FETCH_COLUMN);

    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);

    if ($product['image']) {
        $path = __DIR__ . '/../../uploads/products/' . $product['image'];
        if (is_file($path)) {
            @unlink($path);
        }
    }
    foreach ($galleryFiles as $filename) {
        $path = __DIR__ . '/../../uploads/products/' . $filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    log_activity($pdo, $_SESSION['user_id'], 'product_deleted', "Deleted product: {$product['name']}");
    set_flash('success', 'Product deleted.');
} else {
    set_flash('error', 'Product not found.');
}

redirect('/admin/products/index.php');
