<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_staff();

$pageTitle = 'Dashboard';

$stats = [
    'products'  => $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
    'users'     => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn(),
    'pending'   => $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'pending'")->fetchColumn(),
    'orders'    => $pdo->query("SELECT COUNT(*) FROM orders WHERE status NOT IN ('completed','cancelled')")->fetchColumn(),
];

$revenue = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'verified'")->fetchColumn();
$pendingPayments = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
$lowStock = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active' AND stock_status <> 'made_to_order' AND (stock_quantity - reserved_quantity) <= low_stock_threshold")->fetchColumn();
$supplierRiskCount = (int) $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status = 'active' AND performance_status IN ('watchlist','needs_review')")->fetchColumn();
$overduePurchaseOrders = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status NOT IN ('received','cancelled') AND expected_delivery_date IS NOT NULL AND expected_delivery_date < CURDATE()")->fetchColumn();
$preferredSupplierCount = (int) $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status = 'active' AND performance_status = 'preferred'")->fetchColumn();
$watchlistSuppliers = $pdo->query(
    "SELECT id, name, performance_status, email
     FROM suppliers
     WHERE status = 'active' AND performance_status IN ('watchlist','needs_review')
     ORDER BY CASE performance_status WHEN 'needs_review' THEN 0 WHEN 'watchlist' THEN 1 ELSE 2 END ASC, name ASC
     LIMIT 5"
)->fetchAll();

$recentRequests = $pdo->query(
    "SELECT r.*, o.id AS order_id, u.full_name, p.name AS product_name FROM requests r
     LEFT JOIN orders o ON o.request_id = r.id
     JOIN users u ON u.id = r.user_id
     JOIN products p ON p.id = r.product_id
     ORDER BY r.created_at DESC LIMIT 6"
)->fetchAll();

$recentPayments = $pdo->query(
    "SELECT pay.*, u.full_name FROM payments pay
     JOIN users u ON u.id = pay.user_id
     ORDER BY pay.created_at DESC LIMIT 6"
)->fetchAll();

include __DIR__ . '/../includes/admin_layout_start.php';
?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="label">Active products</div>
        <div class="value"><?= (int) $stats['products'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Registered customers</div>
        <div class="value"><?= (int) $stats['users'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Requests awaiting review</div>
        <div class="value"><?= (int) $stats['pending'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Orders in progress</div>
        <div class="value"><?= (int) $stats['orders'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Supplier risk watchlist</div>
        <div class="value"><?= (int) $supplierRiskCount ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Preferred suppliers</div>
        <div class="value"><?= (int) $preferredSupplierCount ?></div>
    </div>
</div>

<div class="admin-action-grid">
    <a href="<?= url('/admin/orders/index.php?tab=requests') ?>" class="admin-action-card<?= $stats['pending'] > 0 ? ' is-attention' : '' ?>">
        <span class="admin-action-icon"><i class="bi bi-inbox" aria-hidden="true"></i></span>
        <span><strong><?= (int) $stats['pending'] ?> pending request<?= (int) $stats['pending'] === 1 ? '' : 's' ?></strong><small>Review customer requests</small></span>
        <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </a>
    <a href="<?= url('/admin/payments/index.php?status=pending') ?>" class="admin-action-card<?= $pendingPayments > 0 ? ' is-attention' : '' ?>">
        <span class="admin-action-icon"><i class="bi bi-credit-card" aria-hidden="true"></i></span>
        <span><strong><?= $pendingPayments ?> payment<?= $pendingPayments === 1 ? '' : 's' ?> awaiting review</strong><small>Verify submitted payments</small></span>
        <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </a>
    <a href="<?= url('/admin/inventory/index.php?stock=low') ?>" class="admin-action-card<?= $lowStock > 0 ? ' is-attention' : '' ?>">
        <span class="admin-action-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
        <span><strong><?= $lowStock ?> low-stock product<?= $lowStock === 1 ? '' : 's' ?></strong><small>Check available inventory</small></span>
        <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </a>
    <a href="<?= url('/admin/orders/index.php?tab=orders') ?>" class="admin-action-card">
        <span class="admin-action-icon"><i class="bi bi-truck" aria-hidden="true"></i></span>
        <span><strong><?= (int) $stats['orders'] ?> active order<?= (int) $stats['orders'] === 1 ? '' : 's' ?></strong><small>Track fulfillment progress</small></span>
        <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </a>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>Verified revenue to date</h2>
    </div>
    <div class="panel-body">
        <div class="revenue-figure"><?= money($revenue) ?></div>
        <p class="mb-0 dashboard-supporting-copy">Total of all payments marked verified across deposits and balances.</p>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h3>Recent procurement requests</h3>
        <a href="<?= url('/admin/orders/index.php') ?>" class="btn btn-outline btn-sm">Manage requests</a>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Status</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentRequests as $r): ?>
                    <?php
                    $reqUrl = $r['order_id'] ? url('/admin/orders/view.php?id=' . (int) $r['order_id']) : url('/admin/orders/index.php?tab=requests');
                    ?>
                    <tr class="clickable-row" onclick="window.location.href='<?= e($reqUrl) ?>'">
                        <td><?= e($r['full_name']) ?></td>
                        <td><?= e($r['product_name']) ?></td>
                        <td><?= (int) $r['quantity'] ?></td>
                        <td><span class="badge badge-<?= $r['status'] === 'pending' ? 'warning' : ($r['status'] === 'rejected' ? 'danger' : 'success') ?>"><?= e(request_status_label($r['status'])) ?></span></td>
                        <td><?= display_date($r['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentRequests): ?>
                    <tr>
                        <td colspan="5" class="empty-state">No procurement requests yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h3>Recent payments</h3>
        <a href="<?= url('/admin/payments/index.php') ?>" class="btn btn-outline btn-sm">Manage payments</a>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentPayments as $p): ?>
                    <tr class="clickable-row" onclick="window.location.href='<?= e(url('/admin/payments/view.php?id=' . (int) $p['id'])) ?>'">
                        <td><?= e($p['full_name']) ?></td>
                        <td><?= ucfirst(e($p['payment_type'])) ?></td>
                        <td><?= money($p['amount']) ?></td>
                        <td><span class="badge badge-<?= $p['status'] === 'verified' ? 'success' : ($p['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= e(payment_status_label($p['status'])) ?></span></td>
                        <td><?= display_date($p['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentPayments): ?>
                    <tr>
                        <td colspan="5" class="empty-state">No payments recorded yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_layout_end.php'; ?>