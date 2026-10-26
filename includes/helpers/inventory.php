<?php

/** Record an auditable stock movement. Call inside the surrounding transaction. */
function record_inventory_movement(
    PDO $pdo,
    int $productId,
    ?int $userId,
    string $movementType,
    int $quantityChange,
    ?int $purchaseOrderId = null,
    ?int $orderId = null,
    ?string $note = null
): void {
    $allowedTypes = ['received', 'shipped', 'adjustment', 'return'];
    if (!in_array($movementType, $allowedTypes, true) || $quantityChange === 0) {
        throw new InvalidArgumentException('Invalid inventory movement.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO inventory_movements (product_id, user_id, movement_type, quantity_change, purchase_order_id, order_id, note)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$productId, $userId, $movementType, $quantityChange, $purchaseOrderId, $orderId, $note]);
}

function get_order_stock_requirements(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();
    if (!$items) {
        $stmt = $pdo->prepare('SELECT product_id, quantity FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll();
    }

    $requirements = [];
    foreach ($items as $item) {
        $productId = (int) $item['product_id'];
        $requirements[$productId] = ($requirements[$productId] ?? 0) + (int) $item['quantity'];
    }
    return $requirements;
}

function reserve_order_stock(PDO $pdo, int $orderId): void
{
    foreach (get_order_stock_requirements($pdo, $orderId) as $productId => $quantity) {
        $stmt = $pdo->prepare('SELECT name, stock_quantity, reserved_quantity, stock_status FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product || $product['stock_status'] === 'made_to_order') {
            continue;
        }

        $available = (int) $product['stock_quantity'] - (int) $product['reserved_quantity'];
        if ($available < $quantity) {
            throw new RuntimeException('Insufficient available stock for ' . ($product['name'] ?? 'the requested product') . '.');
        }

        $pdo->prepare('UPDATE products SET reserved_quantity = reserved_quantity + ? WHERE id = ?')
            ->execute([$quantity, $productId]);
    }
}

function release_order_stock(PDO $pdo, int $orderId): void
{
    foreach (get_order_stock_requirements($pdo, $orderId) as $productId => $quantity) {
        $pdo->prepare('UPDATE products SET reserved_quantity = GREATEST(reserved_quantity - ?, 0) WHERE id = ?')
            ->execute([$quantity, $productId]);
    }
}

function product_delete_block_reason(PDO $pdo, int $productId): ?string
{
    $checks = [
        ['SELECT COUNT(*) FROM order_items WHERE product_id = ?', 'This product appears in order history and cannot be deleted. Archive it instead.'],
        ['SELECT COUNT(*) FROM purchase_order_items WHERE product_id = ?', 'This product is linked to purchase orders and cannot be deleted. Archive it instead.'],
        ['SELECT COUNT(*) FROM requests WHERE product_id = ?', 'This product has procurement requests and cannot be deleted. Archive it instead.'],
        ['SELECT COUNT(*) FROM orders WHERE product_id = ?', 'This product has customer orders and cannot be deleted. Archive it instead.'],
    ];

    foreach ($checks as [$sql, $message]) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$productId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return $message;
        }
    }

    return null;
}

/** Notify operational users when a product reaches a low or empty stock level. */
function notify_low_stock(PDO $pdo, int $productId, int $stockQuantity, int $threshold): void
{
    if ($threshold <= 0 || ($stockQuantity > $threshold && $stockQuantity > 0)) {
        return;
    }

    $productStmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
    $productStmt->execute([$productId]);
    $productName = $productStmt->fetchColumn();
    if (!$productName) {
        return;
    }

    $isEmpty = $stockQuantity <= 0;
    $title = $isEmpty ? 'Product out of stock' : 'Low stock alert';
    $message = $isEmpty
        ? $productName . ' is out of stock.'
        : $productName . ' has only ' . $stockQuantity . ' unit(s) remaining.';
    $link = '/admin/inventory/index.php?stock=' . ($isEmpty ? 'out' : 'low');

    $users = $pdo->query("SELECT id FROM users WHERE role IN ('staff', 'admin', 'super_admin') AND status = 'active' AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
    $existingStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = ? AND message = ? AND is_read = 0'
    );
    foreach ($users as $userId) {
        $existingStmt->execute([(int) $userId, $title, $message]);
        if ((int) $existingStmt->fetchColumn() === 0) {
            create_notification($pdo, (int) $userId, $title, $message, $link);
        }
    }
}
