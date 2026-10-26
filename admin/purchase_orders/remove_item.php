<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/purchase_orders/index.php');
}
verify_csrf('/admin/purchase_orders/index.php');

$poId = (int) ($_POST['po_id'] ?? 0);
$itemId = (int) ($_POST['item_id'] ?? 0);

$stmt = $pdo->prepare("SELECT id FROM purchase_orders WHERE id = ? AND status = 'draft'");
$stmt->execute([$poId]);

if (!$stmt->fetch()) {
    set_flash('error', 'This purchase order can no longer be edited.');
    redirect('/admin/purchase_orders/view.php?id=' . $poId);
}

$pdo->prepare('DELETE FROM purchase_order_items WHERE id = ? AND purchase_order_id = ?')->execute([$itemId, $poId]);
recalculate_po_totals($pdo, $poId);

log_activity($pdo, $_SESSION['user_id'], 'po_item_removed', "Removed item #$itemId from purchase order #$poId");
set_flash('success', 'Item removed.');
redirect('/admin/purchase_orders/view.php?id=' . $poId);
