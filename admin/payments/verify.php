<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/payments.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/payments/index.php');
}
verify_csrf('/admin/payments/index.php');

$id = (int) ($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'verify';
$reason = trim($_POST['reason'] ?? '');

if (!in_array($action, ['verify', 'reject'], true)) {
    set_flash('error', 'Invalid payment review action.');
    redirect('/admin/payments/index.php');
}
if ($action === 'reject' && ($reason === '' || strlen($reason) > 255)) {
    set_flash('error', 'A rejection reason is required and must be 255 characters or fewer.');
    redirect('/admin/payments/view.php?id=' . $id);
}

$newStatus = $action === 'reject' ? 'rejected' : 'verified';
$summary = null;

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "SELECT pay.*, u.full_name, u.email, o.id AS order_id, p.name AS product_name
         FROM payments pay
         JOIN users u ON u.id = pay.user_id
         JOIN orders o ON o.id = pay.order_id
         JOIN products p ON p.id = o.product_id
         WHERE pay.id = ? AND pay.status = 'pending'
         FOR UPDATE"
    );
    $stmt->execute([$id]);
    $payment = $stmt->fetch();
    if (!$payment) {
        $pdo->rollBack();
        set_flash('error', 'Payment not found or already reviewed.');
        redirect('/admin/payments/index.php');
    }

    if ($newStatus === 'verified') {
        lockAndValidatePaymentForVerification($pdo, $payment);
    }
    $updated = $pdo->prepare("UPDATE payments SET status = ?, rejection_reason = ?, verified_by = ?, verified_at = NOW() WHERE id = ? AND status = 'pending'");
    $updated
        ->execute([$newStatus, $newStatus === 'rejected' ? $reason : null, $_SESSION['user_id'], $id]);
    if ($updated->rowCount() !== 1) {
        throw new RuntimeException('The payment was already reviewed.');
    }

    if ($newStatus === 'verified') {
        $summary = updateOrderPaymentStatus($pdo, (int) $payment['order_id']);
    }
    $allDuesCleared = $newStatus === 'verified' && $summary && $summary['remaining'] <= 0;
    record_order_event(
        $pdo,
        (int) $payment['order_id'],
        $_SESSION['user_id'],
        'payment_' . $newStatus,
        $allDuesCleared ? 'All order dues cleared' : 'Payment ' . $newStatus,
        $allDuesCleared
            ? 'Payment #' . $id . ' was verified; all order dues are cleared.'
            : money($payment['amount']) . ' ' . $payment['payment_type'] . ' payment'
    );
    log_activity($pdo, $_SESSION['user_id'], 'payment_' . $newStatus, "Payment #$id marked $newStatus");
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Payment review failed for payment #' . $id . ': ' . $e->getMessage());
    set_flash(
        'error',
        $e instanceof RuntimeException
            ? 'Payment could not be verified: ' . $e->getMessage()
            : 'Payment review failed. Please try again.'
    );
    redirect('/admin/payments/view.php?id=' . $id);
}
$notificationTitle = $newStatus === 'verified' ? 'Payment received' : 'Proof unclear — re-upload needed';
$notificationMessage = $newStatus === 'verified'
    ? "Your {$payment['payment_type']} payment of " . money($payment['amount']) . " for order #{$payment['order_id']} was received and verified."
    . ($allDuesCleared ? ' All dues are cleared and the order is fully paid.' : '')
    : "Your {$payment['payment_type']} payment for order #{$payment['order_id']} needs a clearer proof." . ($reason ? " What to fix: $reason" : '') . " Open the payment and tap “Re-upload clearer proof”.";
create_notification($pdo, (int) $payment['user_id'], $notificationTitle, $notificationMessage, '/user/payments/view.php?id=' . $id);

$typeLabel = ucfirst($payment['payment_type']);
if ($newStatus === 'verified') {
    $body = "Hello {$payment['full_name']},\n\n"
        . "We've verified your {$payment['payment_type']} payment of " . money($payment['amount']) . " for order #{$payment['order_id']} ({$payment['product_name']}).\n\n"
        . ($allDuesCleared
            ? "All dues are cleared and your order is fully paid."
            : ($payment['payment_type'] === 'deposit'
                ? "Procurement will now begin on your order."
                : "Your payment has been recorded."))
        . "\n\nYou can track progress from your account at any time.";
    send_email($pdo, $payment['email'], "$typeLabel payment verified — Order #{$payment['order_id']}", $body);
} else {
    $body = "Hello {$payment['full_name']},\n\n"
        . "We couldn't read your {$payment['payment_type']} payment proof of " . money($payment['amount']) . " for order #{$payment['order_id']} ({$payment['product_name']}).\n\n"
        . ($reason ? "What to fix: {$reason}\n\n" : '')
        . "Please log in, open Payment #{$id}, and tap “Re-upload clearer proof” to submit a sharper photo showing the amount, date, and reference. Your payment stays on the same record — no need to pay again.";
    send_email($pdo, $payment['email'], "$typeLabel payment needs a clearer proof — Order #{$payment['order_id']}", $body);
}

set_flash('success', 'Payment ' . $newStatus . '.');
redirect('/admin/payments/view.php?id=' . $id);
