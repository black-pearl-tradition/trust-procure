<?php
require_once __DIR__ . '/functions.php';

function getOrderPaymentOptions(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT total_amount, deposit_amount, balance_amount, discount_amount FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    // A till sale can carry a discount: it comes off what the customer owes, so
    // both gross and net are needed here — the discount is subtracted rather
    // than rewritten into the line totals.
    $discount = (float) ($order['discount_amount'] ?? 0);

    $itemStmt = $pdo->prepare('SELECT total_amount, deposit_amount FROM order_items WHERE order_id = ?');
    $itemStmt->execute([$orderId]);
    $items = $itemStmt->fetchAll();
    if ($items) {
        $total = array_sum(array_column($items, 'total_amount'));
        $deposit = array_sum(array_column($items, 'deposit_amount'));
    } else {
        $total = (float) $order['total_amount'];
        $deposit = (float) $order['deposit_amount'];
    }
    $total = max(0, $total - $discount);
    $deposit = min($deposit, $total);
    $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND status = 'verified'");
    $paidStmt->execute([$orderId]);
    $totalPaid = (float) $paidStmt->fetchColumn();

    return [
        'total' => $total,
        'deposit' => $deposit,
        'balance' => max($total - $totalPaid, 0),
        'total_paid' => $totalPaid,
        'requires_deposit' => $deposit < $total,
        'deposit_percentage' => $total > 0 ? round(($deposit / $total) * 100) : 100,
    ];
}

/** Lock the order and confirm a pending payment cannot overstate what is still owed. */
function lockAndValidatePaymentForVerification(PDO $pdo, array $payment): void
{
    $stmt = $pdo->prepare('SELECT user_id, status FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([(int) $payment['order_id']]);
    $order = $stmt->fetch();
    if (!$order || (int) $order['user_id'] !== (int) $payment['user_id']) {
        throw new RuntimeException('The payment does not match an available customer order.');
    }

    $options = getOrderPaymentOptions($pdo, (int) $payment['order_id']);
    $amount = round((float) $payment['amount'], 2);
    if ($amount <= 0 || $amount > round($options['balance'], 2)) {
        throw new RuntimeException('The submitted amount is greater than the order balance still due.');
    }

    $initialPayment = in_array($order['status'], ['request_approved', 'pending_deposit'], true);
    if ($payment['payment_type'] === 'deposit') {
        if (!$initialPayment || !$options['requires_deposit']) {
            throw new RuntimeException('A deposit is not valid at the current order stage.');
        }
        if ($amount !== round($options['deposit'], 2)) {
            throw new RuntimeException('The deposit amount does not match the required deposit.');
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM payments
             WHERE order_id = ? AND payment_type = 'deposit' AND status = 'verified'"
        );
        $stmt->execute([(int) $payment['order_id']]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('A deposit for this order has already been verified.');
        }
    } elseif ($payment['payment_type'] === 'balance') {
        if (!$initialPayment && $order['status'] !== 'delivered') {
            throw new RuntimeException('The balance cannot be verified at the current order stage.');
        }
        if ($amount !== round($options['balance'], 2)) {
            throw new RuntimeException('The payment amount does not match the balance still due.');
        }
    } else {
        throw new RuntimeException('This payment type cannot be verified against an online order.');
    }
}

/** Recalculate verified totals and the order's payment state in one transaction. */
function updateOrderPaymentStatus(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT total_amount, discount_amount, status, channel FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND status = 'verified'");
    $stmt->execute([$orderId]);
    $totalPaid = (float) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE order_id = ? AND payment_type = 'deposit' AND status = 'verified'");
    $stmt->execute([$orderId]);
    $hasVerifiedDeposit = (int) $stmt->fetchColumn() > 0;
    // What is still owed after the discount and everything already settled.
    $remaining = max((float) $order['total_amount'] - (float) ($order['discount_amount'] ?? 0) - $totalPaid, 0);
    $paymentStatus = $totalPaid <= 0 ? 'unpaid' : ($remaining <= 0 ? 'paid' : 'partially_paid');

    $newOrderStatus = $order['status'];
    if ($totalPaid > 0) {
        if ($hasVerifiedDeposit && $remaining > 0 && in_array($order['status'], ['request_approved', 'pending_deposit'], true)) {
            $newOrderStatus = 'deposit_paid';
        }
        if ($remaining <= 0 && ($order['status'] === 'processing' || $order['status'] === 'deposit_paid')) {
            // A till sale (or an approved order) finishes once it is settled.
            $newOrderStatus = 'completed';
        }
    }

    $stmt = $pdo->prepare('UPDATE orders SET balance_amount = ?, payment_status = ?, status = ? WHERE id = ?');
    $stmt->execute([$remaining, $paymentStatus, $newOrderStatus, $orderId]);

    return ['total_paid' => $totalPaid, 'remaining' => $remaining, 'payment_status' => $paymentStatus, 'order_status' => $newOrderStatus];
}
