<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/purchase_orders/index.php');
}
verify_csrf('/admin/purchase_orders/index.php');

$poId = (int) ($_POST['po_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id FROM purchase_orders WHERE id = ? AND status = 'draft'");
$stmt->execute([$poId]);

if (!$stmt->fetch()) {
    set_flash('error', 'This purchase order can no longer be edited.');
    redirect('/admin/purchase_orders/view.php?id=' . $poId);
}

$expectedDate = trim($_POST['expected_delivery_date'] ?? '');
$additionalCosts = max(0, (float) ($_POST['additional_costs'] ?? 0));
$notes = trim($_POST['notes'] ?? '');

$pdo->prepare('UPDATE purchase_orders SET expected_delivery_date = ?, additional_costs = ?, notes = ? WHERE id = ?')
    ->execute([$expectedDate ?: null, $additionalCosts, $notes ?: null, $poId]);

recalculate_po_totals($pdo, $poId);
log_activity($pdo, $_SESSION['user_id'], 'po_updated', "Updated header for purchase order #$poId");
set_flash('success', 'Purchase order updated.');
redirect('/admin/purchase_orders/view.php?id=' . $poId);
