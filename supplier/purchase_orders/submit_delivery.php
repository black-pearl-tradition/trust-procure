<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_supplier();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/supplier/purchase_orders/index.php');
}

$poId = (int) ($_POST['id'] ?? 0);
verify_csrf('/supplier/purchase_orders/view.php?id=' . $poId);

$supplier = get_supplier_for_user((int) $_SESSION['user_id']);
if (!$supplier) {
    set_flash('error', 'Supplier profile not found.');
    redirect('/supplier/purchase_orders/index.php');
}

$expectedDateRaw = trim((string) ($_POST['expected_delivery_date'] ?? ''));
$expectedDate = $expectedDateRaw === '' ? null : normalize_iso_date($expectedDateRaw);
$trackingReference = mb_substr(trim((string) ($_POST['tracking_reference'] ?? '')), 0, 100);
$supplierNotes = mb_substr(trim((string) ($_POST['supplier_notes'] ?? '')), 0, 500);
$declaredInput = $_POST['declared'] ?? null;
$detailsInput = $_POST['details'] ?? [];
$serialsInput = $_POST['serials'] ?? [];

if ($expectedDateRaw !== '' && $expectedDate === null) {
    set_flash('error', 'Enter a valid expected delivery date.');
    redirect('/supplier/purchase_orders/view.php?id=' . $poId);
}
if (!is_array($declaredInput) || !$declaredInput) {
    set_flash('error', 'Enter the quantities included in this delivery notice.');
    redirect('/supplier/purchase_orders/view.php?id=' . $poId);
}
if (!is_array($detailsInput) || !is_array($serialsInput)) {
    set_flash('error', 'Delivery item details were submitted in an invalid format.');
    redirect('/supplier/purchase_orders/view.php?id=' . $poId);
}

try {
    $document = handle_po_document_upload($_FILES['delivery_note'] ?? []);
} catch (RuntimeException $exception) {
    set_flash('error', $exception->getMessage());
    redirect('/supplier/purchase_orders/view.php?id=' . $poId);
}

$removeUploadedDocument = static function () use (&$document): void {
    if ($document !== null) {
        $path = po_document_file_path((string) $document['stored_name']);
        if ($path !== null) {
            @unlink($path);
        }
    }
};

