<?php
/**
 * Complete a point-of-sale sale.
 *
 * One transaction covers the sale order, its lines, the verified payment (or
 * wallet debit / credit sale), the stock depletion with its movement records
 * and the notifications — so a till sale is either recorded completely or not
 * at all. Every amount is recomputed server-side from the session cart.
 */
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/pos/index.php');
}
verify_csrf('/admin/pos/index.php');

$cart = pos_cart_state($pdo);

if (!$cart['lines']) {
    set_flash('error', 'There is nothing in the sale to complete.');
    redirect('/admin/pos/index.php');
}

$soldBy = (int) $_SESSION['user_id'];
$method = $cart['method'];
$total = round((float) $cart['total'], 2);
$subtotal = round((float) $cart['subtotal'], 2);
$discount = round((float) $cart['discount'], 2);
$paidAmount = 0.0;
$creditDueDate = null;
$reference = trim((string) $cart['reference']);
$walkInName = trim((string) ($cart['walk_in_name'] ?? ''));
// Who the till served, snapshotted onto the sale so the receipt prints what was
// true at the time rather than whatever the account says now.
$contactName = trim((string) $cart['contact_name']);
$contactPhone = trim((string) $cart['contact_phone']);
$contactEmail = trim((string) $cart['contact_email']);
$note = trim((string) $cart['note']);

// The walk-in account is only used when no customer was picked.
$customerId = (int) $cart['customer_id'];
$customerRow = $cart['customer'];

$methodRules = pos_available_methods($pdo);
if (!array_key_exists($method, $methodRules)) {
    set_flash('error', 'That payment method is not available on this till.');
    redirect('/admin/pos/index.php');
}

if ($method === 'cash') {
    $tendered = round(max(0.0, (float) $cart['tendered']), 2);
    if ($tendered <= 0) {
        $tendered = $total; // exact payment when nothing was entered
    }
    if ($tendered + 0.005 < $total) {
        set_flash('error', 'Cash received (' . money($tendered) . ') is less than the total of ' . money($total) . '.');
        redirect('/admin/pos/index.php');
    }
    $paidAmount = $total;
} elseif ($method === 'mobile_money' || $method === 'card') {
    $paidAmount = $total;
} elseif ($method === 'wallet') {
    if (!$customerRow) {
        set_flash('error', 'Wallet payment needs a registered customer. Select the customer first.');
        redirect('/admin/pos/index.php');
    }
    $paidAmount = $total;
} elseif ($method === 'credit') {
    if (!$customerRow) {
        set_flash('error', 'Credit sales need a registered customer. Select the customer first.');
        redirect('/admin/pos/index.php');
    }
    [$creditOk, $creditReason] = pos_credit_allowed($pdo, (int) $customerRow['id'], $total);
    if (!$creditOk) {
        set_flash('error', $creditReason);
        redirect('/admin/pos/index.php');
    }
    $terms = pos_credit_terms_days($pdo, (int) $customerRow['id']);
    $creditDueDate = date('Y-m-d', strtotime('+' . $terms . ' days'));
}

$orderStatus = $method === 'credit' ? 'processing' : 'completed';
$paymentStatus = $paidAmount >= $total - 0.005 ? 'paid' : 'unpaid';
$balanceAmount = round(max(0.0, $total - $paidAmount), 2);

