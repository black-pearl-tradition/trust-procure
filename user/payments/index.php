<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

$pageTitle = 'My Payments';
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT pay.*, o.id AS order_id, p.name AS product_name FROM payments pay
     JOIN orders o ON o.id = pay.order_id
     JOIN products p ON p.id = o.product_id
     WHERE pay.user_id = ? ORDER BY pay.created_at DESC"
);
$stmt->execute([$userId]);
$payments = $stmt->fetchAll();

$totalSubmitted = 0;
$pendingReview = 0;
$verified = 0;
$rejected = 0;
foreach ($payments as $payment) {
    $totalSubmitted += (float) $payment['amount'];
    if ($payment['status'] === 'pending') $pendingReview++;
    if ($payment['status'] === 'verified') $verified++;
    if ($payment['status'] === 'rejected') $rejected++;
}

include __DIR__ . '/../../includes/user_layout_start.php';
?>

<div class="account-shell">
    <div class="account-summary">
        <div class="account-summary-main">
            <div class="account-summary-meta">Payment ledger</div>
            <div>
                <div class="section-kicker">Secure transactions</div>
                <h2>My payments</h2>
                <p>Track submitted payments, review status, and proof verification.</p>
            </div>
        </div>
        <a href="<?= url('/user/orders/index.php') ?>" class="btn btn-outline"><i class="bi bi-arrow-left me-2" aria-hidden="true"></i>Back to orders</a>
    </div>

    <div class="request-stat-grid">
        <div class="request-stat-card">
            <div class="label">Total submitted</div>
            <div class="value"><?= money($totalSubmitted) ?></div>
        </div>
        <div class="request-stat-card is-alert">
            <div class="label">Pending review</div>
            <div class="value"><?= $pendingReview ?></div>
        </div>
        <div class="request-stat-card is-success">
            <div class="label">Verified</div>
            <div class="value"><?= $verified ?></div>
        </div>
        <div class="request-stat-card is-danger">
            <div class="label">Rejected</div>
            <div class="value"><?= $rejected ?></div>
        </div>
    </div>

    <div class="detail-progress-strip" aria-label="Payment workflow">
        <span class="detail-progress-item active"><strong>01</strong> Submit</span>
        <span class="detail-progress-item"><strong>02</strong> Verify</span>
        <span class="detail-progress-item"><strong>03</strong> Fulfillment</span>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>Payment history</h2>
            <span class="badge badge-neutral"><?= count($payments) ?> entries</span>
        </div>
        <div class="panel-body flush">
            <table>
                <thead><tr><th>Order</th><th>Type</th><th>Amount</th><th>Method</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                    <tr>
                        <td>#<?= (int) $p['order_id'] ?> — <?= e($p['product_name']) ?></td>
                        <td><?= ucfirst(e($p['payment_type'])) ?></td>
                        <td><?= money($p['amount']) ?></td>
                        <td><?= e($p['method'] ?: '—') ?></td>
                        <td><span class="badge badge-<?= $p['status'] === 'verified' ? 'success' : ($p['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= e(payment_status_label($p['status'])) ?></span></td>
                        <td><?= display_date($p['created_at']) ?></td>
                        <td><a href="<?= url('/user/payments/view.php?id=' . $p['id']) ?>" class="btn btn-outline btn-sm">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$payments): ?>
                        <tr><td colspan="7" class="empty-state">No payments submitted yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/user_layout_end.php'; ?>
