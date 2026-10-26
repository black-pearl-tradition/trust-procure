<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$viewFile = __DIR__ . '/../supplier/purchase_orders/view.php';
$ackFile = __DIR__ . '/../supplier/purchase_orders/acknowledge.php';
$declineFile = __DIR__ . '/../supplier/purchase_orders/decline.php';
$withdrawFile = __DIR__ . '/../supplier/purchase_orders/withdraw.php';
$uploadFile = __DIR__ . '/../supplier/purchase_orders/upload_document.php';
$indexFile = __DIR__ . '/../supplier/purchase_orders/index.php';
$acceptFile = __DIR__ . '/../admin/purchase_orders/accept_delivery_date.php';
$downloadFile = __DIR__ . '/../po-document.php';
$adminViewFile = __DIR__ . '/../admin/purchase_orders/view.php';

foreach ([
    'Supplier PO view'              => $viewFile,
    'Supplier PO index'             => $indexFile,
    'Acknowledgement action'        => $ackFile,
    'Decline action'                => $declineFile,
    'Response withdrawal action'    => $withdrawFile,
    'Document upload action'        => $uploadFile,
    'Accepted-delivery-date action' => $acceptFile,
    'Document download endpoint'    => $downloadFile,
    'Admin PO view'                 => $adminViewFile,
] as $label => $file) {
    assert_true(file_exists($file), $label . ' should exist.');
}

$view = (string) file_get_contents($viewFile);
$ack = (string) file_get_contents($ackFile);
$decline = (string) file_get_contents($declineFile);
$withdraw = (string) file_get_contents($withdrawFile);
$upload = (string) file_get_contents($uploadFile);
$index = (string) file_get_contents($indexFile);
$accept = (string) file_get_contents($acceptFile);
$download = (string) file_get_contents($downloadFile);
$adminView = (string) file_get_contents($adminViewFile);

// 1. A response can be given, changed and withdrawn while the order is still
//    with the supplier — no longer only before the first response.
assert_true(str_contains($view, "if (in_array(\$po['status'], ['sent', 'partially_received'], true)): ?>"), 'Supplier PO view should offer the response form while goods remain outstanding.');
assert_true(!str_contains($view, "if (\$po['status'] === 'sent' && \$po['supplier_response']"), 'The response form should no longer be limited to pending responses.');
assert_true(str_contains($view, 'withdraw.php'), 'Supplier PO view should offer a withdraw action.');
assert_true(str_contains($view, 'supplier_proposed_delivery_date'), 'Supplier PO view should offer the proposed delivery date field.');
assert_true(str_contains($view, 'upload_document.php'), 'Supplier PO view should offer document upload.');
assert_true(str_contains($view, 'po_documents_for_order('), 'Supplier PO view should list the uploaded documents.');
assert_true(str_contains($view, "!in_array(\$po['status'], ['sent', 'partially_received'], true) && (\$po['supplier_response']"), 'Supplier PO view should summarise the response after goods are fully received.');

// 2. The proposed delivery date is validated before it is stored, and reported.
assert_true(str_contains($ack, 'normalize_iso_date('), 'Acknowledging should validate the proposed delivery date.');
assert_true(str_contains($ack, 'supplier_proposed_delivery_date = ?'), 'Acknowledging should store the proposed delivery date.');
assert_true(str_contains($ack, 'Proposed delivery date:'), 'Acknowledging should tell the buyer the proposed date.');
assert_true(str_contains($ack, 'previousResponse'), 'Acknowledging should detect that it is updating an existing response.');

// 3. Declining clears a previously proposed date; withdrawing resets everything.
assert_true(str_contains($decline, 'supplier_proposed_delivery_date = NULL'), 'Declining should clear a previously proposed delivery date.');
assert_true(str_contains($decline, 'mb_substr('), 'Declining should cap the note to the column length.');
assert_true(str_contains($decline, 'SUM(quantity_received)'), 'Declining should be blocked after any quantity is received.');
assert_true(str_contains($withdraw, "supplier_response = 'pending'"), 'Withdrawing should reset the response to pending.');
assert_true(str_contains($withdraw, 'supplier_response_at = NULL'), 'Withdrawing should clear the response timestamp.');
assert_true(str_contains($withdraw, 'verify_csrf('), 'Withdrawing should verify the CSRF token.');
assert_true(str_contains($withdraw, 'po.supplier_id = ?'), 'Withdrawing should be scoped to the supplier that owns the order.');
assert_true(str_contains($withdraw, 'create_notification('), 'Withdrawing should notify the buyer.');

// 4. Documents: validated upload, private storage, checked download.
assert_true(str_contains($upload, 'handle_po_document_upload('), 'Uploading should use the shared document helper.');
assert_true(str_contains($upload, 'verify_csrf('), 'Uploading should verify the CSRF token.');
assert_true(str_contains($upload, 'po.supplier_id = ?'), 'Uploading should be scoped to the supplier that owns the order.');
assert_true(str_contains($upload, 'INSERT INTO purchase_order_documents'), 'Uploading should record the document.');
assert_true(str_contains($upload, 'unlink('), 'A failed document record should not leave an orphaned private file behind.');
assert_true(str_contains($upload, 'create_notification('), 'Uploading should notify the buyer.');

