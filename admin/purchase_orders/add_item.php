<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/purchase_orders/index.php');
}
verify_csrf('/admin/purchase_orders/index.php');

$poId = (int) ($_POST['po_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND status = 'draft'");
$stmt->execute([$poId]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'This purchase order can no longer be edited.');
    redirect('/admin/purchase_orders/index.php');
}

$productId = (int) ($_POST['product_id'] ?? 0);
$orderId = (int) ($_POST['order_id'] ?? 0);
$sourceSupplierProductId = (int) ($_POST['source_supplier_product_id'] ?? 0);
$quantity = max(1, (int) ($_POST['quantity_ordered'] ?? 0));
$unitCost = (float) ($_POST['unit_cost'] ?? 0);

$productCheck = $pdo->prepare(
    'SELECT p.id FROM products p
     JOIN supplier_products sp ON sp.product_id = p.id AND sp.supplier_id = ?
     WHERE p.id = ?'
);
$productCheck->execute([(int) $po['supplier_id'], $productId]);

if (!$productCheck->fetch() || $unitCost < 0) {
    set_flash('error', 'Choose a product linked to this purchase order supplier and enter a valid cost.');
    redirect('/admin/purchase_orders/view.php?id=' . $poId);
}

if ($sourceSupplierProductId > 0) {
    $sourceCheck = $pdo->prepare(
        'SELECT id FROM supplier_products WHERE id = ? AND supplier_id = ? AND product_id = ?'
    );
    $sourceCheck->execute([$sourceSupplierProductId, (int) $po['supplier_id'], $productId]);
    if (!$sourceCheck->fetch()) {
        $sourceSupplierProductId = 0;
    }
}

$lineTotal = $quantity * $unitCost;

$pdo->prepare(
    'INSERT INTO purchase_order_items (purchase_order_id, product_id, order_id, source_supplier_product_id, quantity_ordered, unit_cost, line_total)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
)->execute([$poId, $productId, $orderId ?: null, $sourceSupplierProductId ?: null, $quantity, $unitCost, $lineTotal]);

recalculate_po_totals($pdo, $poId);
log_activity($pdo, $_SESSION['user_id'], 'po_item_added', "Added item to purchase order #{$po['po_number']}");
set_flash('success', 'Item added.');
redirect('/admin/purchase_orders/view.php?id=' . $poId);
