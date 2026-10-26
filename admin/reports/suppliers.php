<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Supplier Report';

$bySupplier = $pdo->query(
    "SELECT s.id, s.name, s.status,
        COUNT(o.id) AS order_count,
        COALESCE(SUM(o.total_amount), 0) AS total_revenue,
        COALESCE(SUM(o.supplier_cost), 0) AS total_cost,
        COALESCE(SUM(o.total_amount - COALESCE(o.supplier_cost, 0)), 0) AS total_margin
     FROM suppliers s
     LEFT JOIN orders o ON o.supplier_id = s.id
     GROUP BY s.id
     ORDER BY total_revenue DESC"
)->fetchAll();

$topProductsBySupplier = $pdo->query(
    "SELECT s.name AS supplier_name, p.name AS product_name, sp.supplier_price, sp.warranty_covered, sp.lead_time_days
     FROM supplier_products sp
     JOIN suppliers s ON s.id = sp.supplier_id
     JOIN products p ON p.id = sp.product_id
     WHERE s.status = 'active'
     ORDER BY s.name, p.name"
)->fetchAll();

$unassignedOrders = (int) $pdo->query(
    "SELECT COUNT(*) FROM orders WHERE supplier_id IS NULL AND status NOT IN ('cancelled')"
)->fetchColumn();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<a href="<?= url('/admin/reports/index.php') ?>" class="btn btn-outline btn-sm" style="margin-bottom: 20px;">Back to reports</a>
<a href="<?= url('/admin/reports/export.php?type=suppliers') ?>" class="btn btn-accent btn-sm" style="margin-bottom: 20px; margin-left: 8px;">Export CSV</a>

<?php if ($unassignedOrders > 0): ?>
<div class="alert alert-warning"><?= $unassignedOrders ?> active order<?= $unassignedOrders === 1 ? '' : 's' ?> <?= $unassignedOrders === 1 ? 'has' : 'have' ?> no supplier assigned yet.</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><h3>Spend and margin by supplier</h3></div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr><th>Supplier</th><th>Status</th><th>Orders fulfilled</th><th>Order value</th><th>Supplier cost</th><th>Margin</th></tr>
            </thead>
            <tbody>
                <?php foreach ($bySupplier as $s): ?>
                <tr>
                    <td><a href="<?= url('/admin/suppliers/view.php?id=' . $s['id']) ?>"><?= e($s['name']) ?></a></td>
                    <td><span class="badge badge-<?= $s['status'] === 'active' ? 'success' : 'neutral' ?>"><?= ucfirst(e($s['status'])) ?></span></td>
                    <td><?= (int) $s['order_count'] ?></td>
                    <td><?= money($s['total_revenue']) ?></td>
                    <td><?= money($s['total_cost']) ?></td>
                    <td><?= money($s['total_margin']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$bySupplier): ?>
                    <tr><td colspan="6" class="empty-state">No suppliers recorded yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Active sourcing options</h3></div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>Supplier</th><th>Product</th><th>Price</th><th>Warranty</th><th>Lead time</th></tr></thead>
            <tbody>
                <?php foreach ($topProductsBySupplier as $row): ?>
                <tr>
                    <td><?= e($row['supplier_name']) ?></td>
                    <td><?= e($row['product_name']) ?></td>
                    <td><?= $row['supplier_price'] !== null ? money($row['supplier_price']) : '—' ?></td>
                    <td><span class="badge badge-<?= $row['warranty_covered'] ? 'success' : 'warning' ?>"><?= $row['warranty_covered'] ? 'Covered' : 'Not covered' ?></span></td>
                    <td><?= $row['lead_time_days'] !== null ? (int) $row['lead_time_days'] . ' days' : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$topProductsBySupplier): ?>
                    <tr><td colspan="5" class="empty-state">No products linked to any active supplier yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