$pdo->beginTransaction();
try {
    // Lock every product in the sale and confirm the shelf still holds it.
    foreach ($cart['lines'] as $line) {
        if ($line['made_to_order']) {
            continue;
        }
        $lock = $pdo->prepare('SELECT id, name, stock_quantity, reserved_quantity FROM products WHERE id = ? FOR UPDATE');
        $lock->execute([$line['product_id']]);
        $product = $lock->fetch();
        if (!$product) {
            throw new RuntimeException('A product in the sale is no longer available.');
        }
        $available = (int) $product['stock_quantity'] - (int) $product['reserved_quantity'];
        if ($available < $line['quantity']) {
            throw new RuntimeException('Only ' . max(0, $available) . ' of ' . $product['name'] . ' remain available.');
        }
    }

    $buyerId = $customerRow ? (int) $customerRow['id'] : pos_walk_in_user_id($pdo);
    $firstProduct = $cart['lines'][0];

    $stmt = $pdo->prepare(
        "INSERT INTO orders
            (user_id, product_id, quantity, unit_price, total_amount, deposit_amount,
             balance_amount, discount_amount, status, payment_status, delivery_address,
             customer_notes, credit_due_date, channel, sold_by, walk_in_customer_name,
             pos_contact_name, pos_contact_phone, pos_contact_email)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Collected in store', ?, ?, 'pos', ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $buyerId,
        (int) $firstProduct['product_id'],
        (int) $firstProduct['quantity'],
        (float) $firstProduct['unit_price'],
        $subtotal,
        $paidAmount,
        $balanceAmount,
        $discount,
        $orderStatus,
        $paymentStatus,
        $note !== '' ? $note : null,
        $creditDueDate,
        $soldBy,
        $customerRow ? null : ($walkInName !== '' ? $walkInName : null),
        $contactName !== '' ? $contactName : null,
        $contactPhone !== '' ? $contactPhone : null,
        $contactEmail !== '' ? $contactEmail : null,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    // Receipt and invoice numbers are derived from the order's own primary key,
    // so two tills can never hand out the same number.
    $pdo->prepare('UPDATE orders SET order_number = ?, invoice_number = ? WHERE id = ?')
        ->execute([pos_order_number($orderId), pos_invoice_number($orderId), $orderId]);

    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_amount, deposit_amount)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($cart['lines'] as $line) {
        $itemStmt->execute([
            $orderId,
            (int) $line['product_id'],
            (int) $line['quantity'],
            (float) $line['unit_price'],
            (float) $line['line_total'],
            $method === 'credit' ? 0.0 : (float) $line['line_total'],
        ]);
    }

    // Take the goods off the shelf and leave an auditable movement behind.
    $orderNumber = pos_order_number($orderId);
    foreach ($cart['lines'] as $line) {
        if ($line['made_to_order']) {
            continue;
        }

        $deplete = $pdo->prepare(
            "UPDATE products
             SET stock_quantity = stock_quantity - ?,
                 stock_status = CASE WHEN stock_quantity - ? > 0 THEN 'in_stock' ELSE 'out_of_stock' END
             WHERE id = ? AND (stock_quantity - reserved_quantity) >= ?"
        );
        $deplete->execute([$line['quantity'], $line['quantity'], $line['product_id'], $line['quantity']]);
        if ($deplete->rowCount() === 0) {
            throw new RuntimeException('Stock for ' . $line['name'] . ' ran out during the sale.');
        }

        record_inventory_movement(
            $pdo,
            (int) $line['product_id'],
            $soldBy,
            'shipped',
            -(int) $line['quantity'],
            null,
            $orderId,
            'POS sale ' . $orderNumber
        );

        $stockStmt = $pdo->prepare('SELECT stock_quantity, low_stock_threshold FROM products WHERE id = ?');
        $stockStmt->execute([$line['product_id']]);
        $stockState = $stockStmt->fetch();
        if ($stockState) {
            notify_low_stock($pdo, (int) $line['product_id'], (int) $stockState['stock_quantity'], (int) $stockState['low_stock_threshold']);
        }
    }

    // The payment record itself: verified on the spot, by the operator who rang it up.
    if ($paidAmount > 0) {
        $referenceNote = $orderNumber . ' · ' . pos_payment_method_label($method);
        if ($reference !== '') {
            $referenceNote .= ' · ref ' . $reference;
        }
        if ($method === 'cash') {
            $referenceNote .= ' · tendered ' . money($tendered) . ' · change ' . money(max(0.0, $tendered - $total));
        }

        $pdo->prepare(
            'INSERT INTO payments (order_id, user_id, amount, payment_type, method, reference_note, status, verified_by, verified_at)
             VALUES (?, ?, ?, ?, ?, ?, "verified", ?, NOW())'
        )->execute([
            $orderId,
            $buyerId,
            $paidAmount,
            pos_payment_record_type($pdo),
            $method,
            mb_substr($referenceNote, 0, 150),
            $soldBy,
        ]);
    }

    // Spending prepaid money removes it from the wallet, under the row lock.
    if ($method === 'wallet') {
        pos_wallet_move(
            $pdo,
            (int) $customerRow['id'],
            -$total,
            'sale',
            $orderNumber,
            $orderId,
            'Payment for sale ' . $orderNumber,
            $soldBy
        );
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $e->getMessage());
    redirect('/admin/pos/index.php');
}

// Everything below runs only once the sale is safely on disk.
pos_cart_clear();

record_order_event(
    $pdo,
    $orderId,
    $soldBy,
    'pos_sale',
    'Sale completed at the till',
    pos_payment_method_label($method) . ' · ' . money($total) . ' · ' . ($paidAmount >= $total ? 'paid in full' : 'on credit')
);

log_activity(
    $pdo,
    $soldBy,
    'pos_sale_completed',
    'POS sale ' . $orderNumber . ' (' . money($total) . ', ' . pos_payment_method_label($method) . ')'
);

if ($customerRow) {
    create_notification(
        $pdo,
        (int) $customerRow['id'],
        'Purchase ' . $orderNumber,
        'Your purchase of ' . money($total) . ' from the store is confirmed.'
            . ($method === 'credit' ? ' Payment is due on ' . date('d M Y', strtotime($creditDueDate)) . '.' : ''),
        '/user/orders/view.php?id=' . $orderId
    );
}

set_flash(
    'success',
    'Sale ' . $orderNumber . ' completed — ' . money($total) . ' by ' . pos_payment_method_label($method) . '.'
);
redirect('/admin/pos/receipt.php?order=' . $orderId . '&print=1');

