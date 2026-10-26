<?php
/**
 * Printable receipt for a till sale.
 *
 * Checkout sends the operator here straight after a sale (with ?print=1 so the
 * print dialog opens by itself), and the same URL reprints any past till sale
 * from the till's Sales list later on.
 *
 * A till sale is an ordinary order with channel = "pos", so this page reads the
 * normal orders/order_items/payments rows. Every figure is recomputed from those
 * rows rather than from the session cart, which checkout has already cleared.
 */
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$orderId = (int) ($_GET['order'] ?? $_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT o.*,
            u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
            s.full_name AS cashier_name
     FROM orders o
     JOIN users u ON u.id = o.user_id
     LEFT JOIN users s ON s.id = o.sold_by
     WHERE o.id = ?"
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    set_flash('error', 'That sale could not be found.');
    redirect('/admin/pos/index.php');
}

// Online orders have their own receipt in the user portal; this page is the
// till's, so send anyone who lands here with the wrong kind of order onward.
if ($order['channel'] !== 'pos') {
    set_flash('error', 'That order was not rung up at the till.');
    redirect('/admin/orders/view.php?id=' . $orderId);
}

$skuColumn = pos_schema_ready($pdo) ? 'p.sku' : 'NULL AS sku';
$itemsStmt = $pdo->prepare(
    "SELECT oi.quantity, oi.unit_price, oi.total_amount,
            p.name AS product_name, p.brand, {$skuColumn}
     FROM order_items oi
     JOIN products p ON p.id = oi.product_id
     WHERE oi.order_id = ?
     ORDER BY oi.id"
);
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();

$paymentsStmt = $pdo->prepare(
    "SELECT payment_type, method, amount, reference_note, verified_at
     FROM payments
     WHERE order_id = ? AND status = 'verified'
     ORDER BY id"
);
$paymentsStmt->execute([$orderId]);
$payments = $paymentsStmt->fetchAll();

// The till stores the pre-discount sum in total_amount, so the payable figure
// is that minus the discount the operator applied.
$subtotal = (float) $order['total_amount'];
$discount = (float) $order['discount_amount'];
$total = max(0.0, $subtotal - $discount);
$paid = pos_order_paid_amount($pdo, $orderId);
$balance = (float) $order['balance_amount'];

$isWalkIn = pos_is_walk_in_sale($order);
$customerName = pos_sale_customer_name($order);
// What the till captured at the time of the sale. These fall back to the
// account's own details for sales recorded before the till captured them.
$contactPhone = pos_sale_contact_phone($order);
$contactEmail = pos_sale_contact_email($order);

/**
 * Cash takings are written into the payment note as "tendered UGX x", a middle
 * dot, then "change UGX y". Recover the pair for the receipt, and stay quiet if
 * an older note predates that wording rather than printing a half-parsed amount.
 * The separator is matched by code point so the pattern stays pure ASCII and
 * cannot be broken by this file being re-encoded.
 */
function pos_receipt_cash_breakdown(?string $note): ?array
{
    if ($note === null || !preg_match('~tendered\s+(.+?)\s+\x{00B7}\s+change\s+(.+)$~u', $note, $matches)) {
        return null;
    }

    return ['tendered' => trim($matches[1]), 'change' => trim($matches[2])];
}

$payment = $payments[0] ?? null;
$method = $payment ? pos_payment_method_label((string) $payment['method']) : 'Not paid';
$cash = $payment ? pos_receipt_cash_breakdown($payment['reference_note']) : null;
$autoPrint = ($_GET['print'] ?? '') === '1';

