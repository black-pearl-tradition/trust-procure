<?php

/**
 * Purchase order documents (quotes, invoices, delivery notes).
 *
 * Supplier uploads are stored outside the web root next to the payment proofs
 * and are streamed through po-document.php rather than served directly, so a
 * guessable path can never expose another supplier's paperwork.
 */

require_once __DIR__ . '/security.php';

const PO_DOCUMENT_MAX_BYTES = 10485760; // 10 MB

/** Allowed extension => MIME type pairs for a purchase order document. */
function po_document_allowed_types(): array
{
    return [
        'pdf'   => 'application/pdf',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'png'   => 'image/png',
        'webp'  => 'image/webp',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'php'   => 'application/x-php',
        'txt'   => 'text/plain',
        'csv'   => 'text/csv',
        'doc'   => 'application/msword',
        'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'   => 'application/vnd.ms-excel',
        'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip'   => 'application/zip',
        'rar'   => 'application/vnd.rar',
        'mp4'   => 'video/mp4',
    ];
}

/** Human-readable labels for the document type selector. */
function po_document_type_labels(): array
{
    return [
        'quote'         => 'Quote',
        'invoice'       => 'Invoice',
        'delivery_note' => 'Delivery note',
        'other'         => 'Other',
    ];
}

function po_document_type_label(string $type): string
{
    return po_document_type_labels()[$type] ?? 'Other';
}

/** Absolute path of the private directory holding purchase order documents. */
function po_document_directory(): string
{
    return dirname(dirname(__DIR__)) . '/../tps_private/po_documents';
}

/** Human-readable file size for the document list. */
function po_document_size_label(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }

    return $bytes . ' B';
}

/**
 * Store one uploaded purchase order document and return its metadata.
 * Returns null when no file was submitted; throws RuntimeException on a
 * validation failure so the caller can show the message.
 */
function handle_po_document_upload(array $file): ?array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The document could not be uploaded.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('The document appears to be empty.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmpName)) {
        throw new RuntimeException('The document could not be uploaded.');
    }

    $mimeType = (string) ($file['type'] ?? 'application/octet-stream');
    if ($mimeType === '') {
        $mimeType = 'application/octet-stream';
    }

    $directory = po_document_directory();
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The document could not be saved.');
    }

    // Random stored name: the original filename is only ever shown as a label.
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($tmpName, $directory . '/' . $storedName)) {
        throw new RuntimeException('The document could not be saved.');
    }

    return [
        'stored_name'   => $storedName,
        'original_name' => mb_substr(basename((string) $file['name']), 0, 255),
        'mime_type'     => $mimeType,
        'file_size'     => $size,
    ];
}

/** Load a document together with the supplier that owns its purchase order. */
function find_po_document(int $documentId): ?array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT d.*, po.supplier_id, po.po_number
         FROM purchase_order_documents d
         JOIN purchase_orders po ON po.id = d.purchase_order_id
         WHERE d.id = ?'
    );
    $stmt->execute([$documentId]);

    return $stmt->fetch() ?: null;
}

/** Documents attached to a purchase order, newest first. */
function po_documents_for_order(int $purchaseOrderId): array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT d.*, u.full_name AS uploaded_by_name, s.name AS supplier_name
         FROM purchase_order_documents d
         JOIN users u ON u.id = d.uploaded_by
         LEFT JOIN purchase_orders po ON po.id = d.purchase_order_id
         LEFT JOIN suppliers s ON s.id = po.supplier_id
         WHERE d.purchase_order_id = ?
         ORDER BY d.created_at DESC, d.id DESC'
    );
    $stmt->execute([$purchaseOrderId]);

    return $stmt->fetchAll();
}

/**
 * Absolute path of a stored document, or null when it is missing or when the
 * resolved path escapes the private directory.
 */
function po_document_file_path(string $storedName): ?string
{
    $directory = realpath(po_document_directory());
    if ($directory === false) {
        return null;
    }

    $path = realpath($directory . DIRECTORY_SEPARATOR . basename($storedName));
    if ($path === false || !is_file($path) || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $path;
}
