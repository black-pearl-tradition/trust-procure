<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/orders/index.php');
}
verify_csrf('/admin/orders/index.php');

$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT r.*, o.id AS existing_order_id, u.full_name, u.email, p.name AS product_name, p.price, p.is_high_end, p.deposit_percentage
     FROM requests r
     LEFT JOIN orders o ON o.request_id = r.id
     JOIN users u ON u.id = r.user_id
     JOIN products p ON p.id = r.product_id
     WHERE r.id = ? AND r.status = 'pending'"
);
$stmt->execute([$id]);
$request = $stmt->fetch();

if (!$request) {
    set_flash('error', 'Request not found or already reviewed.');
    redirect('/admin/orders/index.php');
}

$total = $request['price'] * $request['quantity'];
$depositPct = $request['is_high_end'] ? (int) $request['deposit_percentage'] : 100;
$deposit = round($total * ($depositPct / 100));
$balance = $total - $deposit;

$pdo->beginTransaction();
try {
    if ($request['existing_order_id']) {
        $orderId = (int) $request['existing_order_id'];
        $pdo->prepare(
            "UPDATE orders SET status = 'pending_deposit', payment_status = 'unpaid',
                total_amount = ?, deposit_amount = ?, balance_amount = ?, delivery_address = ?
             WHERE id = ?"
        )->execute([$total, $deposit, $balance, $request['delivery_address'], $orderId]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO orders (request_id, user_id, product_id, quantity, unit_price, total_amount, deposit_amount, balance_amount, status, delivery_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $request['id'],
            $request['user_id'],
            $request['product_id'],
            $request['quantity'],
            $request['price'],
            $total,
            $deposit,
            $balance,
            'pending_deposit',
            $request['delivery_address'],
        ]);
        $orderId = (int) $pdo->lastInsertId();
    }

    reserve_order_stock($pdo, $orderId);

    $pdo->prepare("UPDATE requests SET status = 'converted', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
        ->execute([$_SESSION['user_id'], $request['id']]);

    $pdo->commit();
    log_activity($pdo, $_SESSION['user_id'], 'request_approved', "Approved request #{$request['id']} and created order");

    create_notification($pdo, (int) $request['user_id'], 'Request approved', "Your request for {$request['product_name']} was approved. You may now submit payment.", '/user/orders/view.php?id=' . $orderId);
    record_order_event($pdo, $orderId, $_SESSION['user_id'], 'request_approved', 'Request approved', 'The request was approved and is ready for payment.');
    $body = "Hello {$request['full_name']},\n\n"
        . "Good news — your request for {$request['product_name']} (quantity {$request['quantity']}) has been approved.\n\n"
        . "Order total: " . money($total) . "\n"
        . "Deposit due now: " . money($deposit) . "\n"
        . "Balance due later: " . money($balance) . "\n\n"
        . "Log in to your account to submit your deposit payment and start procurement.";
    send_email($pdo, $request['email'], 'Your Trust Procurement request was approved', $body);

    set_flash('success', 'Request approved and order created.');
} catch (Exception $e) {
    $pdo->rollBack();
    set_flash('error', 'Something went wrong while approving this request.');
}

redirect('/admin/orders/index.php');
