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

$stmt = $pdo->prepare(
    "SELECT po.*
     FROM purchase_orders po
     WHERE po.id = ? AND po.supplier_id = ? AND po.status IN ('sent', 'partially_received')"
);
$stmt->execute([$id, $supplier['id']]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'Only an open purchase order can be acknowledged.');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

$notes = mb_substr(trim((string) ($_POST['supplier_response_notes'] ?? '')), 0, 500);

// The supplier may state the delivery date they can commit to; anything that is
// not a real Y-m-d date is rejected rather than stored as a broken value.
$proposedRaw = trim((string) ($_POST['supplier_proposed_delivery_date'] ?? ''));
$proposedDate = normalize_iso_date($proposedRaw);
if ($proposedRaw !== '' && $proposedDate === null) {
    set_flash('error', 'Enter the delivery date you can commit to as a real date.');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

$previousResponse = $po['supplier_response'] ?? 'pending';

$pdo->prepare(
    "UPDATE purchase_orders
     SET supplier_response = 'acknowledged',
         supplier_response_at = NOW(),
         supplier_response_notes = ?,
         supplier_proposed_delivery_date = ?
     WHERE id = ?"
)->execute([$notes !== '' ? $notes : null, $proposedDate, $id]);

$adminUsers = $pdo->query("SELECT id FROM users WHERE role IN ('staff', 'admin', 'super_admin') AND status = 'active' AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
$deliveryNote = $proposedDate !== null ? ' Proposed delivery date: ' . display_date($proposedDate, 'd M Y') . '.' : '';
foreach ($adminUsers as $adminUserId) {
    create_notification(
        $pdo,
        (int) $adminUserId,
        'Supplier acknowledged purchase order',
        'Supplier has acknowledged purchase order #' . $po['po_number'] . '.' . $deliveryNote . ($notes !== '' ? ' Note: ' . $notes : ''),
        '/admin/purchase_orders/view.php?id=' . $id
    );
}

log_activity($pdo, $_SESSION['user_id'], 'supplier_po_acknowledged', 'Supplier acknowledged purchase order #' . $po['po_number']);
set_flash('success', $previousResponse === 'pending' ? 'Purchase order acknowledged.' : 'Your response has been updated.');
redirect('/supplier/purchase_orders/view.php?id=' . $id);
