<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();
require_once __DIR__ . '/../../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/purchase_orders/index.php');
}
verify_csrf('/admin/purchase_orders/index.php');

$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT po.*, s.name AS supplier_name, s.email AS supplier_email
     FROM purchase_orders po
     JOIN suppliers s ON s.id = po.supplier_id
     WHERE po.id = ? AND po.status = 'approved'"
);
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'Only an approved purchase order can be sent.');
    redirect('/admin/purchase_orders/view.php?id=' . $id);
}

$itemsStmt = $pdo->prepare(
    'SELECT poi.quantity_ordered, poi.unit_cost, poi.line_total, p.name AS product_name
     FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id
     WHERE poi.purchase_order_id = ? ORDER BY poi.id'
);
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
$unlinkedItemsStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM purchase_order_items poi
     LEFT JOIN supplier_products sp ON sp.product_id = poi.product_id AND sp.supplier_id = ?
     WHERE poi.purchase_order_id = ? AND sp.id IS NULL'
);
$unlinkedItemsStmt->execute([(int) $po['supplier_id'], $id]);
if ((int) $unlinkedItemsStmt->fetchColumn() > 0) {
    set_flash('error', 'This purchase order includes products that are not linked to its supplier. Update the PO before sending it.');
    redirect('/admin/purchase_orders/view.php?id=' . $id);
}
$lines = [];
$lines[] = 'Purchase order: ' . $po['po_number'];
$lines[] = 'Supplier: ' . $po['supplier_name'];
$lines[] = 'Expected delivery: ' . ($po['expected_delivery_date'] ?: 'To be confirmed');
$lines[] = '';
$lines[] = 'Items:';
foreach ($items as $item) {
    $lines[] = '- ' . $item['product_name'] . ' | Qty: ' . (int) $item['quantity_ordered'] . ' | Unit cost: ' . money($item['unit_cost']) . ' | Total: ' . money($item['line_total']);
}
$lines[] = '';
$lines[] = 'Subtotal: ' . money($po['subtotal']);
$lines[] = 'Additional costs: ' . money($po['additional_costs']);
$lines[] = 'Grand total: ' . money($po['grand_total']);
if ($po['notes']) {
    $lines[] = '';
    $lines[] = 'Notes: ' . $po['notes'];
}
$lines[] = '';
$lines[] = 'Please confirm receipt of this purchase order and the expected delivery date.';

$emailSent = false;
if (!empty($po['supplier_email']) && filter_var($po['supplier_email'], FILTER_VALIDATE_EMAIL)) {
    $emailSent = send_email($pdo, $po['supplier_email'], 'Purchase order ' . $po['po_number'], implode("\n", $lines));
}

$pdo->prepare("UPDATE purchase_orders SET status = 'sent' WHERE id = ?")->execute([$id]);

// Give the supplier an in-portal signal as well as the email: without this the
// order only appears in their list silently, and there is no notification at
// all when the supplier record has no deliverable email address.
notify_supplier(
    (int) $po['supplier_id'],
    'Purchase order ' . $po['po_number'],
    'Purchase order ' . $po['po_number'] . ' has been sent to you (' . count($items) . ' item(s), '
        . money($po['grand_total']) . '). Please review it and acknowledge or decline it.',
    '/supplier/purchase_orders/view.php?id=' . $id
);

log_activity($pdo, $_SESSION['user_id'], 'po_sent', "Marked purchase order #{$po['po_number']} as sent to supplier");
if ($emailSent) {
    set_flash('success', 'Purchase order marked as sent and emailed to the supplier.');
} elseif (!empty($po['supplier_email'])) {
    set_flash('warning', 'Purchase order marked as sent, but the supplier email could not be delivered.');
} else {
    set_flash('warning', 'Purchase order marked as sent, but this supplier has no valid email address.');
}
redirect('/admin/purchase_orders/view.php?id=' . $id);
