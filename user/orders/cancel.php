<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/user/orders/index.php');
}
verify_csrf('/user/orders/index.php');

$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? AND status = 'pending_deposit'");
$stmt->execute([$id, $_SESSION['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    set_flash('error', 'This order can no longer be cancelled — a deposit has already been submitted or it is already closed. Contact support if you need help.');
    redirect('/user/orders/index.php');
}

$pdo->beginTransaction();
try {
    release_order_stock($pdo, $id);
    $pdo->prepare("UPDATE orders SET status = 'cancelled', cancellation_reason = 'Cancelled by customer before deposit' WHERE id = ?")
        ->execute([$id]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    set_flash('error', 'The order could not be cancelled. Please try again.');
    redirect('/user/orders/index.php');
}

record_order_event($pdo, $id, $_SESSION['user_id'], 'cancelled', 'Order cancelled', 'Cancelled by customer before deposit.');
log_activity($pdo, $_SESSION['user_id'], 'order_cancelled', "Customer cancelled order #$id");
set_flash('success', 'Your order has been cancelled.');
redirect('/user/orders/index.php');
