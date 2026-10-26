<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/purchase_orders/index.php');
}
verify_csrf('/admin/purchase_orders/index.php');

$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT id, supplier_id, po_number, supplier_proposed_delivery_date
     FROM purchase_orders
     WHERE id = ? AND status IN ('sent', 'partially_received')"
);
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'Only a purchase order that is already with the supplier can take a proposed delivery date.');
    redirect('/admin/purchase_orders/view.php?id=' . $id);
}

if (empty($po['supplier_proposed_delivery_date'])) {
    set_flash('error', 'The supplier has not proposed a delivery date for this order yet.');
    redirect('/admin/purchase_orders/view.php?id=' . $id);
}

$pdo->prepare('UPDATE purchase_orders SET expected_delivery_date = ? WHERE id = ?')
    ->execute([$po['supplier_proposed_delivery_date'], $id]);

notify_supplier(
    (int) $po['supplier_id'],
    'Delivery date confirmed',
    'The buyer accepted your proposed delivery date for purchase order ' . $po['po_number'] . ': '
        . display_date($po['supplier_proposed_delivery_date'], 'd M Y') . '.',
    '/supplier/purchase_orders/view.php?id=' . $id
);

log_activity($pdo, $_SESSION['user_id'], 'po_delivery_date_accepted', "Accepted the supplier's proposed delivery date on purchase order #{$po['po_number']}");
set_flash('success', 'Expected delivery date updated to the date the supplier proposed.');
redirect('/admin/purchase_orders/view.php?id=' . $id);
