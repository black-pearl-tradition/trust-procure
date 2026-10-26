<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/purchase_orders/index.php');
}
verify_csrf('/admin/purchase_orders/index.php');

$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare("SELECT po_number FROM purchase_orders WHERE id = ? AND status = 'rejected'");
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'Only a rejected purchase order can be reverted to draft.');
    redirect('/admin/purchase_orders/view.php?id=' . $id);
}

$pdo->prepare("UPDATE purchase_orders SET status = 'draft', approved_by = NULL, approved_at = NULL, approval_notes = NULL WHERE id = ?")
    ->execute([$id]);

log_activity($pdo, $_SESSION['user_id'], 'po_reverted', "Reverted purchase order #{$po['po_number']} to draft for revision");
set_flash('success', 'Purchase order moved back to draft. You can now revise it and resubmit.');
redirect('/admin/purchase_orders/view.php?id=' . $id);
