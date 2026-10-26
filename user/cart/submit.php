<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/user/cart/index.php');
}
verify_csrf('/user/cart/index.php');
$cart = $_SESSION['cart'] ?? [];
$deliveryAddress = trim($_POST['delivery_address'] ?? '');
$notes = trim($_POST['notes'] ?? '');
if (!$cart) {
    set_flash('error', 'Your request cart is empty.');
    redirect('/products.php');
}
if ($deliveryAddress === '') {
    set_flash('error', 'Please provide a delivery address.');
    redirect('/user/cart/index.php');
}

$ids = array_map('intval', array_keys($cart));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id, name, price, is_high_end, deposit_percentage FROM products WHERE id IN ($placeholders) AND status = 'active' AND (stock_status = 'made_to_order' OR (stock_status <> 'out_of_stock' AND stock_quantity > reserved_quantity))");
$stmt->execute($ids);
$products = $stmt->fetchAll();
if (count($products) !== count($ids)) {
    set_flash('error', 'One or more products in your cart is no longer available.');
    redirect('/user/cart/index.php');
}

$total = 0;
$deposit = 0;
foreach ($products as $product) {
    $quantity = max(1, (int) ($cart[$product['id']] ?? 1));
    $lineTotal = (float) $product['price'] * $quantity;
    $total += $lineTotal;
    $deposit += round($lineTotal * (($product['is_high_end'] ? (int) $product['deposit_percentage'] : 100) / 100));
}
$balance = max($total - $deposit, 0);
$firstProduct = $products[0];

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "INSERT INTO orders (user_id, product_id, quantity, unit_price, total_amount, deposit_amount, balance_amount, status, payment_status, delivery_address, customer_notes)
            VALUES (?, ?, 1, ?, ?, ?, ?, 'request_submitted', 'unpaid', ?, ?)"
    );
    $stmt->execute([$_SESSION['user_id'], $firstProduct['id'], $firstProduct['price'], $total, $deposit, $balance, $deliveryAddress, $notes ?: null]);
    $orderId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_amount, deposit_amount) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($products as $product) {
        $quantity = max(1, (int) ($cart[$product['id']] ?? 1));
        $lineTotal = (float) $product['price'] * $quantity;
        $lineDeposit = round($lineTotal * (($product['is_high_end'] ? (int) $product['deposit_percentage'] : 100) / 100));
        $itemStmt->execute([$orderId, $product['id'], $quantity, $product['price'], $lineTotal, $lineDeposit]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    set_flash('error', 'We could not submit your request cart. Please try again.');
    redirect('/user/cart/index.php');
}

$_SESSION['cart'] = [];
record_order_event($pdo, $orderId, $_SESSION['user_id'], 'request_submitted', 'Request submitted', 'Your multi-product request is awaiting admin review.');
create_notification($pdo, $_SESSION['user_id'], 'Order request submitted', 'Your multi-product request is awaiting admin review.', '/user/orders/view.php?id=' . $orderId);
log_activity($pdo, $_SESSION['user_id'], 'cart_request_submitted', "Submitted order #$orderId with " . count($products) . ' products');
set_flash('success', 'Your multi-product request has been submitted for approval.');
redirect('/user/orders/view.php?id=' . $orderId);