$pageTitle = 'Receipt ' . ($order['order_number'] ?: ('#' . $orderId));

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
        .receipt-shell { max-width: 760px; margin: 32px auto; padding: 36px 40px; background: var(--surface); border: 1px solid var(--border); font-family: Georgia, 'Times New Roman', serif; }
        .receipt-shell h1, .receipt-shell h2, .receipt-shell .brand, .receipt-shell .btn, .receipt-shell button { font-family: Georgia, 'Times New Roman', serif; }
        .receipt-shell i { font-family: "bootstrap-icons" !important; }
        .receipt-toolbar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 24px; }
        .receipt-header { display: flex; justify-content: space-between; gap: 32px; padding-bottom: 20px; border-bottom: 3px solid var(--accent); }
        .receipt-header h1 { margin: 0 0 6px; font-size: 1.8rem; }
        .receipt-meta { text-align: right; color: var(--ink-soft); font-size: .9rem; }
        .receipt-parties { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; padding: 22px 0; }
        .receipt-label { color: var(--ink-faint); font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .receipt-value { margin-top: 4px; color: var(--ink); }
        .receipt-table { width: 100%; margin: 8px 0 22px; border-collapse: collapse; }
        .receipt-table th, .receipt-table td { padding: 10px 8px; border-bottom: 1px solid var(--border); text-align: left; }
        .receipt-table th { color: var(--ink-faint); font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; }
        .receipt-table td.num, .receipt-table th.num { text-align: right; white-space: nowrap; }
        .receipt-item-name { font-weight: 700; }
        .receipt-item-meta { color: var(--ink-soft); font-size: .78rem; }
        .receipt-totals { margin-left: auto; width: min(100%, 320px); }
        .receipt-totals div { display: flex; justify-content: space-between; gap: 16px; padding: 6px 0; }
        .receipt-totals .grand-total { border-top: 2px solid var(--ink); margin-top: 6px; padding-top: 10px; font-size: 1.1rem; font-weight: 700; }
        .receipt-paid { margin-top: 22px; padding: 14px 16px; border: 1px solid var(--border); background: var(--bg-alt, #f9fafb); }
        .receipt-note { margin-top: 22px; color: var(--ink-soft); font-size: .85rem; }
        .receipt-foot { margin-top: 26px; padding-top: 16px; border-top: 1px dashed var(--border); text-align: center; color: var(--ink-soft); font-size: .85rem; }
        @media (max-width: 640px) {
            .receipt-shell { margin: 0; padding: 22px 18px; border: 0; }
            .receipt-header { flex-direction: column; }
            .receipt-meta { text-align: left; }
            .receipt-parties { grid-template-columns: 1fr; gap: 12px; }
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
            <a href="<?= url('/admin/pos/index.php') ?>" class="btn btn-outline">Back to till</a>
            <a href="<?= url('/admin/orders/view.php?id=' . $orderId) ?>" class="btn btn-outline">View order</a>
            <button type="button" class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer me-2" aria-hidden="true"></i>Print / Save PDF</button>
        </div>

        <header class="receipt-header">
            <div>
                <div class="brand">Trust<span>Procure</span></div>
                <h1>Point of Sale receipt</h1>
                <p>Collected in store<?= $order['cashier_name'] ? ' — served by ' . e($order['cashier_name']) : '' ?>.</p>
            </div>
            <div class="receipt-meta">
                <strong><?= e($order['order_number'] ?: ('#' . $orderId)) ?></strong><br>
                <?= $order['invoice_number'] ? 'Invoice ' . e($order['invoice_number']) . '<br>' : '' ?>
                <?= e(display_date($order['created_at'])) ?><br>
                <?= e(order_status_label($order['status'])) ?>
            </div>
        </header>

        <section class="receipt-parties">
            <div>
                <div class="receipt-label">Customer</div>
                <div class="receipt-value"><?= e($customerName) ?></div>
                <?php if ($contactPhone !== ''): ?>
                    <div class="muted"><?= e($contactPhone) ?></div>
                <?php endif; ?>
                <?php if ($contactEmail !== ''): ?>
                    <div class="muted"><?= e($contactEmail) ?></div>
                <?php endif; ?>
                <?php if ($isWalkIn): ?>
                    <div class="muted">Walk-in — no customer account</div>
                <?php endif; ?>
            </div>
            <div>
                <div class="receipt-label">Payment</div>
                <div class="receipt-value"><?= e($method) ?></div>
                <div class="muted"><?= e(payment_status_label($order['payment_status'])) ?></div>
                <?php if ($order['credit_due_date']): ?>
                    <div class="muted">Balance due <?= e(display_date($order['credit_due_date'], 'd M Y')) ?></div>
                <?php endif; ?>
            </div>
        </section>

        <table class="receipt-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="num">Qty</th>
                    <th class="num">Unit price</th>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <div class="receipt-item-name"><?= e($item['product_name']) ?></div>
                            <?php if ($item['sku'] || $item['brand']): ?>
                                <div class="receipt-item-meta"><?= e(trim(($item['brand'] ?? '') . ($item['sku'] ? ' · ' . $item['sku'] : ''), ' ·')) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= (int) $item['quantity'] ?></td>
                        <td class="num"><?= money($item['unit_price']) ?></td>
                        <td class="num"><?= money($item['total_amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="receipt-totals">
            <div><span>Subtotal</span><strong><?= money($subtotal) ?></strong></div>
            <?php if ($discount > 0.005): ?>
                <div><span>Discount</span><strong>&minus; <?= money($discount) ?></strong></div>
            <?php endif; ?>
            <div class="grand-total"><span>Total</span><strong><?= money($total) ?></strong></div>
            <div><span>Paid</span><strong><?= money($paid) ?></strong></div>
            <?php if ($balance > 0.005): ?>
                <div><span>Balance due</span><strong><?= money($balance) ?></strong></div>
            <?php endif; ?>
        </div>

        <div class="receipt-paid">
            <div class="receipt-label">Paid by <?= e($method) ?></div>
            <div class="receipt-value"><?= money($paid) ?><?= $payment ? ' · taken ' . e(display_date($payment['verified_at'])) : '' ?></div>
            <?php if ($cash): ?>
                <div class="receipt-item-meta">Cash tendered <?= e($cash['tendered']) ?> &middot; change <?= e($cash['change']) ?></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($order['customer_notes'])): ?>
            <p class="receipt-note"><strong>Note:</strong> <?= e($order['customer_notes']) ?></p>
        <?php endif; ?>

        <p class="receipt-foot">
            Goods sold over the counter are checked before leaving the store.<br>
            Thank you for shopping with TrustProcure.
        </p>
    </main>

    <?php if ($autoPrint): ?>
        <script>window.addEventListener('load', function () { window.print(); });</script>
    <?php endif; ?>
</body>
</html>
