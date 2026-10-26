<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/mailer.php';
require_staff();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'The security token is invalid.']);
    exit;
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$action = $_POST['action'] ?? '';
$reason = trim($_POST['reason'] ?? '');
$newStatus = $action === 'approve' ? 'request_approved' : ($action === 'reject' ? 'request_rejected' : '');

if (!$orderId || !$newStatus || ($newStatus === 'request_rejected' && strlen($reason) > 255)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT o.*, u.full_name, u.email, p.name AS product_name
     FROM orders o JOIN users u ON u.id = o.user_id JOIN products p ON p.id = o.product_id
     WHERE o.id = ? AND o.status = 'request_submitted'"
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Order request not found or already reviewed.']);
    exit;
}

$pdo->beginTransaction();
try {
    if ($newStatus === 'request_approved') {
        reserve_order_stock($pdo, $orderId);
    }
    $pdo->prepare('UPDATE orders SET status = ?, rejection_reason = ?, payment_status = ? WHERE id = ?')
        ->execute([$newStatus, $newStatus === 'request_rejected' ? ($reason ?: null) : null, 'unpaid', $orderId]);
    if ($order['request_id']) {
        $pdo->prepare('UPDATE requests SET status = ?, resolution_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
            ->execute([$newStatus === 'request_approved' ? 'converted' : 'rejected', $reason ?: null, $_SESSION['user_id'], $order['request_id']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

create_notification(
    $pdo,
    (int) $order['user_id'],
    $newStatus === 'request_approved' ? 'Order request approved' : 'Order request rejected',
    $message = $newStatus === 'request_approved'
        ? "Your order #$orderId was approved. You may now submit payment."
        : "Your order #$orderId was rejected." . ($reason ? " Reason: $reason" : ''),
    '/user/orders/view.php?id=' . $orderId
);
record_order_event(
    $pdo,
    $orderId,
    $_SESSION['user_id'],
    $newStatus,
    $newStatus === 'request_approved' ? 'Request approved' : 'Request rejected',
    $reason ?: null
);

log_activity($pdo, $_SESSION['user_id'], $action === 'approve' ? 'request_approved' : 'request_rejected', "Order request #$orderId reviewed");
send_email($pdo, $order['email'], "Order request #$orderId update", "Hello {$order['full_name']},\n\n$message\n\nLog in to view your order details.");

echo json_encode(['success' => true, 'status' => $newStatus, 'message' => $message]);
