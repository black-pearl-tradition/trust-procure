<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Payment Report';

$byStatus = $pdo->query(
    "SELECT status, COUNT(*) AS total, COALESCE(SUM(amount),0) AS value FROM payments GROUP BY status"
)->fetchAll();

$byType = $pdo->query(
    "SELECT payment_type, COUNT(*) AS total, COALESCE(SUM(amount),0) AS value
     FROM payments WHERE status = 'verified' GROUP BY payment_type"
)->fetchAll();

$byMethod = $pdo->query(
    "SELECT COALESCE(method, 'Unspecified') AS method, COUNT(*) AS total, COALESCE(SUM(amount),0) AS value
     FROM payments WHERE status = 'verified' GROUP BY method"
)->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<a href="<?= url('/admin/reports/index.php') ?>" class="btn btn-outline btn-sm" style="margin-bottom: 20px;">Back to reports</a>
<a href="<?= url('/admin/reports/export.php?type=payments') ?>" class="btn btn-accent btn-sm" style="margin-bottom: 20px; margin-left: 8px;">Export CSV</a>

<div class="panel">
    <div class="panel-head"><h3>By verification status</h3></div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>Status</th><th>Count</th><th>Amount</th></tr></thead>
            <tbody>
                <?php foreach ($byStatus as $row): ?>
                <tr><td><?= e(payment_status_label($row['status'])) ?></td><td><?= (int) $row['total'] ?></td><td><?= money($row['value']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$byStatus): ?><tr><td colspan="3" class="empty-state">No payments yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Verified — deposit vs balance</h3></div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>Type</th><th>Count</th><th>Amount</th></tr></thead>
            <tbody>
                <?php foreach ($byType as $row): ?>
                <tr><td><?= ucfirst(e($row['payment_type'])) ?></td><td><?= (int) $row['total'] ?></td><td><?= money($row['value']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$byType): ?><tr><td colspan="3" class="empty-state">No verified payments yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Verified — by method</h3></div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>Method</th><th>Count</th><th>Amount</th></tr></thead>
            <tbody>
                <?php foreach ($byMethod as $row): ?>
                <tr><td><?= e($row['method']) ?></td><td><?= (int) $row['total'] ?></td><td><?= money($row['value']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$byMethod): ?><tr><td colspan="3" class="empty-state">No verified payments yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
