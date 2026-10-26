<?php

/**
 * Exercises deposit and balance verification against the configured database.
 * All fixture rows are written in a transaction that is always rolled back.
 */
$host = getenv('DB_HOST') ?: 'localhost';
$name = getenv('DB_NAME') ?: 'procure_db';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

try {
    new PDO(
        'mysql:host=' . $host . ';port=3306;dbname=' . $name . ';charset=utf8mb4',
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo 'SKIP: no database reachable (' . $e->getMessage() . ')' . PHP_EOL;
    exit(0);
}

require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/payments.php';

function payment_audit_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$pdo->beginTransaction();
try {
    $userId = (int) $pdo->query("SELECT id FROM users WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    $productId = (int) $pdo->query("SELECT id FROM products ORDER BY id LIMIT 1")->fetchColumn();
    if (!$userId || !$productId) {
        echo "SKIP: payment test needs one user and one product.\n";
        $pdo->rollBack();
        exit(0);
    }

    $pdo->prepare(
        "INSERT INTO orders (user_id, product_id, quantity, unit_price, total_amount, deposit_amount, balance_amount, status, payment_status)
         VALUES (?, ?, 1, 100.00, 100.00, 30.00, 70.00, 'request_approved', 'unpaid')"
    )->execute([$userId, $productId]);
    $orderId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO payments (order_id, user_id, amount, payment_type, status)
         VALUES (?, ?, 30.00, 'deposit', 'pending')"
    )->execute([$orderId, $userId]);
    $paymentId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
    $stmt->execute([$paymentId]);
    $deposit = $stmt->fetch();
    lockAndValidatePaymentForVerification($pdo, $deposit);
    $pdo->prepare("UPDATE payments SET status = 'verified' WHERE id = ?")->execute([$paymentId]);

    $summary = updateOrderPaymentStatus($pdo, $orderId);
    payment_audit_assert($summary['total_paid'] === 30.0, 'Verifying the deposit should count its payment row once.');
    payment_audit_assert($summary['remaining'] === 70.0, 'The outstanding balance should be reduced by the deposit.');
    payment_audit_assert($summary['payment_status'] === 'partially_paid', 'A verified deposit should mark the order partially paid.');

    $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    payment_audit_assert($stmt->fetchColumn() === 'deposit_paid', 'A verified deposit should advance the order state.');

    $pdo->prepare("UPDATE orders SET status = 'delivered' WHERE id = ?")->execute([$orderId]);
    $pdo->prepare(
        "INSERT INTO payments (order_id, user_id, amount, payment_type, status)
         VALUES (?, ?, 70.00, 'balance', 'pending')"
    )->execute([$orderId, $userId]);
    $balancePaymentId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
    $stmt->execute([$balancePaymentId]);
    $balancePayment = $stmt->fetch();
    lockAndValidatePaymentForVerification($pdo, $balancePayment);

    $pdo->prepare('UPDATE payments SET amount = 71.00 WHERE id = ?')->execute([$balancePaymentId]);
    $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
    $stmt->execute([$balancePaymentId]);
    $overpayment = $stmt->fetch();
    $rejectedOverpayment = false;
    try {
        lockAndValidatePaymentForVerification($pdo, $overpayment);
    } catch (RuntimeException $e) {
        $rejectedOverpayment = str_contains($e->getMessage(), 'greater than the order balance');
    }
    payment_audit_assert($rejectedOverpayment, 'Admin verification should refuse an amount above the remaining balance.');

    $pdo->prepare('UPDATE payments SET amount = 70.00, status = \'verified\' WHERE id = ?')->execute([$balancePaymentId]);
    $summary = updateOrderPaymentStatus($pdo, $orderId);
    payment_audit_assert($summary['total_paid'] === 100.0, 'Deposit and balance records should total the order amount exactly once.');
    payment_audit_assert($summary['remaining'] === 0.0, 'The balance should reach zero after both payments are verified.');
    payment_audit_assert($summary['payment_status'] === 'paid', 'The order should be marked paid after both records are verified.');

    echo "Payment audit tests passed.\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
