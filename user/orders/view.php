<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/payments.php';
require_user();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT o.*, p.name AS product_name, p.image FROM orders o
     JOIN products p ON p.id = o.product_id
     WHERE o.id = ? AND o.user_id = ?"
);
$stmt->execute([$id, $_SESSION['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    set_flash('error', 'Order not found.');
    redirect('/user/orders/index.php');
}

$itemsStmt = $pdo->prepare(
    'SELECT oi.*, p.name AS product_name, p.image FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id'
);
$itemsStmt->execute([$id]);
$orderItems = $itemsStmt->fetchAll();

$pageTitle = 'Order #' . $order['id'];
$paymentOptions = getOrderPaymentOptions($pdo, $id);

$payments = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC');
$payments->execute([$id]);
$payments = $payments->fetchAll();
$hasVerifiedPayment = (bool) array_filter($payments, static fn(array $payment): bool => $payment['status'] === 'verified');
$eventsStmt = $pdo->prepare('SELECT * FROM order_events WHERE order_id = ? ORDER BY created_at DESC');
$eventsStmt->execute([$id]);
$events = $eventsStmt->fetchAll();
$customerReceived = (bool) array_filter($events, static fn(array $event): bool => $event['event_type'] === 'customer_received');

$hasPendingDeposit = in_array($order['status'], ['request_approved', 'pending_deposit'], true) && !array_filter($payments, fn($p) => $p['payment_type'] === 'deposit' && in_array($p['status'], ['pending', 'verified'], true));
$canPayBalance = $order['status'] === 'delivered' && (float) $order['balance_amount'] > 0
    && !array_filter($payments, fn($p) => $p['payment_type'] === 'balance' && $p['status'] === 'pending');

include __DIR__ . '/../../includes/user_layout_start.php';
?>

<div class="account-shell">
    <div class="account-summary">
        <div class="account-summary-main">
            <div class="account-summary-meta">Procurement order</div>
            <div>
                <div class="section-kicker">Order record</div>
                <h2>Order #<?= (int) $order['id'] ?></h2>
                <p><?= e($order['product_name']) ?> · <?= count($orderItems) > 1 ? count($orderItems) . ' items' : '1 item' ?></p>
            </div>
        </div>
        <div class="table-actions">
            <span class="badge badge-<?= order_status_badge_class($order['status']) ?>"><?= e(order_status_label($order['status'])) ?></span>
            <?php
            $receiptUnlocked = $hasVerifiedPayment && in_array($order['status'], ['delivered', 'completed'], true);
            ?>
            <?php if ($receiptUnlocked): ?><a href="<?= url('/user/orders/receipt.php?id=' . $order['id']) ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener"><i class="bi bi-receipt me-1" aria-hidden="true"></i>Receipt</a><?php endif; ?>
        </div>
    </div>

    <div class="detail-progress-strip" aria-label="Order process">
        <span class="detail-progress-item active"><strong>01</strong> Requested</span>
        <span class="detail-progress-item"><strong>02</strong> Reviewed</span>
        <span class="detail-progress-item"><strong>03</strong> Delivered</span>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>Order summary</h2>
            <span class="badge badge-neutral"><?= display_date($order['created_at']) ?></span>
        </div>
        <div class="panel-body">
        <div class="detail-grid">
            <div class="detail-item">
                <div class="label">Quantity</div>
                <div class="value"><?= (int) $order['quantity'] ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Total amount</div>
                <div class="value"><?= money($order['total_amount']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Deposit</div>
                <div class="value"><?= money($order['deposit_amount']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Balance</div>
                <div class="value"><?= money($order['balance_amount']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Created</div>
                <div class="value"><?= display_date($order['created_at']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Last updated</div>
                <div class="value"><?= display_date($order['updated_at']) ?></div>
            </div>
        </div>

        <?php if ($order['status'] === 'request_submitted'): ?>
            <div class="alert alert-info" style="margin-top: 24px;">Your order request has been submitted and is awaiting admin approval.</div>
        <?php elseif ($order['status'] === 'request_rejected'): ?>
            <div class="alert alert-error" style="margin-top: 24px;">Your order request was rejected<?= $order['rejection_reason'] ? ': ' . e($order['rejection_reason']) : '.' ?></div>
        <?php elseif ($order['status'] === 'request_approved'): ?>
            <?php if ($order['payment_status'] === 'paid' || $paymentOptions['balance'] <= 0): ?>
                <div class="alert alert-success" style="margin-top: 24px;">Payment received in full. The procurement team will now update the order as it progresses.</div>
            <?php else: ?>
                <div class="alert alert-info" style="margin-top: 24px;">
                    Your order has been approved. Choose a payment option below.
                </div>
                <div class="table-actions" style="margin-top: 12px;">
                    <?php if ($paymentOptions['requires_deposit']): ?>
                        <a href="<?= url('/user/payments/add.php?order_id=' . $order['id'] . '&type=deposit') ?>" class="btn btn-accent">Pay deposit (<?= money($paymentOptions['deposit']) ?>)</a>
                    <?php endif; ?>
                    <a href="<?= url('/user/payments/add.php?order_id=' . $order['id'] . '&type=balance') ?>" class="btn btn-outline">Pay full amount (<?= money($paymentOptions['total']) ?>)</a>
                </div>
            <?php endif; ?>
        <?php elseif ($hasPendingDeposit): ?>
            <div class="alert alert-info" style="margin-top: 24px;">Your deposit of <?= money($paymentOptions['deposit']) ?> is due to start procurement.
                <a href="<?= url('/user/payments/add.php?order_id=' . $order['id'] . '&type=deposit') ?>">Submit deposit payment</a>
            </div>
        <?php elseif ($canPayBalance): ?>
            <div class="alert alert-info" style="margin-top: 24px;">A balance of <?= money($order['balance_amount']) ?> is due.
                <a href="<?= url('/user/payments/add.php?order_id=' . $order['id'] . '&type=balance') ?>">Submit balance payment</a>
            </div>
        <?php elseif (in_array($order['status'], ['deposit_paid', 'processing', 'procuring', 'shipped'], true) && (float) $order['balance_amount'] > 0): ?>
            <div class="alert alert-info" style="margin-top: 24px;">The remaining balance of <?= money($order['balance_amount']) ?> can be paid after the order is delivered.</div>
        <?php endif; ?>

        <?php if ($order['status'] === 'cancelled' && $order['cancellation_reason']): ?>
            <hr class="divider">
            <div class="detail-item">
                <div class="label">Cancellation reason</div>
                <div class="value"><?= e($order['cancellation_reason']) ?></div>
            </div>
        <?php endif; ?>
        <?php if ($order['status'] === 'delivered'): ?>
            <hr class="divider">
            <?php if ($customerReceived): ?>
                <div class="alert alert-success">You confirmed that this order was received. The team has been notified.</div>
            <?php else: ?>
                <form method="post" action="<?= url('/user/orders/confirm_received.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                    <button type="submit" class="btn btn-accent" data-confirm="Confirm that you received this order?"><i class="bi bi-check2-circle me-2" aria-hidden="true"></i>I received this order</button>
                </form>
            <?php endif; ?>
            <?php if ($hasVerifiedPayment): ?>
                <div class="table-actions" style="margin-top: 12px;">
                    <a href="<?= url('/user/orders/receipt.php?id=' . $order['id']) ?>" class="btn btn-outline" target="_blank" rel="noopener"><i class="bi bi-receipt me-1" aria-hidden="true"></i>View / print receipt</a>
                </div>
            <?php endif; ?>
        <?php elseif ($hasVerifiedPayment && !in_array($order['status'], ['completed'], true)): ?>
            <hr class="divider">
            <div class="alert alert-info" style="margin-top: 0;">Your receipt will be available once your order is delivered.</div>
        <?php endif; ?>
        <?php if ($order['customer_notes']): ?>
            <hr class="divider">
            <div class="detail-item">
                <div class="label">Request notes</div>
                <div class="value"><?= nl2br(e($order['customer_notes'])) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($order['status'] === 'pending_deposit'): ?>
            <hr class="divider">
            <form method="post" action="<?= url('/user/orders/cancel.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $order['id'] ?>">
                <button type="submit" class="btn btn-danger" data-confirm="Cancel this order? This cannot be undone.">Cancel this order</button>
            </form>
        <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($orderItems): ?>
    <div class="panel">
        <div class="panel-head">
            <h3><i class="bi bi-bag me-2" aria-hidden="true"></i>Items in this request</h3>
        </div>
        <div class="panel-body flush">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderItems as $item): ?>
                            <tr>
                                <td><?= e($item['product_name']) ?></td>
                                <td><?= (int) $item['quantity'] ?></td>
                                <td><?= money($item['unit_price']) ?></td>
                                <td><?= money($item['total_amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head">
        <h3><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Order timeline</h3>
    </div>
    <div class="panel-body">
        <?php if ($events): ?>
            <div class="order-timeline">
                <?php foreach ($events as $event): ?>
                    <div class="order-timeline-item">
                        <span class="order-timeline-dot" aria-hidden="true"></span>
                        <div><strong><?= e($event['title']) ?></strong><time><?= display_date($event['created_at']) ?></time><?php if ($event['details']): ?><p><?= e($event['details']) ?></p><?php endif; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted mb-0">Order activity will appear here as your request progresses.</p>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h3>Payments on this order</h3>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= ucfirst(e($p['payment_type'])) ?></td>
                        <td><?= money($p['amount']) ?></td>
                        <td><?= e($p['method'] ?: '—') ?></td>
                        <td><span class="badge badge-<?= $p['status'] === 'verified' ? 'success' : ($p['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= e(payment_status_label($p['status'])) ?></span></td>
                        <td><?= display_date($p['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$payments): ?>
                    <tr>
                        <td colspan="5" class="empty-state">No payments submitted yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/user_layout_end.php'; ?>