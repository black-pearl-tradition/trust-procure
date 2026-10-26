<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT pay.*, o.id AS order_id, p.name AS product_name FROM payments pay
     JOIN orders o ON o.id = pay.order_id
     JOIN products p ON p.id = o.product_id
     WHERE pay.id = ? AND pay.user_id = ?"
);
$stmt->execute([$id, $_SESSION['user_id']]);
$payment = $stmt->fetch();

if (!$payment) {
    set_flash('error', 'Payment not found.');
    redirect('/user/payments/index.php');
}

$pageTitle = 'Payment #' . $payment['id'];
include __DIR__ . '/../../includes/user_layout_start.php';
?>

<div class="account-shell">
    <div class="account-summary">
        <div class="account-summary-main">
            <div class="account-summary-meta">Payment record</div>
            <div>
                <div class="section-kicker">Transaction review</div>
                <h2>Payment #<?= (int) $payment['id'] ?></h2>
                <p><?= e($payment['product_name']) ?> · <?= ucfirst(e($payment['payment_type'])) ?></p>
            </div>
        </div>
        <span class="badge badge-<?= $payment['status'] === 'verified' ? 'success' : ($payment['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= e(payment_status_label($payment['status'])) ?></span>
    </div>

    <div class="detail-progress-strip" aria-label="Payment status path">
        <span class="detail-progress-item active"><strong>01</strong> Submitted</span>
        <span class="detail-progress-item"><strong>02</strong> Verified</span>
        <span class="detail-progress-item"><strong>03</strong> Fulfillment</span>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>Transaction summary</h2>
            <span class="badge badge-neutral"><?= display_date($payment['created_at']) ?></span>
        </div>
        <div class="panel-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="label">Order</div>
                    <div class="value"><a href="<?= url('/user/orders/view.php?id=' . $payment['order_id']) ?>">#<?= (int) $payment['order_id'] ?> — <?= e($payment['product_name']) ?></a></div>
                </div>
                <div class="detail-item">
                    <div class="label">Type</div>
                    <div class="value"><?= ucfirst(e($payment['payment_type'])) ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Amount</div>
                    <div class="value"><?= money($payment['amount']) ?></div>
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
                    <div class="label">Submitted</div>
                    <div class="value"><?= display_date($payment['created_at']) ?></div>
                </div>
            </div>
            <?php if ($payment['proof_image']): ?>
                <hr class="divider">
                <div class="detail-item">
                    <div class="label">Proof of payment</div>
                    <div class="pd-image" style="max-width: 320px; margin-top: 8px;">
                        <img src="<?= url('/payment-proof.php?id=' . (int) $payment['id']) ?>" alt="Proof of payment" loading="lazy" onerror="this.closest('.pd-image').outerHTML='<div class=&quot;payment-proof-missing&quot;>Proof image file is missing on the server. Please submit your payment again.</div>'">
                    </div>
                    <div style="margin-top: 8px;"><a class="btn btn-outline btn-sm" href="<?= url('/payment-proof.php?id=' . (int) $payment['id']) ?>" target="_blank" rel="noopener">Open full image</a></div>
                </div>
            <?php endif; ?>
            <?php if ($payment['status'] === 'rejected' && $payment['rejection_reason']): ?>
                <div class="alert alert-error" style="margin-top: 20px;"><strong>Reason for rejection:</strong> <?= e($payment['rejection_reason']) ?></div>
            <?php endif; ?>
            <?php if ($payment['status'] === 'rejected'): ?>
                <div class="table-actions" style="margin-top: 16px;">
                    <a href="<?= url('/user/payments/reupload.php?id=' . (int) $payment['id']) ?>" class="btn btn-accent"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Re-upload clearer proof</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/user_layout_end.php'; ?>