<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/payments.php';
require_staff();

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT pay.*, u.full_name, u.email, u.phone, o.id AS order_id, o.status AS order_status,
            o.total_amount, o.deposit_amount, o.balance_amount, o.payment_status, o.delivery_address,
            p.name AS product_name
     FROM payments pay
     JOIN users u ON u.id = pay.user_id
     JOIN orders o ON o.id = pay.order_id
     JOIN products p ON p.id = o.product_id
     WHERE pay.id = ?"
);
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    set_flash('error', 'Payment not found.');
    redirect('/admin/payments/index.php');
}

$pageTitle = 'Payment #' . $payment['id'];
$paymentOptions = getOrderPaymentOptions($pdo, (int) $payment['order_id']);
$expectedPaymentAmount = $payment['payment_type'] === 'deposit'
    ? $paymentOptions['deposit']
    : ($payment['order_status'] === 'request_approved' ? $paymentOptions['total'] : (float) $payment['amount']);
$allDuesCleared = $payment['status'] === 'verified' && $paymentOptions['balance'] <= 0;
$eventsStmt = $pdo->prepare('SELECT event_type, title, details, created_at FROM order_events WHERE order_id = ? ORDER BY created_at DESC LIMIT 8');
$eventsStmt->execute([(int) $payment['order_id']]);
$orderEvents = $eventsStmt->fetchAll();
include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="account-shell">
    <div class="account-summary">
        <div class="account-summary-main">
            <div class="account-summary-meta">Payment review</div>
            <div>
                <div class="section-kicker">Finance approval</div>
                <h2>Payment #<?= (int) $payment['id'] ?></h2>
                <p><?= e($payment['full_name']) ?> · <?= e($payment['product_name']) ?></p>
            </div>
        </div>
        <span class="badge badge-<?= $payment['status'] === 'verified' ? 'success' : ($payment['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= e(payment_status_label($payment['status'])) ?></span>
    </div>

    <div class="detail-progress-strip" aria-label="Finance workflow">
        <span class="detail-progress-item active"><strong>01</strong> Review</span>
        <span class="detail-progress-item"><strong>02</strong> Verify</span>
        <span class="detail-progress-item"><strong>03</strong> Fulfill</span>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>Transaction details</h2>
            <span class="badge badge-neutral"><?= display_date($payment['created_at']) ?></span>
        </div>
        <div class="panel-body">
            <?php if ($allDuesCleared): ?>
                <div class="payment-clearance-banner">
                    <div class="payment-clearance-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></div>
                    <div>
                        <strong>All dues cleared</strong>
                        <p>This payment was verified and order #<?= (int) $payment['order_id'] ?> is fully paid.</p>
                    </div>
                </div>
            <?php endif; ?>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="label">Customer</div>
                    <div class="value"><?= e($payment['full_name']) ?> (<?= e($payment['email']) ?>)</div>
                </div>
                <div class="detail-item">
                    <div class="label">Phone</div>
                    <div class="value"><?= e($payment['phone'] ?: '—') ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Order</div>
                    <div class="value"><a href="<?= url('/admin/orders/view.php?id=' . $payment['order_id']) ?>">#<?= (int) $payment['order_id'] ?> — <?= e($payment['product_name']) ?></a></div>
                </div>
                <div class="detail-item">
                    <div class="label">Payment type</div>
                    <div class="value"><?= ucfirst(e($payment['payment_type'])) ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Amount</div>
                    <div class="value"><?= money($payment['amount']) ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Expected amount</div>
                    <div class="value"><?= money($expectedPaymentAmount) ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Order total</div>
                    <div class="value"><?= money($payment['total_amount']) ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Current balance</div>
                    <div class="value"><?= money($paymentOptions['balance']) ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Payment status</div>
                    <div class="value"><?= e(payment_status_label($payment['payment_status'])) ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Method</div>
                    <div class="value"><?= e($payment['method'] ?: '—') ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Reference</div>
                    <div class="value"><?= e($payment['reference_note'] ?: '—') ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Delivery address</div>
                    <div class="value"><?= e($payment['delivery_address'] ?: '—') ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Submitted</div>
                    <div class="value"><?= display_date($payment['created_at']) ?></div>
                </div>
                <?php if ($payment['verified_at']): ?>
                    <div class="detail-item">
                        <div class="label">Reviewed</div>
                        <div class="value"><?= display_date($payment['verified_at']) ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($payment['proof_image']): ?>
                <hr class="divider">
                <div class="detail-item">
                    <div class="label">Proof of payment</div>
                    <div class="pd-image" style="max-width: 320px; margin-top: 8px;">
                        <img src="<?= url('/payment-proof.php?id=' . (int) $payment['id']) ?>" alt="Proof of payment" loading="lazy" onerror="this.closest('.pd-image').outerHTML='<div class=&quot;payment-proof-missing&quot;><i class=&quot;bi bi-exclamation-circle me-2&quot; aria-hidden=&quot;true&quot;></i>Proof image file is missing on the server. Ask the customer to re-upload.</div>'">
                    </div>
                    <div style="margin-top: 8px;"><a class="btn btn-outline btn-sm" href="<?= url('/payment-proof.php?id=' . (int) $payment['id']) ?>" target="_blank" rel="noopener">Open full image</a></div>
                </div>
            <?php else: ?>
                <div class="payment-proof-missing"><i class="bi bi-exclamation-circle me-2" aria-hidden="true"></i>No proof of payment was attached.</div>
            <?php endif; ?>

            <?php if ($orderEvents): ?>
                <hr class="divider">
                <div class="payment-timeline">
                    <div class="label">Order timeline</div>
                    <?php foreach ($orderEvents as $event): ?>
                        <div class="payment-timeline-item">
                            <span class="payment-timeline-dot" aria-hidden="true"></span>
                            <div><strong><?= e($event['title']) ?></strong><time><?= display_date($event['created_at']) ?></time><?php if ($event['details']): ?><p><?= e($event['details']) ?></p><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($payment['status'] === 'pending'): ?>
                <hr class="divider">
                <div class="table-actions">
                    <button type="button" class="btn btn-accent js-review-payment" data-action="verify">Verify payment</button>
                    <button type="button" class="btn btn-danger js-review-payment" data-action="reject">Reject &amp; request clearer proof</button>
                </div>
                <div class="hint" style="margin-top: 8px;">Rejecting notifies the customer with your reason and prompts them to re-upload a clearer proof on the same payment record.</div>
            <?php endif; ?>
            <?php if ($payment['status'] === 'rejected' && $payment['rejection_reason']): ?>
                <div class="payment-proof-missing"><strong>Rejection reason:</strong> <?= e($payment['rejection_reason']) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>

<?php if ($payment['status'] === 'pending'): ?>
    <script>
        document.querySelectorAll('.js-review-payment').forEach(function(button) {
            button.addEventListener('click', async function() {
                var reason = '';
                if (button.dataset.action === 'reject') {
                    reason = window.prompt('Describe the problem with the proof (e.g. blurry, wrong amount, missing reference). The customer will see this and be asked to re-upload:');
                    if (reason === null || reason.trim() === '') {
                        window.alert('A reason is required so the customer knows what to fix.');
                        return;
                    }
                } else if (!window.confirm('Mark this payment as verified?')) {
                    return;
                }
                button.disabled = true;
                var body = new URLSearchParams({
                    payment_id: '<?= (int) $payment['id'] ?>',
                    action: button.dataset.action,
                    reason: reason
                });
                try {
                    var response = await fetch('<?= url('/ajax/verify-payment.php') ?>', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-Token': '<?= e(csrf_token()) ?>'
                        },
                        body: body
                    });
                    var result = await response.json();
                    if (!response.ok || !result.success) throw new Error(result.message || 'Payment review failed.');
                    window.location.reload();
                } catch (error) {
                    window.alert(error.message);
                    button.disabled = false;
                }
            });
        });
    </script>
<?php endif; ?>