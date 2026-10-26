<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/purchase_orders/index.php');
}
verify_csrf('/admin/purchase_orders/index.php');

$id = (int) ($_POST['id'] ?? 0);
$reason = trim($_POST['approval_notes'] ?? '');

$stmt = $pdo->prepare("SELECT po_number FROM purchase_orders WHERE id = ? AND status = 'submitted'");
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'This purchase order is not awaiting approval.');
    redirect('/admin/purchase_orders/view.php?id=' . $id);
}

$pdo->prepare("UPDATE purchase_orders SET status = 'rejected', approved_by = ?, approved_at = NOW(), approval_notes = ? WHERE id = ?")
    ->execute([$_SESSION['user_id'], $reason ?: null, $id]);

log_activity($pdo, $_SESSION['user_id'], 'po_rejected', "Rejected purchase order #{$po['po_number']}");
set_flash('success', 'Purchase order rejected.');
redirect('/admin/purchase_orders/view.php?id=' . $id);
