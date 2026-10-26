<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/orders/index.php');
}
verify_csrf('/admin/orders/index.php');

$id = (int) ($_POST['id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

$stmt = $pdo->prepare(
    "SELECT r.*, o.id AS existing_order_id, u.full_name, u.email, p.name AS product_name FROM requests r
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

$orderId = (int) ($request['existing_order_id'] ?? 0);

$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE requests SET status = 'rejected', resolution_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
        ->execute([$reason ?: null, $_SESSION['user_id'], $id]);

    if ($orderId > 0) {
        $pdo->prepare("UPDATE orders SET status = 'request_rejected', rejection_reason = ? WHERE id = ? AND status = 'request_submitted'")
            ->execute([$reason ?: null, $orderId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    set_flash('error', 'The request could not be rejected. Please try again.');
    redirect('/admin/orders/index.php');
}

log_activity($pdo, $_SESSION['user_id'], 'request_rejected', "Rejected request #$id");

if ($orderId > 0) {
    record_order_event($pdo, $orderId, $_SESSION['user_id'], 'request_rejected', 'Request rejected', $reason ?: 'The request was not approved.');
    create_notification($pdo, (int) $request['user_id'], 'Order request rejected', $reason ? "Your request for {$request['product_name']} was rejected: {$reason}" : "Your request for {$request['product_name']} was not approved.", '/user/orders/view.php?id=' . $orderId);
}

$body = "Hello {$request['full_name']},\n\n"
    . "Your request for {$request['product_name']} (quantity {$request['quantity']}) was not approved.\n"
    . ($reason ? "Reason: {$reason}\n\n" : "\n")
    . "If you have questions, feel free to reply to this email or contact us through the site.";
send_email($pdo, $request['email'], 'Update on your Trust Procurement request', $body);

set_flash('success', 'Request rejected.');
redirect('/admin/orders/index.php');
