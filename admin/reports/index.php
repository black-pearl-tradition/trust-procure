<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Reports';

$totals = [
    'products'  => $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
    'requests'  => $pdo->query("SELECT COUNT(*) FROM requests")->fetchColumn(),
    'orders'    => $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
    'revenue'   => $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='verified'")->fetchColumn(),
    'pending_payments' => $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='pending'")->fetchColumn(),
    'outstanding_balance' => $pdo->query("SELECT COALESCE(SUM(balance_amount),0) FROM orders WHERE status NOT IN ('completed','cancelled')")->fetchColumn(),
];

$byStatus = $pdo->query(
    "SELECT status, COUNT(*) AS total FROM orders GROUP BY status"
)->fetchAll();

$topProducts = $pdo->query(
    "SELECT p.name, COUNT(o.id) AS order_count, COALESCE(SUM(o.total_amount),0) AS revenue
     FROM products p LEFT JOIN orders o ON o.product_id = p.id
     GROUP BY p.id ORDER BY order_count DESC LIMIT 5"
)->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="stat-grid">
    <div class="stat-card"><div class="label">Total products</div><div class="value"><?= (int) $totals['products'] ?></div></div>
    <div class="stat-card"><div class="label">Total requests</div><div class="value"><?= (int) $totals['requests'] ?></div></div>
    <div class="stat-card"><div class="label">Total orders</div><div class="value"><?= (int) $totals['orders'] ?></div></div>
    <div class="stat-card"><div class="label">Verified revenue</div><div class="value" style="font-size: 1.4rem;"><?= money($totals['revenue']) ?></div></div>
</div>

<div class="grid-4" style="margin-bottom: 24px;">
    <a href="<?= url('/admin/reports/products.php') ?>" class="value-card">
        <h3>Product report</h3>
        <p>Catalog size, pricing distribution and category coverage.</p>
    </a>
    <a href="<?= url('/admin/reports/orders.php') ?>" class="value-card">
        <h3>Order &amp; request report</h3>
        <p>Requests, conversions and orders broken down by status.</p>
    </a>
    <a href="<?= url('/admin/reports/payments.php') ?>" class="value-card">
        <h3>Payment report</h3>
        <p>Deposits, balances, and verification activity over time.</p>
    </a>
    <a href="<?= url('/admin/reports/suppliers.php') ?>" class="value-card">
        <h3>Supplier report</h3>
        <p>Spend, margin and sourcing options by supplier.</p>
    </a>
</div>

<div class="panel">
    <div class="panel-head"><h3>Export raw data</h3></div>
    <div class="panel-body table-actions">
        <a href="<?= url('/admin/reports/export.php?type=products') ?>" class="btn btn-outline btn-sm">Products CSV</a>
        <a href="<?= url('/admin/reports/export.php?type=requests') ?>" class="btn btn-outline btn-sm">Requests CSV</a>
        <a href="<?= url('/admin/reports/export.php?type=orders') ?>" class="btn btn-outline btn-sm">Orders CSV</a>
        <a href="<?= url('/admin/reports/export.php?type=payments') ?>" class="btn btn-outline btn-sm">Payments CSV</a>
        <a href="<?= url('/admin/reports/export.php?type=suppliers') ?>" class="btn btn-outline btn-sm">Suppliers CSV</a>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Orders by status</h3></div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>Status</th><th>Orders</th></tr></thead>
            <tbody>
                <?php foreach ($byStatus as $row): ?>
                <tr><td><?= e(order_status_label($row['status'])) ?></td><td><?= (int) $row['total'] ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$byStatus): ?><tr><td colspan="2" class="empty-state">No orders yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Most requested products</h3></div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>Product</th><th>Orders</th><th>Revenue (total order value)</th></tr></thead>
            <tbody>
                <?php foreach ($topProducts as $row): ?>
                <tr><td><?= e($row['name']) ?></td><td><?= (int) $row['order_count'] ?></td><td><?= money($row['revenue']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$topProducts): ?><tr><td colspan="3" class="empty-state">No data yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Outstanding amounts</h3></div>
    <div class="panel-body">
        <div class="detail-grid">
            <div class="detail-item"><div class="label">Payments awaiting verification</div><div class="value"><?= money($totals['pending_payments']) ?></div></div>
            <div class="detail-item"><div class="label">Balance still owed across open orders</div><div class="value"><?= money($totals['outstanding_balance']) ?></div></div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
