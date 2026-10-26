<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_supplier();

$pageTitle = 'Purchase Orders';

// Get supplier info using the actual email-based supplier/user relationship.
$supplier = get_supplier_for_user((int) $_SESSION['user_id']);

$statuses = ['draft', 'submitted', 'approved', 'rejected', 'sent', 'partially_received', 'received', 'cancelled'];
$orders = [];
$total = 0;
$totalPages = 1;
$requestedStatusFilter = (string) ($_GET['status'] ?? '');
$statusFilter = in_array($requestedStatusFilter, $statuses, true) ? $requestedStatusFilter : '';
$responseFilter = ($_GET['response'] ?? '') === 'pending' ? 'pending' : '';
$search = trim((string) ($_GET['q'] ?? ''));
$fromDate = normalize_iso_date((string) ($_GET['from'] ?? ''));
$toDate = normalize_iso_date((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

if ($supplier) {
    // Build query
    $where = ['po.supplier_id = ?'];
    $params = [$supplier['id']];

    if ($responseFilter === 'pending') {
        // This preset is specifically for unanswered orders sent to this supplier.
        $statusFilter = 'sent';
    }

    if ($statusFilter !== '') {
        $where[] = 'po.status = ?';
        $params[] = $statusFilter;
    }

    // Preset used by the dashboard's "Awaiting Your Response" tile.
    if ($responseFilter === 'pending') {
        $where[] = 'po.supplier_response = "pending"';
    }

    // Keyword search on the PO number. LIKE wildcards typed by the user are
    // escaped so searching for "_" matches a literal underscore.
    if ($search !== '') {
        $where[] = 'po.po_number LIKE ?';
        $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
    }

    if ($fromDate !== null) {
        $where[] = 'po.created_at >= ?';
        $params[] = $fromDate . ' 00:00:00';
    }

    if ($toDate !== null) {
        $where[] = 'po.created_at <= ?';
        $params[] = $toDate . ' 23:59:59';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // Get total count
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM purchase_orders po $whereSql"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));

    // Clamp an out-of-range page: below 1 (e.g. ?page=0) would bind a negative
    // OFFSET, which MySQL rejects, and past the last page would show an empty
    // table even though results exist.
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    // Get orders
    $stmt = $pdo->prepare(
        "SELECT po.*, 
                (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS item_count,
                (SELECT COALESCE(SUM(poi.quantity_ordered), 0) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS total_ordered,
                (SELECT COALESCE(SUM(poi.quantity_received), 0) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS total_received
         FROM purchase_orders po 
         $whereSql 
         ORDER BY po.created_at DESC 
         LIMIT ? OFFSET ?"
    );
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    // Positional binding right through: mixing ? with :limit/:offset in one
    // statement is rejected by PDO ("mixed named and positional parameters")
    // whenever emulated prepares are off, which config/database.php turns off.
    $stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll();
}

$activeFilters = $statusFilter !== '' || $responseFilter === 'pending' || $search !== '' || $fromDate !== null || $toDate !== null;

// Shared by the pagination links so every active filter survives paging.
$pagerQuery = http_build_query(array_filter([
    'status'   => $statusFilter !== '' ? $statusFilter : null,
    'response' => $responseFilter === 'pending' ? 'pending' : null,
    'q'        => $search !== '' ? $search : null,
    'from'     => $fromDate,
    'to'       => $toDate,
], static fn($value): bool => $value !== null));

include __DIR__ . '/../../includes/supplier_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>Purchase Orders</h2>
        <?php if ($responseFilter === 'pending'): ?><span class="badge badge-warning">Awaiting your response</span><?php endif; ?>
    </div>
    <div class="panel-body">
        <form method="get" class="filter-bar" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end;">
            <div class="field" style="margin: 0;">
                <label for="status">Status</label>
                <select id="status" name="status" <?= $responseFilter === 'pending' ? 'disabled' : '' ?>>
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(po_status_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($responseFilter === 'pending'): ?>
                    <input type="hidden" name="response" value="pending">
                    <div class="hint">Status is fixed to sent while filtering for your response.</div>
                <?php endif; ?>
            </div>
            <div class="field" style="margin: 0;">
                <label for="q">PO number</label>
                <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="e.g. PO-2026">
            </div>
            <div class="field" style="margin: 0;">
                <label for="from">Created from</label>
                <input type="date" id="from" name="from" value="<?= e($fromDate ?? '') ?>">
            </div>
            <div class="field" style="margin: 0;">
                <label for="to">Created to</label>
                <input type="date" id="to" name="to" value="<?= e($toDate ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
            <?php if ($activeFilters): ?>
                <a href="<?= url('/supplier/purchase_orders/index.php') ?>" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="panel-body flush">
        <?php if (!$supplier): ?>
            <div class="alert alert-warning">No supplier profile found.</div>
        <?php elseif (!$orders): ?>
            <div class="empty-state">
                <i class="bi bi-file-earmark-text" style="font-size: 2rem;"></i>
                <p>No purchase orders found.</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>PO Number</th>
                        <th>Status</th>
                        <th>Your response</th>
                        <th>Lines</th>
                        <th>Total</th>
                        <th>Units received</th>
                        <th>Expected Delivery</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><strong><?= e($order['po_number']) ?></strong></td>
                            <td><span class="badge badge-<?= po_status_badge_class($order['status']) ?>"><?= e(po_status_label($order['status'])) ?></span></td>
                            <td>
                                <?php if (in_array($order['status'], ['sent', 'partially_received'], true)): ?>
                                    <span class="badge badge-<?= supplier_response_badge_class($order['supplier_response'] ?? 'pending') ?>"><?= e(supplier_response_label($order['supplier_response'] ?? 'pending')) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $order['item_count'] ?></td>
                            <td><?= money($order['grand_total']) ?></td>
                            <td>
                                <?php $received = (int) ($order['total_received'] ?? 0); ?>
                                <?= $received ?> / <?= (int) ($order['total_ordered'] ?? 0) ?> units
                            </td>
                            <td><?= $order['expected_delivery_date'] ? e($order['expected_delivery_date']) : '—' ?></td>
                            <td><?= display_date($order['created_at']) ?></td>
                            <td>
                                <a href="<?= url('/supplier/purchase_orders/view.php?id=' . $order['id']) ?>"
                                    class="btn btn-outline btn-sm">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($totalPages > 1): ?>
                <div style="margin-top: 16px; display: flex; gap: 8px;">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="<?= url('/supplier/purchase_orders/index.php?' . ($pagerQuery !== '' ? $pagerQuery . '&' : '') . 'page=' . $i) ?>"
                            class="btn btn-<?= $i === $page ? 'primary' : 'outline' ?> btn-sm">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/supplier_layout_end.php'; ?>