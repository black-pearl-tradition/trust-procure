<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/user/orders/index.php');
}
verify_csrf('/user/orders/index.php');

$orderId = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT o.*, u.full_name, p.name AS product_name
     FROM orders o
     JOIN users u ON u.id = o.user_id
     JOIN products p ON p.id = o.product_id
     WHERE o.id = ? AND o.user_id = ? AND o.status = 'delivered'"
);
$stmt->execute([$orderId, $_SESSION['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    set_flash('error', 'This order is not ready to be confirmed as received.');
    redirect('/user/orders/index.php');
}

$eventCheck = $pdo->prepare("SELECT COUNT(*) FROM order_events WHERE order_id = ? AND event_type = 'customer_received'");
$eventCheck->execute([$orderId]);
if ((int) $eventCheck->fetchColumn() > 0) {
    set_flash('info', 'You have already confirmed receipt of this order.');
    redirect('/user/orders/view.php?id=' . $orderId);
}

record_order_event(
    $pdo,
    $orderId,
    (int) $_SESSION['user_id'],
    'customer_received',
    'Customer confirmed receipt',
    'The customer confirmed that the order was received and is ready for staff follow-up.'
);
log_activity($pdo, (int) $_SESSION['user_id'], 'customer_received_order', "Customer confirmed receipt of order #$orderId");

$staffStmt = $pdo->query("SELECT id, full_name, email FROM users WHERE status = 'active' AND deleted_at IS NULL AND role IN ('staff', 'admin', 'super_admin')");
$staff = $staffStmt->fetchAll();
foreach ($staff as $staffId) {
    $message = "{$order['full_name']} confirmed receipt of order #{$orderId} for {$order['product_name']}. Review the order and update its status.";
    create_notification(
        $pdo,
        (int) $staffId['id'],
        'Customer confirmed receipt',
        $message,
        '/admin/orders/view.php?id=' . $orderId
    );
    log_activity($pdo, (int) $staffId['id'], 'customer_receipt_notification', $message);
    send_email($pdo, $staffId['email'], "Customer received order #{$orderId}", "Hello {$staffId['full_name']},\n\n{$message}\n\nLog in to Trust Procurement to review the order.");
}

set_flash('success', 'Thanks. The team has been notified that you received your order.');
redirect('/user/orders/view.php?id=' . $orderId);
