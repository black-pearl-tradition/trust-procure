<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/purchase_orders/index.php');
}

verify_csrf('/admin/purchase_orders/index.php');

$sourceId = (int) ($_POST['id'] ?? 0);
if ($sourceId <= 0) {
    set_flash('error', 'Invalid purchase order.');
    redirect('/admin/purchase_orders/index.php');
}

$stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
$stmt->execute([$sourceId]);
$sourcePo = $stmt->fetch();

if (!$sourcePo) {
    set_flash('error', 'Source purchase order not found.');
    redirect('/admin/purchase_orders/index.php');
}

// Fetch line items
$itemStmt = $pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY id ASC');
$itemStmt->execute([$sourceId]);
$sourceItems = $itemStmt->fetchAll();

if (!$sourceItems) {
    set_flash('error', 'Cannot duplicate a purchase order with no items.');
    redirect('/admin/purchase_orders/view.php?id=' . $sourceId);
}

try {
    $pdo->beginTransaction();

    $newPoNumber = generate_po_number($pdo);
    $note = 'Cloned from PO ' . $sourcePo['po_number'];
    if (!empty($sourcePo['notes'])) {
        $note .= ' — ' . $sourcePo['notes'];
    }

    $insertPo = $pdo->prepare(
        "INSERT INTO purchase_orders (po_number, supplier_id, notes, created_by)
         VALUES (?, ?, ?, ?)"
    );
    $insertPo->execute([$newPoNumber, $sourcePo['supplier_id'], $note, $_SESSION['user_id']]);
    $newPoId = (int) $pdo->lastInsertId();

    $insertItem = $pdo->prepare(
        "INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity_ordered, unit_cost, line_total)
         VALUES (?, ?, ?, ?, ?)"
    );

    foreach ($sourceItems as $it) {
        $insertItem->execute([
            $newPoId,
            $it['product_id'],
            $it['quantity_ordered'],
            $it['unit_cost'],
            $it['line_total'],
        ]);
    }

    recalculate_po_totals($pdo, $newPoId);

    log_activity($pdo, $_SESSION['user_id'], 'po_created', "Cloned PO $newPoNumber from " . $sourcePo['po_number']);

    $pdo->commit();

    set_flash('success', "Draft purchase order $newPoNumber created from " . $sourcePo['po_number'] . ".");
    redirect('/admin/purchase_orders/view.php?id=' . $newPoId);
} catch (Throwable $e) {
    $pdo->rollBack();
    set_flash('error', 'Failed to duplicate purchase order: ' . $e->getMessage());
    redirect('/admin/purchase_orders/view.php?id=' . $sourceId);
}
