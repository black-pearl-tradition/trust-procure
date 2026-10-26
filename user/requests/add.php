<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/products.php');
}
verify_csrf('/products.php');

$productId = (int) ($_POST['product_id'] ?? 0);
$quantity = max(1, (int) ($_POST['quantity'] ?? 1));
$notes = trim($_POST['notes'] ?? '');
$deliveryAddress = trim($_POST['delivery_address'] ?? '');

$stmt = $pdo->prepare("SELECT id, name, price, is_high_end, deposit_percentage FROM products WHERE id = ? AND status = 'active'");
$stmt->execute([$productId]);
$product = $stmt->fetch();

if (!$product) {
    set_flash('error', 'That product is not available for request.');
    redirect('/products.php');
}

$total = (float) $product['price'] * $quantity;
$deposit = round($total * (($product['is_high_end'] ? (int) $product['deposit_percentage'] : 100) / 100));
$balance = $total - $deposit;

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO requests (user_id, product_id, quantity, notes, delivery_address) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$_SESSION['user_id'], $productId, $quantity, $notes, $deliveryAddress ?: null]);
    $requestId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        "INSERT INTO orders (request_id, user_id, product_id, quantity, unit_price, total_amount, deposit_amount, balance_amount, status, payment_status, delivery_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'request_submitted', 'unpaid', ?)"
    );
    $stmt->execute([
        $requestId,
        $_SESSION['user_id'],
        $productId,
        $quantity,
        $product['price'],
        $total,
        $deposit,
        $balance,
        $deliveryAddress ?: null,
    ]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    set_flash('error', 'We could not submit your order request. Please try again.');
    redirect('/products.php');
}

log_activity($pdo, $_SESSION['user_id'], 'request_submitted', "Requested product #$productId");
record_order_event($pdo, $orderId, $_SESSION['user_id'], 'request_submitted', 'Request submitted', 'Your order request is awaiting admin review.');
create_notification($pdo, $_SESSION['user_id'], 'Order request submitted', "Your request for {$product['name']} is awaiting admin review.", '/user/orders/view.php?id=' . $orderId);
log_activity($pdo, null, 'admin_notification', "New order request #$orderId requires review");
set_flash('success', 'Your request has been submitted. We will review it shortly.');

if ((($_POST['ajax'] ?? '') === '1') || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'redirect' => url('/user/requests/index.php'), 'message' => 'Your request has been submitted. We will review it shortly.']);
    exit;
}
redirect('/user/requests/index.php');
