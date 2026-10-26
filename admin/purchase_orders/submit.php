<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/purchase_orders/index.php');
}
verify_csrf('/admin/purchase_orders/index.php');

$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND status = 'draft'");
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'This purchase order cannot be submitted.');
    redirect('/admin/purchase_orders/view.php?id=' . $id);
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = ?');
$countStmt->execute([$id]);
$itemCount = (int) $countStmt->fetchColumn();

if ($itemCount === 0) {
    set_flash('error', 'Add at least one item before submitting for approval.');
    redirect('/admin/purchase_orders/view.php?id=' . $id);
}

$pdo->prepare("UPDATE purchase_orders SET status = 'submitted', approved_by = NULL, approved_at = NULL, approval_notes = NULL WHERE id = ?")
    ->execute([$id]);

log_activity($pdo, $_SESSION['user_id'], 'po_submitted', "Submitted purchase order #{$po['po_number']} for approval");
set_flash('success', 'Purchase order submitted for approval.');
redirect('/admin/purchase_orders/view.php?id=' . $id);
