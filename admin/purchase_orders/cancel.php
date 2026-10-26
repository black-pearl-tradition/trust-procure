<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/purchase_orders/index.php');
}
verify_csrf('/admin/purchase_orders/index.php');

$id = (int) ($_POST['id'] ?? 0);
$reason = trim($_POST['cancellation_reason'] ?? '');

if ($reason === '') {
    set_flash('error', 'Please provide a reason for cancelling this purchase order.');
    redirect('/admin/purchase_orders/view.php?id=' . $id);
}

$stmt = $pdo->prepare(
    "SELECT po_number, supplier_id, status, supplier_response
     FROM purchase_orders
     WHERE id = ?
       AND (
           status IN ('draft', 'submitted', 'approved', 'rejected')
           OR (status IN ('sent', 'partially_received') AND supplier_response = 'declined')
       )"
);
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'This purchase order can only be cancelled before sending or after the supplier declines it.');
    redirect('/admin/purchase_orders/view.php?id=' . $id);
}

$pdo->prepare("UPDATE purchase_orders SET status = 'cancelled', cancellation_reason = ? WHERE id = ?")
    ->execute([$reason, $id]);

if ($po['status'] === 'sent') {
    notify_supplier(
        (int) $po['supplier_id'],
        'Purchase order cancelled',
        'Purchase order #' . $po['po_number'] . ' was cancelled by the buyer.'
            . ' Reason: ' . $reason,
        '/supplier/purchase_orders/view.php?id=' . $id
    );
}

log_activity($pdo, $_SESSION['user_id'], 'po_cancelled', "Cancelled purchase order #{$po['po_number']}");
set_flash('success', 'Purchase order cancelled.');
redirect('/admin/purchase_orders/view.php?id=' . $id);
