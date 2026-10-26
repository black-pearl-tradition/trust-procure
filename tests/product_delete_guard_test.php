<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$pdo->beginTransaction();

try {
    $productId = (int) $pdo->query("SELECT id FROM products WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    if ($productId === 0) {
        throw new RuntimeException('No active product available for delete-guard coverage.');
    }

    $userId = (int) $pdo->query("SELECT id FROM users WHERE status = 'active' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    if ($userId === 0) {
        throw new RuntimeException('No active user available for order fixture setup.');
    }

    $orderId = (int) $pdo->query("SELECT id FROM orders WHERE product_id = {$productId} ORDER BY id LIMIT 1")->fetchColumn();
    if ($orderId === 0) {
        $pdo->prepare('INSERT INTO orders (user_id, product_id, quantity, unit_price, total_amount, deposit_amount, balance_amount, status, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $productId, 1, 100.00, 100.00, 0.00, 0.00, 'request_submitted', 'unpaid']);
        $orderId = (int) $pdo->lastInsertId();
    }

    $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_amount, deposit_amount) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$orderId, $productId, 1, 100.00, 100.00, 0.00]);

    $reason = product_delete_block_reason($pdo, $productId);
    assert_true($reason !== null && $reason !== '', 'Deleting a product tied to order history should be blocked.');
    echo "Product delete guard works.\n";
} finally {
    $pdo->rollBack();
}
