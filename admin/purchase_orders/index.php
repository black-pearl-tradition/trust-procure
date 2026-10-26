<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Purchase Orders';

$statusFilter = $_GET['status'] ?? '';
$supplierFilter = (int) ($_GET['supplier_id'] ?? 0);
$paymentFilter = (string) ($_GET['payment'] ?? '');
$page = current_page();
$perPage = 15;

$where = [];
$params = [];
if ($statusFilter !== '') {
    $where[] = 'po.status = ?';
    $params[] = $statusFilter;
}
if ($supplierFilter) {
    $where[] = 'po.supplier_id = ?';
    $params[] = $supplierFilter;
}
// Payables view: filter by what the business still owes this supplier.
if (in_array($paymentFilter, ['unpaid', 'partially_paid', 'paid'], true)) {
    $where[] = 'po.payment_status = ?';
    $params[] = $paymentFilter;
} elseif ($paymentFilter === 'overdue') {
    $where[] = "po.payment_due_date IS NOT NULL AND po.payment_status <> 'paid' AND po.payment_due_date < CURDATE()";
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders po" . $whereSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$sql = "SELECT po.*, s.name AS supplier_name,
            (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS item_count
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id"
    . $whereSql . " ORDER BY po.created_at DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
foreach ($params as $i => $val) {
    $stmt->bindValue($i + 1, $val);
}
// Positional binding right through: mixing ? with :limit/:offset in one
// statement is rejected by PDO ("mixed named and positional parameters")
// whenever emulated prepares are off, which config/database.php turns off.
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll();

$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
$statuses = ['draft', 'submitted', 'approved', 'rejected', 'sent', 'partially_received', 'received', 'cancelled'];

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>All purchase orders</h2>
        <div class="table-actions">
            <a href="<?= url('/admin/purchase_orders/low_stock.php') ?>" class="btn btn-outline btn-sm">
                <i class="bi bi-lightning-charge me-1"></i> Restock Low Inventory
            </a>
            <a href="<?= url('/admin/purchase_orders/add.php') ?>" class="btn btn-accent btn-sm">New purchase order</a>
        </div>
    </div>
    <div class="panel-body">
        <form method="get" class="filter-bar" style="margin-bottom: 0;">
            <select name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(po_status_label($s)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="supplier_id" onchange="this.form.submit()">
                <option value="0">All suppliers</option>
                <?php foreach ($suppliers as $sup): ?>
                    <option value="<?= $sup['id'] ?>" <?= $supplierFilter === (int) $sup['id'] ? 'selected' : '' ?>><?= e($sup['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="payment" onchange="this.form.submit()">
                <option value="">All payment states</option>
                <option value="unpaid" <?= $paymentFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                <option value="partially_paid" <?= $paymentFilter === 'partially_paid' ? 'selected' : '' ?>>Partially paid</option>
                <option value="paid" <?= $paymentFilter === 'paid' ? 'selected' : '' ?>>Fully paid</option>
                <option value="overdue" <?= $paymentFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
            </select>
            <?php if ($paymentFilter !== '' || $statusFilter !== '' || $supplierFilter): ?>
                <a href="<?= url('/admin/purchase_orders/index.php') ?>" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>PO number</th>
                    <th>Supplier</th>
                    <th>Items</th>
                    <th>Grand total</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Supplier Response</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $po): ?>
                    <tr>
                        <td><?= e($po['po_number']) ?></td>
                        <td><?= e($po['supplier_name']) ?></td>
                        <td><?= (int) $po['item_count'] ?></td>
                        <td><?= money($po['grand_total']) ?></td>
                        <td>
                            <span class="badge badge-<?= po_payment_status_badge_class($po['payment_status'] ?? 'unpaid') ?>"><?= e(po_payment_status_label($po['payment_status'] ?? 'unpaid')) ?></span>
                            <?php if ($po['payment_due_date'] && ($po['payment_status'] ?? 'unpaid') !== 'paid'): ?>
                                <?php $overdue = $po['payment_due_date'] < date('Y-m-d'); ?>
                                <div style="font-size: 0.78rem; margin-top: 4px;<?= $overdue ? ' color: #b91c1c; font-weight: 600;' : '' ?>" class="<?= $overdue ? '' : 'muted' ?>">
                                    <?= $overdue ? 'Overdue since ' : 'Due ' ?><?= display_date($po['payment_due_date'], 'd M Y') ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?= po_status_badge_class($po['status']) ?>"><?= e(po_status_label($po['status'])) ?></span></td>
                        <td>
                            <?php if ($po['status'] === 'sent'): ?>
                                <span class="badge badge-<?= supplier_response_badge_class($po['supplier_response'] ?? 'pending') ?>"><?= e(supplier_response_label($po['supplier_response'] ?? 'pending')) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= display_date($po['created_at']) ?></td>
                        <td><a href="<?= url('/admin/purchase_orders/view.php?id=' . $po['id']) ?>" class="btn btn-outline btn-sm">Manage</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?>
                    <tr>
                        <td colspan="9" class="empty-state">No purchase orders yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php render_pagination($page, $totalPages, '/admin/purchase_orders/index.php', ['status' => $statusFilter, 'supplier_id' => $supplierFilter, 'payment' => $paymentFilter]); ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>