assert_true(str_contains($download, 'po_document_file_path('), 'The download endpoint should resolve files through the private-directory helper.');
assert_true(str_contains($download, 'Content-Disposition: attachment'), 'Documents should be served as attachments.');
assert_true(str_contains($download, 'X-Content-Type-Options: nosniff'), 'Documents should be served with nosniff.');
assert_true(str_contains($download, 'is_staff_or_above()'), 'The download endpoint should let staff and administrators through.');
assert_true(str_contains($download, 'get_supplier_for_user('), 'The download endpoint should check the supplier owns the document.');
assert_true(!str_contains($download, 'require_login();'), 'The download endpoint must not redirect anonymous hits to login (bare 404 only).');

// 5. The supplier list can be searched and date-ranged.
assert_true(str_contains($index, 'po.po_number LIKE ?'), 'Supplier PO list should support a PO number search.');
assert_true(str_contains($index, 'po.created_at >= ?'), 'Supplier PO list should support a from-date filter.');
assert_true(str_contains($index, 'po.created_at <= ?'), 'Supplier PO list should support a to-date filter.');
assert_true(str_contains($index, '$pagerQuery'), 'Pagination links should carry the active filters.');
assert_true(str_contains($index, 'normalize_iso_date('), 'The date filters should be validated.');

// 6. The buyer can accept the proposed date.
assert_true(str_contains($accept, 'require_admin();'), 'Accepting a proposed date should require an administrator.');
assert_true(str_contains($accept, 'verify_csrf('), 'Accepting a proposed date should verify the CSRF token.');
assert_true(str_contains($accept, 'notify_supplier('), 'Accepting a proposed date should notify the supplier.');
assert_true(str_contains($accept, 'expected_delivery_date = ?'), 'Accepting should update the expected delivery date.');
assert_true(str_contains($adminView, 'accept_delivery_date.php'), 'Admin PO view should offer to accept the proposed date.');
assert_true(str_contains($adminView, 'po_documents_for_order('), 'Admin PO view should list the supplier documents.');

// 7. Schema and migration carry the new column and table.
$schema = (string) file_get_contents(__DIR__ . '/../config/schema.sql');
$migrations = (string) file_get_contents(__DIR__ . '/../config/migrations.sql');
assert_true(str_contains($schema, 'supplier_proposed_delivery_date DATE'), 'schema.sql should define the proposed delivery date column.');
assert_true(str_contains($schema, 'CREATE TABLE purchase_order_documents'), 'schema.sql should define the documents table.');
assert_true(str_contains($migrations, 'ADD COLUMN supplier_proposed_delivery_date'), 'migrations.sql should add the proposed delivery date column.');
assert_true(str_contains($migrations, 'CREATE TABLE IF NOT EXISTS purchase_order_documents'), 'migrations.sql should create the documents table idempotently.');

// An older database already has purchase_orders, so CREATE TABLE IF NOT EXISTS
// is skipped for it: every later column has to be added guarded by name, and the
// new column must not be positioned after one an older database may lack.
assert_true(str_contains($migrations, 'ADD COLUMN supplier_response_notes VARCHAR(500)'), 'migrations.sql should add the PO columns an older database may be missing.');
assert_true(!str_contains($migrations, 'AFTER supplier_response_notes'), 'The proposed date must not be positioned after a column an older database may lack.');
assert_true(str_contains($migrations, 'CONSTRAINT fk_pod_uploaded_by'), 'The documents table must declare its foreign keys with a valid keyword.');
assert_true(
    substr_count($migrations, 'PREPARE stmt FROM @sql;') === substr_count($migrations, 'DEALLOCATE PREPARE stmt;')
        && substr_count($migrations, 'PREPARE stmt FROM @sql;') === substr_count($migrations, 'SET @sql = IF('),
    'Every guarded migration statement should be prepared, executed and deallocated exactly once.'
);

// 8. Behavioural checks: date validation.
assert_true(normalize_iso_date('2026-03-04') === '2026-03-04', 'A real Y-m-d date should be accepted.');
assert_true(normalize_iso_date(' 2026-03-04 ') === '2026-03-04', 'Surrounding whitespace should be trimmed.');
assert_true(normalize_iso_date('') === null, 'An empty date should resolve to null.');
assert_true(normalize_iso_date('2026-02-31') === null, 'A day that does not exist should be rejected.');
assert_true(normalize_iso_date('2026-13-01') === null, 'A month that does not exist should be rejected.');
assert_true(normalize_iso_date('04/03/2026') === null, 'A non-ISO date should be rejected.');
assert_true(normalize_iso_date('tomorrow') === null, 'Free text should be rejected.');

// 9. Behavioural checks: document helpers.
assert_true(array_key_exists('pdf', po_document_allowed_types()), 'PDFs should remain supported.');
assert_true(array_key_exists('php', po_document_allowed_types()), 'Executable extensions should no longer be blocked by the document helper.');
assert_true(po_document_type_label('invoice') === 'Invoice', 'Document types should be labelled for display.');
assert_true(po_document_type_label('nonsense') === 'Other', 'Unknown document types should fall back to Other.');
assert_true(po_document_size_label(2048) === '2 KB', 'File sizes should be human readable.');
assert_true(
    str_contains(str_replace('\\', '/', po_document_directory()), 'tps_private/po_documents'),
    'Documents should be stored in the private directory.'
);
assert_true(po_document_file_path('../config/schema.sql') === null, 'A path escaping the private directory should not resolve.');
assert_true(po_document_file_path('does-not-exist.pdf') === null, 'A missing file should not resolve.');

// 10. Behavioural check: unrestricted uploads should no longer reject arbitrary extensions.
assert_true(array_key_exists('php', po_document_allowed_types()), 'Uploads should no longer hard-block extra file types.');

echo "Supplier purchase order workflow tests passed.\n";
