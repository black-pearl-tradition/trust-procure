<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_supplier();

$deliveryId = (int) ($_GET['id'] ?? 0);
$supplier = get_supplier_for_user((int) $_SESSION['user_id']);
if (!$supplier) {
    set_flash('error', 'Supplier profile not found.');
    redirect('/supplier/purchase_orders/index.php');
}

$deliveryStmt = $pdo->prepare(
    'SELECT d.*, po.po_number, s.name AS supplier_name
     FROM supplier_deliveries d
     JOIN purchase_orders po ON po.id = d.purchase_order_id
     JOIN suppliers s ON s.id = d.supplier_id
     WHERE d.id = ? AND d.supplier_id = ?'
);
$deliveryStmt->execute([$deliveryId, (int) $supplier['id']]);
$delivery = $deliveryStmt->fetch();
if (!$delivery) {
    set_flash('error', 'Delivery notice not found.');
    redirect('/supplier/purchase_orders/index.php');
}

$itemsStmt = $pdo->prepare(
    'SELECT di.id AS delivery_item_id, di.delivery_details, di.declared_quantity,
            p.name AS product_name, p.sku, p.barcode, p.specifications, p.track_serial_numbers
     FROM supplier_delivery_items di
     JOIN purchase_order_items poi ON poi.id = di.purchase_order_item_id
     JOIN products p ON p.id = poi.product_id
     WHERE di.delivery_id = ?
     ORDER BY di.id'
);
$itemsStmt->execute([$deliveryId]);
$items = $itemsStmt->fetchAll();
$serialStmt = $pdo->prepare(
    'SELECT delivery_item_id, serial_number
     FROM supplier_delivery_serials
     WHERE delivery_item_id IN (
         SELECT id FROM supplier_delivery_items WHERE delivery_id = ?
     )
     ORDER BY id'
);
$serialStmt->execute([$deliveryId]);
$serialsByDeliveryItem = [];
foreach ($serialStmt->fetchAll() as $serial) {
    $serialsByDeliveryItem[(int) $serial['delivery_item_id']][] = $serial['serial_number'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery <?= e($delivery['delivery_code']) ?> — <?= e($delivery['po_number']) ?></title>
    <style>
        body { margin: 0; padding: 28px; background: #f3f5f8; color: #16233f; font-family: Arial, sans-serif; }
        main { max-width: 720px; margin: 0 auto; padding: 32px; background: #fff; border: 1px solid #d9dee7; }
        header { display: flex; justify-content: space-between; gap: 20px; border-bottom: 2px solid #b8863b; padding-bottom: 18px; }
        h1 { margin: 0 0 8px; font-size: 22px; }
        p { margin: 5px 0; }
        .code { margin: 28px 0; padding: 20px; text-align: center; border: 1px dashed #929bad; }
        .digits { margin: 8px 0; font-size: 26px; font-weight: bold; letter-spacing: .2em; }
        svg { max-width: 100%; height: 60px; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; }
        th, td { padding: 10px; border-bottom: 1px solid #e4e8ef; text-align: left; }
        .actions { max-width: 720px; margin: 0 auto 14px; display: flex; justify-content: flex-end; gap: 10px; }
        .actions button, .actions a { padding: 10px 16px; border: 0; border-radius: 6px; background: #16233f; color: #fff; text-decoration: none; cursor: pointer; }
        @media print { body { padding: 0; background: #fff; } main { border: 0; } .actions { display: none; } }
    </style>
</head>
<body>
    <div class="actions">
        <a href="<?= url('/supplier/purchase_orders/view.php?id=' . (int) $delivery['purchase_order_id']) ?>">Back to PO</a>
        <button type="button" onclick="window.print()">Print delivery code</button>
    </div>
    <main>
        <header>
            <div><h1>Supplier delivery notice</h1><p><strong>Purchase order:</strong> <?= e($delivery['po_number']) ?></p></div>
            <div><strong><?= e($delivery['supplier_name']) ?></strong><?php if ($delivery['tracking_reference']): ?><p>Ref: <?= e($delivery['tracking_reference']) ?></p><?php endif; ?></div>
        </header>
        <section class="code">
            <strong>Delivery code — receiving staff use this to verify the shipment</strong>
            <div class="digits"><?= e($delivery['delivery_code']) ?></div>
            <?= supplier_delivery_barcode_markup($delivery['delivery_code']) ?>
            <p>Declaration only. Goods are not received into inventory until verified by the buyer.</p>
        </section>
        <p><strong>Submitted:</strong> <?= display_date($delivery['submitted_at'], 'd M Y H:i') ?></p>
        <p><strong>Expected arrival:</strong> <?= $delivery['expected_delivery_date'] ? display_date($delivery['expected_delivery_date'], 'd M Y') : 'Not specified' ?></p>
        <table>
            <thead><tr><th>Product / identifying details</th><th>Declared quantity</th><th>Unit serials / unique barcodes</th></tr></thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <strong><?= e($item['product_name']) ?></strong>
                            <?php if ($item['sku'] || $item['barcode']): ?><br>SKU <?= e($item['sku'] ?: '—') ?> · product barcode <?= e($item['barcode'] ?: '—') ?><?php endif; ?>
                            <?php if ($item['specifications']): ?><br><?= e($item['specifications']) ?><?php endif; ?>
                            <?php if ($item['delivery_details']): ?><br>Delivery details: <?= e($item['delivery_details']) ?><?php endif; ?>
                        </td>
                        <td><?= (int) $item['declared_quantity'] ?></td>
                        <td><?= e(implode(', ', $serialsByDeliveryItem[(int) $item['delivery_item_id']] ?? [])) ?: '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </main>
</body>
</html>
