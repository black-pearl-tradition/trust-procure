<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

$pageTitle = 'My Requests';
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT r.*, o.id AS order_id, o.status AS order_status, p.name AS product_name, p.price FROM requests r
     LEFT JOIN orders o ON o.request_id = r.id
     JOIN products p ON p.id = r.product_id
     WHERE r.user_id = ? ORDER BY r.created_at DESC"
);
$stmt->execute([$userId]);
$requests = $stmt->fetchAll();

$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;
foreach ($requests as $request) {
    $status = $request['order_status'] ?: ($request['status'] === 'pending' ? 'request_submitted' : $request['status']);
    if (in_array($status, ['request_submitted', 'pending_deposit', 'request_approved'], true)) {
        $pendingCount++;
    }
    if (in_array($status, ['request_approved', 'deposit_paid', 'processing', 'procuring', 'delivered'], true)) {
        $approvedCount++;
    }
    if (in_array($status, ['request_rejected', 'cancelled'], true)) {
        $rejectedCount++;
    }
}

include __DIR__ . '/../../includes/user_layout_start.php';
?>

<div class="account-shell">
    <div class="account-summary">
        <div class="account-summary-main">
            <div class="account-summary-meta">Request tracker</div>
            <div>
                <div class="section-kicker">Procurement activity</div>
                <h2>My requests</h2>
                <p>Track products you have requested and follow their approval status.</p>
            </div>
        </div>
        <a href="<?= url('/products.php') ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-2" aria-hidden="true"></i>Request a product</a>
    </div>

    <div class="request-stat-grid">
        <div class="request-stat-card">
            <div class="label">Total requests</div>
            <div class="value"><?= count($requests) ?></div>
        </div>
        <div class="request-stat-card is-alert">
            <div class="label">In review</div>
            <div class="value"><?= $pendingCount ?></div>
        </div>
        <div class="request-stat-card is-success">
            <div class="label">Approved</div>
            <div class="value"><?= $approvedCount ?></div>
        </div>
        <div class="request-stat-card is-danger">
            <div class="label">Closed</div>
            <div class="value"><?= $rejectedCount ?></div>
        </div>
    </div>

    <div class="detail-progress-strip" aria-label="Request process">
        <span class="detail-progress-item active"><strong>01</strong> Request</span>
        <span class="detail-progress-item"><strong>02</strong> Review</span>
        <span class="detail-progress-item"><strong>03</strong> Approval</span>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>Request history</h2>
            <span class="badge badge-neutral"><?= count($requests) ?> total</span>
        </div>
        <div class="panel-body flush">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Est. total</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td><?= e($r['product_name']) ?></td>
                            <td><?= (int) $r['quantity'] ?></td>
                            <td><?= money($r['price'] * $r['quantity']) ?></td>
                            <?php $requestStatus = $r['order_status'] ?: ($r['status'] === 'pending' ? 'request_submitted' : $r['status']); ?>
                            <td><span class="badge badge-<?= in_array($requestStatus, ['request_rejected', 'cancelled', 'rejected'], true) ? 'danger' : ($requestStatus === 'request_submitted' ? 'warning' : 'success') ?>"><?= e(order_status_label($requestStatus)) ?></span></td>
                            <td><?= display_date($r['created_at']) ?></td>
                            <td>
                                <div class="table-actions">
                                    <a href="<?= url($r['order_id'] ? '/user/orders/view.php?id=' . $r['order_id'] : '/user/requests/view.php?id=' . $r['id']) ?>" class="btn btn-outline btn-sm">View</a>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <form method="post" action="<?= url('/user/requests/cancel.php') ?>" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Cancel this request?">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$requests): ?>
                        <tr>
                            <td colspan="6" class="empty-state">You haven't submitted any requests yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/user_layout_end.php'; ?>