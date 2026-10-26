<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/user/cart/index.php');
}
verify_csrf('/user/cart/index.php');
$productId = (int) ($_POST['product_id'] ?? 0);
unset($_SESSION['cart'][$productId]);
set_flash('success', 'Product removed from your request cart.');
redirect('/user/cart/index.php');
