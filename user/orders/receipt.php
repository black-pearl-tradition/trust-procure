<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/payments.php';
require_user();

$orderId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT o.*, u.full_name, u.email, u.phone, p.name AS product_name
     FROM orders o
     JOIN users u ON u.id = o.user_id
     JOIN products p ON p.id = o.product_id
     WHERE o.id = ? AND o.user_id = ?"
);
$stmt->execute([$orderId, $_SESSION['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    set_flash('error', 'Order not found.');
    redirect('/user/orders/index.php');
}

$itemsStmt = $pdo->prepare(
    'SELECT oi.quantity, oi.unit_price, oi.total_amount, p.name AS product_name
     FROM order_items oi JOIN products p ON p.id = oi.product_id
     WHERE oi.order_id = ? ORDER BY oi.id'
);
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();
if (!$items) {
    $items = [[
        'product_name' => $order['product_name'],
        'quantity' => $order['quantity'],
        'unit_price' => $order['unit_price'],
        'total_amount' => $order['total_amount'],
    ]];
}

$paymentsStmt = $pdo->prepare(
    "SELECT payment_type, amount, method, reference_note, verified_at
     FROM payments WHERE order_id = ? AND status = 'verified' ORDER BY verified_at, id"
);
$paymentsStmt->execute([$orderId]);
$payments = $paymentsStmt->fetchAll();

if (!$payments) {
    set_flash('error', 'A receipt is available after payment has been verified.');
    redirect('/user/orders/view.php?id=' . $orderId);
}

// Receipts unlock only once the order is delivered (or completed after it).
if (!in_array($order['status'], ['delivered', 'completed'], true)) {
    set_flash('error', 'Your receipt will be available once your order is delivered.');
    redirect('/user/orders/view.php?id=' . $orderId);
}

$totalPaid = array_sum(array_column($payments, 'amount'));
$pageTitle = 'Receipt for Order #' . $orderId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — Trust Procurement</title>
    <link rel="stylesheet" href="<?= url('/assets/css/style.css?v=20261026-header-height') ?>">
    <script defer src="<?= url('/assets/js/system-notifications.js?v=20261014') ?>"></script>
    <style>
        .receipt-shell { max-width: 820px; margin: 32px auto; padding: 40px; background: var(--surface); border: 1px solid var(--border); font-family: Georgia, 'Times New Roman', serif; }
        .receipt-shell h1, .receipt-shell h2, .receipt-shell h3, .receipt-shell .brand, .receipt-shell .btn, .receipt-shell button { font-family: Georgia, 'Times New Roman', serif; }
        .receipt-shell i { font-family: "bootstrap-icons" !important; }
        .receipt-toolbar { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 28px; }
        .receipt-header { display: flex; justify-content: space-between; gap: 32px; padding-bottom: 24px; border-bottom: 3px solid var(--accent); }
        .receipt-header h1 { margin: 0 0 8px; font-size: 2rem; }
        .receipt-meta { text-align: right; color: var(--ink-soft); }
        .receipt-customer { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; padding: 24px 0; }
        .receipt-label { color: var(--ink-faint); font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .receipt-value { margin-top: 4px; color: var(--ink); }
        .receipt-table { width: 100%; margin: 8px 0 24px; border-collapse: collapse; }
        .receipt-table th, .receipt-table td { padding: 12px 10px; border-bottom: 1px solid var(--border); text-align: left; }
        .receipt-table th { color: var(--ink-soft); font-size: .78rem; text-transform: uppercase; }
        .receipt-table td:last-child, .receipt-table th:last-child { text-align: right; }
        .receipt-total { max-width: 360px; margin-left: auto; }
        .receipt-total div { display: flex; justify-content: space-between; gap: 20px; padding: 8px 0; }
        .receipt-total .grand-total { border-top: 2px solid var(--ink); font-size: 1.1rem; font-weight: 700; }
        .receipt-note { margin-top: 28px; color: var(--ink-soft); font-size: .85rem; }
        @media (max-width: 640px) {
            .receipt-shell { margin: 0; padding: 24px 20px; border: 0; }
            .receipt-toolbar, .receipt-header { align-items: flex-start; flex-direction: column; }
            .receipt-meta { text-align: left; }
            .receipt-customer { grid-template-columns: 1fr; gap: 12px; }
            .receipt-table { font-size: .82rem; }
        }
        @media print {
            .receipt-toolbar { display: none; }
            .receipt-shell { max-width: none; margin: 0; padding: 0; border: 0; }
            body { background: #fff; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/partials/system_notifications.php'; ?>
    <main class="receipt-shell">
        <div class="receipt-toolbar">
            <a href="<?= url('/user/orders/view.php?id=' . $orderId) ?>" class="btn btn-outline">Back to order</a>
            <button type="button" class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer me-2" aria-hidden="true"></i>Print / Save PDF</button>
        </div>
        <header class="receipt-header">
            <div>
                <div class="brand">Trust<span>Procure</span></div>
                <h1>Payment receipt</h1>
                <p>Thank you for your purchase.</p>
            </div>
            <div class="receipt-meta">
                <strong>Receipt for Order #<?= $orderId ?></strong><br>
                Issued <?= e(date('d M Y, H:i')) ?><br>
                Status: <?= e(order_status_label($order['status'])) ?>
            </div>
        </header>
        <section class="receipt-customer">
            <div><div class="receipt-label">Customer</div><div class="receipt-value"><?= e($order['full_name']) ?></div><div class="muted"><?= e($order['email']) ?></div></div>
            <div><div class="receipt-label">Delivery address</div><div class="receipt-value"><?= e($order['delivery_address'] ?: '—') ?></div></div>
        </section>
        <table class="receipt-table">
            <thead><tr><th>Product</th><th>Quantity</th><th>Unit price</th><th>Total</th></tr></thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr><td><?= e($item['product_name']) ?></td><td><?= (int) $item['quantity'] ?></td><td><?= money($item['unit_price']) ?></td><td><?= money($item['total_amount']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="receipt-total">
            <div><span>Order total</span><strong><?= money($order['total_amount']) ?></strong></div>
            <div><span>Verified paid</span><strong><?= money($totalPaid) ?></strong></div>
            <div class="grand-total"><span>Balance</span><strong><?= money(max((float) $order['total_amount'] - $totalPaid, 0)) ?></strong></div>
        </div>
        <h2>Verified payments</h2>
        <table class="receipt-table">
            <thead><tr><th>Type</th><th>Method</th><th>Verified</th><th>Amount</th></tr></thead>
            <tbody>
                <?php foreach ($payments as $payment): ?>
                    <tr><td><?= e(ucfirst($payment['payment_type'])) ?></td><td><?= e($payment['method'] ?: '—') ?></td><td><?= e(display_date($payment['verified_at'])) ?></td><td><?= money($payment['amount']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="receipt-note">This receipt confirms payments verified by TrustProcure. Keep it for your records.</p>
    </main>
</body>
</html>
