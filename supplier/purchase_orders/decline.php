<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_supplier();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/supplier/purchase_orders/index.php');
}
verify_csrf('/supplier/purchase_orders/index.php');

$id = (int) ($_POST['id'] ?? 0);

$supplier = get_supplier_for_user((int) $_SESSION['user_id']);
if (!$supplier) {
    set_flash('error', 'Supplier profile not found.');
    redirect('/supplier/purchase_orders/index.php');
}

$pdo->beginTransaction();
$stmt = $pdo->prepare(
    "SELECT po.*
     FROM purchase_orders po
     WHERE po.id = ? AND po.supplier_id = ? AND po.status IN ('sent', 'partially_received')
     FOR UPDATE"
);
$stmt->execute([$id, $supplier['id']]);
$po = $stmt->fetch();

if (!$po) {
    $pdo->rollBack();
    set_flash('error', 'Only an open purchase order can be declined.');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

$receivedStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(quantity_received), 0)
     FROM purchase_order_items
     WHERE purchase_order_id = ?'
);
$receivedStmt->execute([$id]);
if ((int) $receivedStmt->fetchColumn() > 0) {
    $pdo->rollBack();
    set_flash('error', 'A purchase order cannot be declined after a partial delivery has been received.');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

$pendingDeliveryStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM supplier_deliveries
     WHERE purchase_order_id = ? AND status = 'submitted'"
);
$pendingDeliveryStmt->execute([$id]);
if ((int) $pendingDeliveryStmt->fetchColumn() > 0) {
    $pdo->rollBack();
    set_flash('error', 'A purchase order cannot be declined while a supplier delivery notice is awaiting verification.');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

$notes = mb_substr(trim((string) ($_POST['supplier_response_notes'] ?? '')), 0, 500);
if ($notes === '') {
    $pdo->rollBack();
    set_flash('error', 'Please add a note explaining why you are declining this purchase order.');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

$previousResponse = $po['supplier_response'] ?? 'pending';

// Declining clears any delivery date proposed with an earlier acknowledgement.
$pdo->prepare(
    "UPDATE purchase_orders
     SET supplier_response = 'declined',
         supplier_response_at = NOW(),
         supplier_response_notes = ?,
         supplier_proposed_delivery_date = NULL
     WHERE id = ?"
)->execute([$notes, $id]);
$pdo->commit();

$adminUsers = $pdo->query("SELECT id FROM users WHERE role IN ('staff', 'admin', 'super_admin') AND status = 'active' AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
foreach ($adminUsers as $adminUserId) {
    create_notification(
        $pdo,
        (int) $adminUserId,
        'Supplier declined purchase order',
        'Supplier has declined purchase order #' . $po['po_number'] . ': ' . $notes,
        '/admin/purchase_orders/view.php?id=' . $id
    );
}

log_activity($pdo, $_SESSION['user_id'], 'supplier_po_declined', 'Supplier declined purchase order #' . $po['po_number']);
set_flash('success', $previousResponse === 'pending' ? 'Purchase order declined.' : 'Your response has been updated.');
redirect('/supplier/purchase_orders/view.php?id=' . $id);
