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
    set_flash('error', 'Only an open purchase order can change its response.');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

if (($po['supplier_response'] ?? 'pending') === 'pending') {
    set_flash('warning', 'You have not responded to this purchase order yet.');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

$withdrawnResponse = supplier_response_label($po['supplier_response']);

$pdo->prepare(
    "UPDATE purchase_orders
     SET supplier_response = 'pending',
         supplier_response_at = NULL,
         supplier_response_notes = NULL,
         supplier_proposed_delivery_date = NULL
     WHERE id = ?"
)->execute([$id]);

$adminUsers = $pdo->query("SELECT id FROM users WHERE role IN ('staff', 'admin', 'super_admin') AND status = 'active' AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
foreach ($adminUsers as $adminUserId) {
    create_notification(
        $pdo,
        (int) $adminUserId,
        'Supplier withdrew a response',
        'Supplier withdrew their ' . strtolower($withdrawnResponse) . ' response on purchase order #' . $po['po_number'] . ' and will respond again.',
        '/admin/purchase_orders/view.php?id=' . $id
    );
}

log_activity($pdo, $_SESSION['user_id'], 'supplier_po_response_withdrawn', 'Withdrew the response on purchase order #' . $po['po_number']);
set_flash('success', 'Your response was withdrawn — you can respond to this order again.');
redirect('/supplier/purchase_orders/view.php?id=' . $id);
