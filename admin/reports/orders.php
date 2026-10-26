<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Order & Request Report';

$requestStatus = $pdo->query("SELECT status, COUNT(*) AS total FROM requests GROUP BY status")->fetchAll();
$orderStatus = $pdo->query("SELECT status, COUNT(*) AS total FROM orders GROUP BY status")->fetchAll();

$monthly = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total, COALESCE(SUM(total_amount),0) AS value
     FROM orders GROUP BY month ORDER BY month DESC LIMIT 6"
)->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<a href="<?= url('/admin/reports/index.php') ?>" class="btn btn-outline btn-sm" style="margin-bottom: 20px;">Back to reports</a>
<a href="<?= url('/admin/reports/export.php?type=requests') ?>" class="btn btn-accent btn-sm" style="margin-bottom: 20px; margin-left: 8px;">Export requests CSV</a>
<a href="<?= url('/admin/reports/export.php?type=orders') ?>" class="btn btn-accent btn-sm" style="margin-bottom: 20px; margin-left: 8px;">Export orders CSV</a>

<div class="panel">
    <div class="panel-head"><h3>Requests by status</h3></div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>Status</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($requestStatus as $row): ?>
                <tr><td><?= e(request_status_label($row['status'])) ?></td><td><?= (int) $row['total'] ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$requestStatus): ?><tr><td colspan="2" class="empty-state">No requests yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Orders by status</h3></div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>Status</th><th>Count</th></tr></thead>
            <tbody>
                <?php foreach ($orderStatus as $row): ?>
                <tr><td><?= e(order_status_label($row['status'])) ?></td><td><?= (int) $row['total'] ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$orderStatus): ?><tr><td colspan="2" class="empty-state">No orders yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Orders by month</h3></div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>Month</th><th>Orders</th><th>Total value</th></tr></thead>
            <tbody>
                <?php foreach ($monthly as $row): ?>
                <tr><td><?= e($row['month']) ?></td><td><?= (int) $row['total'] ?></td><td><?= money($row['value']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$monthly): ?><tr><td colspan="3" class="empty-state">No orders yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
