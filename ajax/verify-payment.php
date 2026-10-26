<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/payments.php';
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

$paymentId = (int) ($_POST['payment_id'] ?? 0);
$action = $_POST['action'] ?? '';
$reason = trim($_POST['reason'] ?? '');
if (!$paymentId || !in_array($action, ['verify', 'reject'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid payment review.']);
    exit;
}
if ($action === 'reject' && ($reason === '' || strlen($reason) > 255)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A rejection reason is required and must be 255 characters or fewer.']);
    exit;
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND status = 'pending' FOR UPDATE");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();
    if (!$payment) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment not found or already reviewed.']);
        exit;
    }

    $newStatus = $action === 'verify' ? 'verified' : 'rejected';
    if ($newStatus === 'verified') {
        lockAndValidatePaymentForVerification($pdo, $payment);
    }
    $updated = $pdo->prepare("UPDATE payments SET status = ?, rejection_reason = ?, verified_by = ?, verified_at = NOW() WHERE id = ? AND status = 'pending'");
    $updated->execute([$newStatus, $newStatus === 'rejected' ? $reason : null, $_SESSION['user_id'], $paymentId]);
    if ($updated->rowCount() !== 1) {
        throw new RuntimeException('The payment was already reviewed.');
    }
    $summary = $newStatus === 'verified' ? updateOrderPaymentStatus($pdo, (int) $payment['order_id']) : null;
    $allDuesCleared = $newStatus === 'verified' && $summary && $summary['remaining'] <= 0;
    $notificationMessage = $allDuesCleared
        ? "Your payment for order #{$payment['order_id']} was verified. All dues are cleared and the order is fully paid."
        : "Your {$payment['payment_type']} payment for order #{$payment['order_id']} was $newStatus."
            . ($newStatus === 'rejected' ? " What to fix: $reason — open the payment and tap “Re-upload clearer proof”." : '');
    record_order_event(
        $pdo,
        (int) $payment['order_id'],
        $_SESSION['user_id'],
        'payment_' . $newStatus,
        $allDuesCleared ? 'All order dues cleared' : ($newStatus === 'verified' ? 'Payment verified' : 'Payment rejected'),
        $allDuesCleared ? $notificationMessage : money($payment['amount']) . ' ' . $payment['payment_type'] . ' payment'
    );
    log_activity($pdo, $_SESSION['user_id'], 'payment_' . $newStatus, "Payment #$paymentId marked $newStatus");
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Payment review failed for payment #' . $paymentId . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e instanceof RuntimeException
            ? 'Payment could not be verified: ' . $e->getMessage()
            : 'Payment review failed.',
    ]);
    exit;
}

$notificationTitle = $allDuesCleared ? 'All order dues cleared' : ($newStatus === 'verified' ? 'Payment verified' : 'Proof unclear — re-upload needed');
create_notification(
    $pdo,
    (int) $payment['user_id'],
    $notificationTitle,
    $notificationMessage,
    '/user/payments/view.php?id=' . $paymentId
);
echo json_encode(['success' => true, 'status' => $newStatus, 'summary' => $summary]);
