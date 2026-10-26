<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Orders & Requests';
$tab = ($_GET['tab'] ?? 'requests') === 'orders' ? 'orders' : 'requests';
$page = current_page();
$perPage = 15;
$requests = [];
$orders = [];

if ($tab === 'requests') {
    $total = (int) $pdo->query("SELECT COUNT(*) FROM requests")->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));

    $stmt = $pdo->prepare(
        "SELECT r.*, o.id AS order_id, o.status AS order_status, u.full_name, u.phone, p.name AS product_name, p.price, p.is_high_end, p.deposit_percentage
         FROM requests r
         LEFT JOIN orders o ON o.request_id = r.id
         JOIN users u ON u.id = r.user_id
         JOIN products p ON p.id = r.product_id
         ORDER BY (r.status = 'pending') DESC, r.created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $requests = $stmt->fetchAll();
} else {
    $total = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));

    $stmt = $pdo->prepare(
        "SELECT o.*, u.full_name, p.name AS product_name
         FROM orders o
         JOIN users u ON u.id = o.user_id
         JOIN products p ON p.id = o.product_id
         ORDER BY o.created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll();
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="account-shell">
    <div class="account-summary">
        <div class="account-summary-main">
            <div class="account-summary-meta">Order pipeline</div>
            <div>
                <div class="section-kicker">Procurement operations</div>
                <h2>Orders &amp; requests</h2>
                <p>Review demand, approve requests, track payment status, and keep fulfillment moving.</p>
            </div>
        </div>
        <span class="badge badge-warning"><?= $tab === 'requests' ? 'Requests' : 'Orders' ?></span>
    </div>

    <div class="request-stat-grid">
        <div class="request-stat-card">
            <div class="label">Requests</div>
            <div class="value"><?= (int) $pdo->query("SELECT COUNT(*) FROM requests")->fetchColumn() ?></div>
        </div>
        <div class="request-stat-card is-alert">
            <div class="label">Pending</div>
            <div class="value"><?= (int) $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'pending'")->fetchColumn() ?></div>
        </div>
        <div class="request-stat-card is-success">
            <div class="label">Approved</div>
            <div class="value"><?= (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status NOT IN ('cancelled', 'completed')")->fetchColumn() ?></div>
        </div>
        <div class="request-stat-card is-danger">
            <div class="label">Active</div>
            <div class="value"><?= (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status NOT IN ('completed', 'cancelled')")->fetchColumn() ?></div>
        </div>
    </div>

    <div class="detail-progress-strip" aria-label="Order operations workflow">
        <span class="detail-progress-item active"><strong>01</strong> Request</span>
        <span class="detail-progress-item"><strong>02</strong> Approve</span>
        <span class="detail-progress-item"><strong>03</strong> Pay</span>
        <span class="detail-progress-item"><strong>04</strong> Fulfill</span>
    </div>

<div class="panel">
    <div class="panel-head" style="gap: 8px;">
        <div class="table-actions">
            <a href="<?= url('/admin/orders/index.php?tab=requests') ?>" class="btn btn-<?= $tab === 'requests' ? 'primary' : 'outline' ?> btn-sm">Procurement requests</a>
            <a href="<?= url('/admin/orders/index.php?tab=orders') ?>" class="btn btn-<?= $tab === 'orders' ? 'primary' : 'outline' ?> btn-sm">Orders</a>
        </div>
    </div>

    <?php if ($tab === 'requests'): ?>
        <div class="panel-body flush">
            <table>
                <thead>
                    <tr>
                        <th>Customer</th>
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
                        <tr class="<?= $r['order_status'] === 'request_submitted' || (!$r['order_status'] && $r['status'] === 'pending') ? 'admin-queue-row' : '' ?>">
                            <td><?= e($r['full_name']) ?></td>
                            <td><?= e($r['product_name']) ?></td>
                            <td><?= (int) $r['quantity'] ?></td>
                            <td><?= money($r['price'] * $r['quantity']) ?></td>
                            <?php $requestStatus = $r['order_status'] ?: ($r['status'] === 'pending' ? 'request_submitted' : $r['status']); ?>
                            <td class="request-status"><span class="badge badge-<?= in_array($requestStatus, ['request_rejected', 'cancelled', 'rejected'], true) ? 'danger' : ($requestStatus === 'request_submitted' ? 'warning' : 'success') ?>"><?= e(order_status_label($requestStatus)) ?></span></td>
                            <td><?= display_date($r['created_at']) ?></td>
                            <td>
                                <?php if ($requestStatus === 'request_submitted' && $r['order_id']): ?>
                                    <div class="table-actions">
                                        <span class="admin-queue-badge">Needs action</span>
                                        <button type="button" class="btn btn-accent btn-sm js-review-request" data-order-id="<?= (int) $r['order_id'] ?>" data-action="approve">Approve</button>
                                        <button type="button" class="btn btn-danger btn-sm js-review-request" data-order-id="<?= (int) $r['order_id'] ?>" data-action="reject">Reject</button>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$requests): ?>
                        <tr>
                            <td colspan="7" class="empty-state">No procurement requests yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php render_pagination($page, $totalPages, '/admin/orders/index.php', ['tab' => 'requests']); ?>
        </div>
    <?php else: ?>
        <div class="panel-body flush">
            <table>
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                        <?php $needsAction = in_array($o['status'], ['request_submitted', 'pending_deposit', 'delivered'], true) && ($o['status'] !== 'delivered' || (float) $o['balance_amount'] > 0); ?>
                        <tr class="<?= $needsAction ? 'admin-queue-row' : '' ?>">
                            <td>#<?= (int) $o['id'] ?></td>
                            <td><?= e($o['full_name']) ?></td>
                            <td><?= e($o['product_name']) ?></td>
                            <td><?= money($o['total_amount']) ?></td>
                            <td class="order-status"><span class="badge badge-<?= order_status_badge_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
                            <td><?= display_date($o['created_at']) ?></td>
                            <td>
                                <div class="table-actions">
                                    <?php if ($needsAction): ?><span class="admin-queue-badge">Needs action</span><?php endif; ?>
                                    <a href="<?= url('/admin/orders/view.php?id=' . $o['id']) ?>" class="btn btn-outline btn-sm">Manage</a>
                                    <?php if ($o['status'] === 'request_submitted'): ?>
                                        <button type="button" class="btn btn-accent btn-sm js-review-request" data-order-id="<?= (int) $o['id'] ?>" data-action="approve">Approve</button>
                                        <button type="button" class="btn btn-danger btn-sm js-review-request" data-order-id="<?= (int) $o['id'] ?>" data-action="reject">Reject</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$orders): ?>
                        <tr>
                            <td colspan="7" class="empty-state">No orders yet. Approve a request to create the first one.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php render_pagination($page, $totalPages, '/admin/orders/index.php', ['tab' => 'orders']); ?>
        </div>
    <?php endif; ?>
</div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>

<script>
    document.querySelectorAll('.js-review-request').forEach(function(button) {
        button.addEventListener('click', async function() {
            var reason = '';
            if (button.dataset.action === 'reject') {
                reason = window.prompt('Optional rejection reason:', '')
                if (reason === null) return;
            } else if (!window.confirm('Approve this order request?')) {
                return;
            }

            button.disabled = true;
            var body = new URLSearchParams({
                order_id: button.dataset.orderId,
                action: button.dataset.action,
                reason: reason
            });
            try {
                var response = await fetch('<?= url('/ajax/order-request.php') ?>', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': '<?= e(csrf_token()) ?>'
                    },
                    body: body
                });
                var result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'The request could not be updated.');
                var row = button.closest('tr');
                var statusCell = row.querySelector('.request-status, .order-status');
                if (statusCell) statusCell.innerHTML = '<span class="badge badge-' + (result.status === 'request_rejected' ? 'danger' : 'info') + '">' + (result.status === 'request_rejected' ? 'Rejected' : 'Approved') + '</span>';
                row.querySelector('.table-actions').innerHTML = '<span class="muted">Reviewed</span>';
            } catch (error) {
                window.alert(error.message);
                button.disabled = false;
            }
        });
    });
</script>