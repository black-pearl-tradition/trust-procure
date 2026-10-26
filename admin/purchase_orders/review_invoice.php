<?php
/**
 * Approve or reject a supplier invoice attached to a purchase order.
 *
 * Invoice review is separate from recording PO-level supplier disbursements.
 */
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/purchase_orders/index.php');
}

$poId = (int) ($_POST['po_id'] ?? 0);
verify_csrf('/admin/purchase_orders/view.php?id=' . $poId);

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$reviewNotes = trim((string) ($_POST['review_notes'] ?? ''));

if (!in_array($action, ['approve', 'reject'], true)) {
    set_flash('error', 'Unknown invoice action.');
    redirect('/admin/purchase_orders/view.php?id=' . $poId);
}

$stmt = $pdo->prepare(
    'SELECT si.*, po.po_number, po.supplier_id, s.name AS supplier_name
     FROM supplier_invoices si
     JOIN purchase_orders po ON po.id = si.purchase_order_id
     JOIN suppliers s ON s.id = po.supplier_id
     WHERE si.id = ? AND si.purchase_order_id = ?'
);
$stmt->execute([$invoiceId, $poId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    set_flash('error', 'Supplier invoice not found on this purchase order.');
    redirect('/admin/purchase_orders/view.php?id=' . $poId);
}

$newStatus = $action === 'approve' ? 'approved' : 'rejected';

if ($invoice['status'] === 'settled') {
    set_flash('error', 'This invoice has a legacy settled status and cannot be reviewed again.');
    redirect('/admin/purchase_orders/view.php?id=' . $poId);
}

$notes = $invoice['notes'];
if ($reviewNotes !== '') {
    // Notes are capped by the column width, so keep the tail rather than
    // letting MySQL reject an over-long note outright.
    $notes = mb_substr($reviewNotes, 0, 500);
}

$pdo->prepare(
    'UPDATE supplier_invoices
     SET status = ?, notes = ?, reviewed_by = ?, reviewed_at = NOW()
     WHERE id = ?'
)->execute([$newStatus, $notes, $_SESSION['user_id'], $invoiceId]);

$statusLabels = [
    'approved' => 'approved',
    'rejected' => 'rejected',
];

log_activity(
    $pdo,
    $_SESSION['user_id'],
    'supplier_invoice_' . $newStatus,
    ucfirst($statusLabels[$newStatus]) . ' supplier invoice ' . $invoice['invoice_number']
        . ' on purchase order ' . $invoice['po_number']
);

notify_supplier(
    (int) $invoice['supplier_id'],
    'Supplier invoice ' . $statusLabels[$newStatus],
    'Invoice ' . $invoice['invoice_number'] . ' (' . money((float) $invoice['total_amount']) . ') for purchase order '
        . $invoice['po_number'] . ' was ' . $statusLabels[$newStatus] . '.',
    '/supplier/purchase_orders/view.php?id=' . $poId
);

set_flash('success', 'Invoice ' . $invoice['invoice_number'] . ' ' . $statusLabels[$newStatus] . '.');
redirect('/admin/purchase_orders/view.php?id=' . $poId);