$pdo->beginTransaction();
try {
    $poStmt = $pdo->prepare(
        "SELECT id, po_number, status, supplier_response
         FROM purchase_orders
         WHERE id = ? AND supplier_id = ? FOR UPDATE"
    );
    $poStmt->execute([$poId, (int) $supplier['id']]);
    $po = $poStmt->fetch();
    if (
        !$po
        || !in_array($po['status'], ['sent', 'partially_received'], true)
        || $po['supplier_response'] === 'declined'
    ) {
        throw new RuntimeException('This purchase order is not open for a delivery notice.');
    }

    $pendingStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM supplier_deliveries
         WHERE purchase_order_id = ? AND status = 'submitted'"
    );
    $pendingStmt->execute([$poId]);
    if ((int) $pendingStmt->fetchColumn() > 0) {
        throw new RuntimeException('A delivery notice is already awaiting verification. Wait for the buyer to verify it before submitting another.');
    }

    $itemStmt = $pdo->prepare(
        'SELECT poi.id, poi.quantity_ordered, poi.quantity_received, p.name,
                p.id AS product_id, p.track_serial_numbers
         FROM purchase_order_items poi
         JOIN products p ON p.id = poi.product_id
         JOIN supplier_products sp ON sp.product_id = poi.product_id AND sp.supplier_id = ?
         WHERE poi.purchase_order_id = ?
         ORDER BY poi.id FOR UPDATE'
    );
    $itemStmt->execute([(int) $supplier['id'], $poId]);
    $items = $itemStmt->fetchAll();
    $itemsById = array_column($items, null, 'id');
    $lineCountStmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = ?');
    $lineCountStmt->execute([$poId]);
    if ((int) $lineCountStmt->fetchColumn() !== count($items)) {
        throw new RuntimeException('This purchase order contains a product that is not linked to your supplier profile. Contact the buyer.');
    }

    $declaredItems = [];
    foreach ($declaredInput as $itemId => $quantityRaw) {
        if (!ctype_digit((string) $itemId) || !isset($itemsById[(int) $itemId])) {
            throw new RuntimeException('The delivery notice includes an item that is not on this purchase order.');
        }
        if (
            !is_scalar($quantityRaw)
            || !preg_match('/^\d+$/', (string) $quantityRaw)
            || (float) $quantityRaw > 4294967295
        ) {
            throw new RuntimeException('Enter whole-number quantities for each item.');
        }
        $quantity = (int) $quantityRaw;
        if ($quantity === 0) {
            continue;
        }
        $item = $itemsById[(int) $itemId];
        $remaining = (int) $item['quantity_ordered'] - (int) $item['quantity_received'];
        if ($quantity > $remaining) {
            throw new RuntimeException('The declared quantity for ' . $item['name'] . ' exceeds the outstanding PO quantity of ' . $remaining . '.');
        }
        $detailsRaw = $detailsInput[$itemId] ?? '';
        if (!is_scalar($detailsRaw)) {
            throw new RuntimeException('Enter valid color or variant details for ' . $item['name'] . '.');
        }
        $details = mb_substr(trim((string) $detailsRaw), 0, 500);
        $serialsRaw = $serialsInput[$itemId] ?? '';
        if (!is_scalar($serialsRaw)) {
            throw new RuntimeException('Enter serial numbers as one value per line for ' . $item['name'] . '.');
        }
        $serialNumbers = [];
        if ((bool) $item['track_serial_numbers']) {
            $serialNumbers = preg_split('/\R/u', trim((string) $serialsRaw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $serialNumbers = array_map(static fn(string $serial): string => trim($serial), $serialNumbers);
            if (count($serialNumbers) !== $quantity) {
                throw new RuntimeException('Enter exactly one serial number or unique unit barcode for each of the ' . $quantity . ' ' . $item['name'] . ' unit(s).');
            }
            $seenSerials = [];
            foreach ($serialNumbers as $serialNumber) {
                if ($serialNumber === '' || mb_strlen($serialNumber) > 100) {
                    throw new RuntimeException('Each unit identifier for ' . $item['name'] . ' must contain 1 to 100 characters.');
                }
                $serialKey = mb_strtolower($serialNumber);
                if (isset($seenSerials[$serialKey])) {
                    throw new RuntimeException('The same unit identifier was entered more than once for ' . $item['name'] . '.');
                }
                $seenSerials[$serialKey] = true;
            }
        } elseif (trim((string) $serialsRaw) !== '') {
            throw new RuntimeException('This product is not configured for individual serial tracking.');
        }
        $declaredItems[(int) $itemId] = [
            'quantity' => $quantity,
            'details' => $details !== '' ? $details : null,
            'product_id' => (int) $item['product_id'],
            'serials' => $serialNumbers,
        ];
    }
    if (!$declaredItems) {
        throw new RuntimeException('Enter a quantity greater than zero for at least one item.');
    }

    $deliveryId = 0;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $deliveryCode = sprintf('%010d', random_int(0, 9999999999));
        try {
            $pdo->prepare(
                'INSERT INTO supplier_deliveries
                    (purchase_order_id, supplier_id, delivery_code, expected_delivery_date,
                     tracking_reference, supplier_notes, submitted_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $poId,
                (int) $supplier['id'],
                $deliveryCode,
                $expectedDate,
                $trackingReference !== '' ? $trackingReference : null,
                $supplierNotes !== '' ? $supplierNotes : null,
                (int) $_SESSION['user_id'],
            ]);
            $deliveryId = (int) $pdo->lastInsertId();
            break;
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
        }
    }
    if ($deliveryId === 0) {
        throw new RuntimeException('Could not create a unique delivery code. Please try again.');
    }

    $deliveryItemStmt = $pdo->prepare(
        'INSERT INTO supplier_delivery_items
            (delivery_id, purchase_order_item_id, declared_quantity, delivery_details)
         VALUES (?, ?, ?, ?)'
    );
    $serialStmt = $pdo->prepare(
        'INSERT INTO supplier_delivery_serials (delivery_item_id, product_id, serial_number)
         VALUES (?, ?, ?)'
    );
    foreach ($declaredItems as $itemId => $declaredItem) {
        $deliveryItemStmt->execute([
            $deliveryId,
            $itemId,
            $declaredItem['quantity'],
            $declaredItem['details'],
        ]);
        $deliveryItemId = (int) $pdo->lastInsertId();
        foreach ($declaredItem['serials'] as $serialNumber) {
            try {
                $serialStmt->execute([$deliveryItemId, $declaredItem['product_id'], $serialNumber]);
            } catch (PDOException $exception) {
                if (($exception->errorInfo[1] ?? null) === 1062) {
                    throw new RuntimeException('Unit identifier ' . $serialNumber . ' has already been recorded for this product.');
                }
                throw $exception;
            }
        }
    }

    if ($document !== null) {
        $pdo->prepare(
            'INSERT INTO purchase_order_documents
                (purchase_order_id, delivery_id, uploaded_by, document_type, original_name, stored_name, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $poId,
            $deliveryId,
            (int) $_SESSION['user_id'],
            'delivery_note',
            $document['original_name'],
            $document['stored_name'],
            $document['mime_type'],
            $document['file_size'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $removeUploadedDocument();
    set_flash(
        'error',
        $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'The delivery notice could not be saved. Check that the latest database migrations are applied.'
    );
    redirect('/supplier/purchase_orders/view.php?id=' . $poId);
}

$adminUsers = $pdo->query(
    "SELECT id FROM users
     WHERE role IN ('staff', 'admin', 'super_admin')
       AND status = 'active' AND deleted_at IS NULL"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($adminUsers as $adminUserId) {
    create_notification(
        $pdo,
        (int) $adminUserId,
        'Supplier delivery notice submitted',
        'Delivery ' . $deliveryCode . ' for purchase order #' . $po['po_number'] . ' is awaiting verification.',
        '/admin/purchase_orders/receive.php?code=' . rawurlencode($deliveryCode)
    );
}

log_activity(
    $pdo,
    (int) $_SESSION['user_id'],
    'supplier_delivery_submitted',
    'Submitted delivery notice ' . $deliveryCode . ' for purchase order #' . $po['po_number']
);
set_flash('success', 'Delivery notice submitted. Give receiving staff this code: ' . $deliveryCode . '. Inventory will not update until they verify it.');
redirect('/supplier/purchase_orders/view.php?id=' . $poId);
