<?php
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
     WHERE po.id = ? AND po.supplier_id = ? AND po.status <> 'cancelled'"
);
$stmt->execute([$id, $supplier['id']]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'Purchase order not found, or it can no longer take documents.');
    redirect('/supplier/purchase_orders/index.php');
}

$type = (string) ($_POST['document_type'] ?? 'other');
if (!array_key_exists($type, po_document_type_labels())) {
    $type = 'other';
}

try {
    $document = handle_po_document_upload($_FILES['document'] ?? []);
} catch (RuntimeException $e) {
    set_flash('error', $e->getMessage());
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

if ($document === null) {
    set_flash('error', 'Choose a file to upload.');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

try {
    $pdo->prepare(
        'INSERT INTO purchase_order_documents
            (purchase_order_id, uploaded_by, document_type, original_name, stored_name, mime_type, file_size)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $id,
        $_SESSION['user_id'],
        $type,
        $document['original_name'],
        $document['stored_name'],
        $document['mime_type'],
        $document['file_size'],
    ]);
} catch (Exception $e) {
    // The file is already on disk at this point, so drop it again rather than
    // leaving an orphan behind when the record cannot be written — e.g. when the
    // purchase_order_documents table has not been created by the migration yet.
    $orphan = po_document_file_path((string) $document['stored_name']);
    if ($orphan !== null) {
        // Best effort: a locked file must not stop the redirect below.
        @unlink($orphan);
    }

    set_flash('error', 'The document could not be saved. Has config/migrations.sql been run on this database?');
    redirect('/supplier/purchase_orders/view.php?id=' . $id);
}

$adminUsers = $pdo->query("SELECT id FROM users WHERE role IN ('staff', 'admin', 'super_admin') AND status = 'active' AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
foreach ($adminUsers as $adminUserId) {
    create_notification(
        $pdo,
        (int) $adminUserId,
        'Supplier uploaded a document',
        'Supplier uploaded a ' . strtolower(po_document_type_label($type)) . ' for purchase order #' . $po['po_number'] . '.',
        '/admin/purchase_orders/view.php?id=' . $id
    );
}

log_activity($pdo, $_SESSION['user_id'], 'supplier_po_document_uploaded', 'Uploaded a supplier document for purchase order #' . $po['po_number']);
set_flash('success', po_document_type_label($type) . ' uploaded.');
redirect('/supplier/purchase_orders/view.php?id=' . $id);
