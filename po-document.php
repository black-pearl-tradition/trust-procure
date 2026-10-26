<?php
require_once __DIR__ . '/includes/auth_check.php';

// Deliberately no require_login() here: like payment-proof.php, every denial
// below is a bare 404, so an anonymous hit reveals nothing about the document.

$documentId = (int) ($_GET['id'] ?? 0);
if ($documentId <= 0) {
    http_response_code(404);
    exit;
}

$document = find_po_document($documentId);
if (!$document) {
    http_response_code(404);
    exit;
}

// Staff and administrators can open any document; a supplier only the documents
// attached to its own purchase orders.
$allowed = is_staff_or_above();
if (!$allowed) {
    $supplier = get_supplier_for_user((int) ($_SESSION['user_id'] ?? 0));
    $allowed = $supplier !== null && (int) $supplier['id'] === (int) $document['supplier_id'];
}

if (!$allowed) {
    http_response_code(404);
    exit;
}

$filePath = po_document_file_path((string) $document['stored_name']);
if ($filePath === null) {
    http_response_code(404);
    exit;
}

$downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) $document['original_name']));
if (!is_string($downloadName) || $downloadName === '') {
    $downloadName = 'document';
}

header('Content-Type: ' . ($document['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($filePath));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
