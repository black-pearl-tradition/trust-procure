<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/products.php');
}
verify_csrf('/products.php');

$productId = (int) ($_POST['product_id'] ?? 0);
$quantity = max(1, (int) ($_POST['quantity'] ?? 1));
$stmt = $pdo->prepare("SELECT id FROM products WHERE id = ? AND status = 'active' AND (stock_status = 'made_to_order' OR (stock_status <> 'out_of_stock' AND stock_quantity > reserved_quantity))");
$stmt->execute([$productId]);
if (!$stmt->fetch()) {
    set_flash('error', 'That product is not available.');
    redirect('/products.php');
}

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
$_SESSION['cart'][$productId] = min(99, (int) ($_SESSION['cart'][$productId] ?? 0) + $quantity);
set_flash('success', 'Product added to your request cart.');
redirect('/user/cart/index.php');
