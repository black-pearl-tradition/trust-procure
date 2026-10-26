<?php
/**
 * Supplier portal: submit a proforma / tax invoice against a purchase order.
 *
 * The invoice is recorded with status "pending" and wait for an administrator
 * to review it; the optional scan is stored with the order's other documents so
 * administrators open it through po-document.php.
 */
require_once __DIR__ . '/../../includes/auth_check.php';
require_supplier();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/supplier/purchase_orders/index.php');
}

$id = (int) ($_POST['id'] ?? 0);
verify_csrf('/supplier/purchase_orders/view.php?id=' . $id);

$supplier = get_supplier_for_user((int) $_SESSION['user_id']);
if (!$supplier) {
    set_flash('error', 'Supplier profile not found.');
    redirect('/supplier/purchase_orders/index.php');
}

$stmt = $pdo->prepare(
    "SELECT po.*
     FROM purchase_orders po
     WHERE po.id = ? AND po.supplier_id = ?
       AND po.status IN ('approved', 'sent', 'partially_received', 'received')"
);
$stmt->execute([$id, $supplier['id']]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'Purchase order not found, or it is not open for invoicing yet.');
    redirect('/supplier/purchase_orders/index.php');
}

$invoiceNumber = trim((string) ($_POST['invoice_number'] ?? ''));
$invoiceType = (string) ($_POST['invoice_type'] ?? 'tax_invoice');
$invoiceDate = normalize_iso_date((string) ($_POST['invoice_date'] ?? ''));
$dueDate = normalize_iso_date((string) ($_POST['due_date'] ?? ''));
$totalAmount = round((float) ($_POST['total_amount'] ?? 0), 2);
$notes = trim((string) ($_POST['notes'] ?? ''));

if (!in_array($invoiceType, ['proforma', 'tax_invoice', 'credit_note'], true)) {
    $invoiceType = 'tax_invoice';
}

if ($invoiceNumber === '') {
    set_flash('error', 'Enter your invoice number.');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

if (mb_strlen($invoiceNumber) > 100) {
    $invoiceNumber = mb_substr($invoiceNumber, 0, 100);
}

if ($invoiceDate === null) {
    $invoiceDate = date('Y-m-d');
}

if ($totalAmount <= 0) {
    set_flash('error', 'Enter the invoice total as an amount greater than zero.');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

// The same invoice number must never be booked twice on one purchase order —
// that would double up the payable and could lead to a duplicate payment.
$duplicateStmt = $pdo->prepare('SELECT id FROM supplier_invoices WHERE purchase_order_id = ? AND invoice_number = ?');
$duplicateStmt->execute([$id, $invoiceNumber]);
if ($duplicateStmt->fetch()) {
    set_flash('error', 'Invoice ' . $invoiceNumber . ' has already been submitted for this purchase order.');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

// Optional scan of the invoice itself.
$document = null;
try {
    $document = handle_po_document_upload($_FILES['document'] ?? []);
} catch (RuntimeException $e) {
    set_flash('error', $e->getMessage());
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

$documentId = null;
if ($document !== null) {
    try {
        $pdo->prepare(
            'INSERT INTO purchase_order_documents
                (purchase_order_id, uploaded_by, document_type, original_name, stored_name, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $_SESSION['user_id'],
            'invoice',
            $document['original_name'],
            $document['stored_name'],
            $document['mime_type'],
            $document['file_size'],
        ]);
        $documentId = (int) $pdo->lastInsertId();
    } catch (Exception $e) {
        $orphan = po_document_file_path((string) $document['stored_name']);
        if ($orphan !== null) {
            @unlink($orphan);
        }

        set_flash('error', 'The invoice file could not be saved. Has config/migrations.sql been run on this database?');
        redirect('/supplier/purchase_orders/view.php?id=' . $id);
    }
}

try {
    $pdo->prepare(
        'INSERT INTO supplier_invoices
            (purchase_order_id, invoice_number, invoice_type, invoice_date, due_date,
             total_amount, status, document_id, notes, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $id,
        $invoiceNumber,
        $invoiceType,
        $invoiceDate,
        $dueDate,
        $totalAmount,
        'pending',
        $documentId,
        $notes !== '' ? mb_substr($notes, 0, 500) : null,
        $_SESSION['user_id'],
    ]);
} catch (Exception $e) {
    set_flash('error', 'The invoice could not be submitted. Has config/migrations.sql been run on this database?');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

$adminUsers = $pdo->query("SELECT id FROM users WHERE role IN ('staff', 'admin', 'super_admin') AND status = 'active' AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
foreach ($adminUsers as $adminUserId) {
    create_notification(
        $pdo,
        (int) $adminUserId,
        'Supplier submitted an invoice',
        $supplier['name'] . ' submitted invoice ' . $invoiceNumber . ' (' . money($totalAmount) . ') for purchase order ' . $po['po_number'] . '.',
        '/admin/purchase_orders/view.php?id=' . $id
    );
}

log_activity(
    $pdo,
    $_SESSION['user_id'],
    'supplier_invoice_submitted',
    'Submitted invoice ' . $invoiceNumber . ' for purchase order ' . $po['po_number']
);

set_flash('success', 'Invoice ' . $invoiceNumber . ' submitted for review.');
redirect('/supplier/purchase_orders/view.php?id=' . $id);
