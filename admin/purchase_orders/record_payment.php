<?php
/**
 * Record a payment (disbursement) made to a supplier against one purchase order.
 *
 * Updates the supplier_payments ledger and then recalculates the purchase
 * order's amount_paid / payment_status through
 * recalculate_po_payment_status(), so the two never drift apart.
 */
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/purchase_orders/index.php');
}

$poId = (int) ($_POST['po_id'] ?? 0);
verify_csrf('/admin/purchase_orders/view.php?id=' . $poId);

$stmt = $pdo->prepare(
    'SELECT po.*, s.name AS supplier_name, s.payment_terms
     FROM purchase_orders po
     JOIN suppliers s ON s.id = po.supplier_id
     WHERE po.id = ?'
);
$stmt->execute([$poId]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'Purchase order not found.');
    redirect('/admin/purchase_orders/index.php');
}

if (!in_array($po['status'], ['approved', 'sent', 'partially_received', 'received'], true)) {
    set_flash('error', 'Payments can only be recorded after a purchase order has been approved.');
    redirect('/admin/purchase_orders/view.php?id=' . $poId);
}

$amount = round((float) ($_POST['amount'] ?? 0), 2);
$paymentDate = normalize_iso_date((string) ($_POST['payment_date'] ?? ''));
if ($paymentDate === null) {
    set_flash('error', 'Enter a valid payment date.');
    redirect('/admin/purchase_orders/view.php?id=' . $poId);
}

$paymentMethod = (string) ($_POST['payment_method'] ?? 'bank_transfer');
if (!in_array($paymentMethod, ['bank_transfer', 'mobile_money', 'cheque', 'cash', 'other'], true)) {
    set_flash('error', 'Select a valid payment method.');
    redirect('/admin/purchase_orders/view.php?id=' . $poId);
}

$paymentType = (string) ($_POST['payment_type'] ?? 'full');
if (!in_array($paymentType, ['advance', 'milestone', 'balance', 'full'], true)) {
    set_flash('error', 'Select a valid payment stage.');
    redirect('/admin/purchase_orders/view.php?id=' . $poId);
}

$referenceNumber = mb_substr(trim((string) ($_POST['reference_number'] ?? '')), 0, 100);
$notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 500);

if ($amount <= 0) {
    set_flash('error', 'Enter a payment amount greater than zero.');
    redirect('/admin/purchase_orders/view.php?id=' . $poId);
}

// Optional payment slip, stored with the other purchase order documents.
$document = null;
try {
    $document = handle_po_document_upload($_FILES['proof_file'] ?? []);
} catch (RuntimeException $e) {
    set_flash('error', $e->getMessage());
    redirect('/admin/purchase_orders/view.php?id=' . $poId);
}

$removeUploadedProof = static function () use (&$document): void {
    if ($document !== null) {
        $orphan = po_document_file_path((string) $document['stored_name']);
        if ($orphan !== null) {
            @unlink($orphan);
        }
    }
};

$proofDocumentId = null;
$pdo->beginTransaction();
try {
    // Serialize balance checks for this PO so simultaneous disbursements
    // cannot both pass against the same outstanding amount.
    $lockedPoStmt = $pdo->prepare('SELECT grand_total, status FROM purchase_orders WHERE id = ? FOR UPDATE');
    $lockedPoStmt->execute([$poId]);
    $lockedPo = $lockedPoStmt->fetch();

    if (!$lockedPo || !in_array($lockedPo['status'], ['approved', 'sent', 'partially_received', 'received'], true)) {
        $pdo->rollBack();
        $removeUploadedProof();
        set_flash('error', 'Payments can only be recorded after a purchase order has been approved.');
        redirect('/admin/purchase_orders/view.php?id=' . $poId);
    }

    $paymentRowsStmt = $pdo->prepare('SELECT amount FROM supplier_payments WHERE purchase_order_id = ? FOR UPDATE');
    $paymentRowsStmt->execute([$poId]);
    $alreadyPaid = array_sum(array_map('floatval', $paymentRowsStmt->fetchAll(PDO::FETCH_COLUMN)));
    $outstanding = max(0, (float) $lockedPo['grand_total'] - $alreadyPaid);

    if ($amount > $outstanding + 0.01) {
        $pdo->rollBack();
        $removeUploadedProof();
        set_flash('error', 'That is more than the outstanding balance of ' . money($outstanding) . ' on this purchase order.');
        redirect('/admin/purchase_orders/view.php?id=' . $poId);
    }

    if ($document !== null) {
        $pdo->prepare(
            'INSERT INTO purchase_order_documents
                (purchase_order_id, uploaded_by, document_type, original_name, stored_name, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $poId,
            $_SESSION['user_id'],
            'other',
            'Payment proof — ' . $document['original_name'],
            $document['stored_name'],
            $document['mime_type'],
            $document['file_size'],
        ]);
        $proofDocumentId = (int) $pdo->lastInsertId();
    }

    $pdo->prepare(
        'INSERT INTO supplier_payments
            (purchase_order_id, amount, payment_method, payment_type, reference_number,
             payment_date, proof_document_id, notes, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $poId,
        $amount,
        $paymentMethod,
        $paymentType,
        $referenceNumber !== '' ? $referenceNumber : null,
        $paymentDate,
        $proofDocumentId,
        $notes !== '' ? $notes : null,
        $_SESSION['user_id'],
    ]);

    recalculate_po_payment_status($pdo, $poId);

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $removeUploadedProof();

    set_flash('error', 'The payment could not be recorded. Has config/migrations.sql been run on this database?');
    redirect('/admin/purchase_orders/view.php?id=' . $poId);
}

$statusStmt = $pdo->prepare('SELECT amount_paid, payment_status FROM purchase_orders WHERE id = ?');
$statusStmt->execute([$poId]);
$updated = $statusStmt->fetch();
$newStatus = $updated ? (string) $updated['payment_status'] : 'unpaid';

log_activity(
    $pdo,
    $_SESSION['user_id'],
    'supplier_payment_recorded',
    'Recorded a ' . money($amount) . ' payment to ' . $po['supplier_name'] . ' for purchase order ' . $po['po_number']
);

notify_supplier(
    (int) $po['supplier_id'],
    'Payment recorded',
    'A payment of ' . money($amount) . ' was recorded against purchase order ' . $po['po_number'] . '.',
    '/supplier/purchase_orders/view.php?id=' . $poId
);

set_flash(
    'success',
    'Payment of ' . money($amount) . ' recorded. Order is now ' . strtolower(po_payment_status_label($newStatus)) . '.'
);
redirect('/admin/purchase_orders/view.php?id=' . $poId);
