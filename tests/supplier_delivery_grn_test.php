<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_delivery_grn(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$root = __DIR__ . '/..';
$schema = (string) file_get_contents($root . '/config/schema.sql');
$migration = (string) file_get_contents($root . '/config/migrations.sql');
$migrationRunner = (string) file_get_contents($root . '/config/run_migrations.php');
$helper = (string) file_get_contents($root . '/includes/helpers/supplier.php');
$productAdd = (string) file_get_contents($root . '/admin/products/add.php');
$productEdit = (string) file_get_contents($root . '/admin/products/edit.php');
$supplierView = (string) file_get_contents($root . '/supplier/purchase_orders/view.php');
$supplierSubmit = (string) file_get_contents($root . '/supplier/purchase_orders/submit_delivery.php');
$supplierLabel = (string) file_get_contents($root . '/supplier/purchase_orders/delivery_label.php');
$supplierDecline = (string) file_get_contents($root . '/supplier/purchase_orders/decline.php');
$adminView = (string) file_get_contents($root . '/admin/purchase_orders/view.php');
$send = (string) file_get_contents($root . '/admin/purchase_orders/send.php');
$print = (string) file_get_contents($root . '/admin/purchase_orders/print.php');
$receive = (string) file_get_contents($root . '/admin/purchase_orders/receive.php');
$adminLayout = (string) file_get_contents($root . '/includes/admin_layout_start.php');

assert_delivery_grn(
    str_contains($schema, 'CREATE TABLE supplier_deliveries')
        && str_contains($schema, 'delivery_code VARCHAR(10) NOT NULL')
        && str_contains($schema, 'CREATE TABLE supplier_delivery_items')
        && str_contains($schema, 'delivery_details VARCHAR(500)')
        && str_contains($schema, 'CREATE TABLE supplier_delivery_serials')
        && str_contains($schema, 'track_serial_numbers TINYINT(1)')
        && str_contains($schema, 'accepted_quantity INT UNSIGNED DEFAULT NULL'),
    'The schema should persist supplier declarations separately from receiving inspection/acceptance.'
);
assert_delivery_grn(
    str_contains($schema, 'delivery_id INT DEFAULT NULL')
        && str_contains($schema, 'fk_pod_delivery'),
    'A submitted delivery note should be linked directly to its delivery notice.'
);
assert_delivery_grn(
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS supplier_deliveries')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS supplier_delivery_items')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS supplier_delivery_serials')
        && str_contains($migration, 'ADD COLUMN delivery_details VARCHAR(500)')
        && str_contains($migration, 'ADD COLUMN track_serial_numbers TINYINT(1)')
        && str_contains($migration, 'ADD COLUMN delivery_id INT DEFAULT NULL')
        && str_contains($migration, 'ADD CONSTRAINT fk_pod_delivery'),
    'The migration should safely install delivery, inspection, and document-link schema.'
);
assert_delivery_grn(
    str_contains($migrationRunner, "'supplier_deliveries'")
        && str_contains($migrationRunner, "'supplier_delivery_items'")
        && str_contains($migrationRunner, "'supplier_delivery_serials'")
        && str_contains($migrationRunner, "'products.track_serial_numbers'")
        && str_contains($migrationRunner, "'supplier_delivery_items.delivery_details'")
        && str_contains($migrationRunner, "'purchase_order_documents.delivery_id'"),
    'The migration runner should verify the delivery tables and document relationship.'
);

assert_delivery_grn(
    str_contains($supplierView, 'Submit a delivery notice')
        && str_contains($supplierView, 'name="declared[')
        && str_contains($supplierView, 'supplier_delivery_barcode_markup')
        && str_contains($supplierView, 'delivery_label.php')
        && str_contains($supplierView, 'Only buyer-accepted quantities become stock'),
    'Suppliers should submit declared PO quantities and receive a delivery-specific barcode without changing stock.'
);
assert_delivery_grn(
    str_contains($supplierView, "\$delivery['status'] === 'submitted'")
        && str_contains($supplierView, 'awaiting verification')
        && str_contains($supplierSubmit, 'A delivery notice is already awaiting verification'),
    'A second dispatch notice should wait until the prior delivery is verified.'
);
assert_delivery_grn(
    str_contains($supplierSubmit, 'get_supplier_for_user')
        && str_contains($supplierSubmit, 'supplier_id = ?')
        && str_contains($supplierSubmit, 'JOIN supplier_products sp')
        && str_contains($supplierSubmit, 'random_int(0, 9999999999)')
        && str_contains($supplierSubmit, 'supplier_delivery_items')
        && str_contains($supplierSubmit, 'document_type')
        && str_contains($supplierSubmit, 'delivery_note'),
    'A supplier can only submit an owned PO, using linked items, a unique code, and attached delivery paperwork.'
);
assert_delivery_grn(
    str_contains($supplierSubmit, 'exactly one serial number or unique unit barcode')
        && str_contains($supplierSubmit, 'delivery_details')
        && str_contains($supplierSubmit, 'supplier_delivery_serials')
        && str_contains($productAdd, 'Track a unique serial number')
        && str_contains($supplierView, 'Color / variant details'),
    'Suppliers should declare delivery-specific variants and one identifier per serialized physical unit.'
);
assert_delivery_grn(
    str_contains($productAdd, 'track_serial_numbers')
        && str_contains($productEdit, 'track_serial_numbers')
        && str_contains($productAdd, 'product/model barcode alone is not a unique unit serial'),
    'Staff should be able to distinguish unit-serialized products from products with only a model barcode.'
);
assert_delivery_grn(
    !str_contains($supplierSubmit, 'UPDATE products')
        && !str_contains($supplierSubmit, 'record_inventory_movement('),
    'Supplier submission must not directly change inventory.'
);
assert_delivery_grn(
    str_contains($supplierLabel, 'supplier_id = ?')
        && str_contains($supplierLabel, 'Print delivery code')
        && str_contains($supplierLabel, 'supplier_delivery_barcode_markup')
        && str_contains($supplierLabel, 'serial_number')
        && str_contains($supplierLabel, 'delivery_details'),
    'Suppliers should be able to print their own code, item details, quantities, and unit identifiers.'
);
assert_delivery_grn(
    str_contains($supplierDecline, "status = 'submitted'")
        && str_contains($supplierDecline, 'awaiting verification'),
    'A supplier cannot decline a PO while a delivery notice is awaiting verification.'
);

assert_delivery_grn(
    !str_contains($send, 'Goods receipt code')
        && !str_contains($print, 'Goods receipt code')
        && !str_contains($adminView, 'ensure_po_receiving_code'),
    'A generic PO code should no longer be presented as a delivery code.'
);
assert_delivery_grn(
    str_contains($adminView, 'supplier_deliveries')
        && str_contains($adminView, 'GRN-')
        && str_contains($adminView, 'Inspection results')
        && str_contains($adminView, '/admin/purchase_orders/receive.php?code='),
    'The buyer PO view should show pending deliveries, verified GRNs, and their discrepancy results.'
);
assert_delivery_grn(
    str_contains($adminLayout, "'label' => 'Goods Receiving'")
        && str_contains($adminLayout, "'href' => '/admin/purchase_orders/receive.php'"),
    'Receiving staff should have a navigation entry to the delivery code scanner.'
);

assert_delivery_grn(
    str_contains($receive, "preg_match('/^\\d{10}$/', \$code)")
        && str_contains($receive, 'd.status = \'submitted\'')
        && str_contains($receive, 'supplier_delivery_items')
        && str_contains($receive, 'receipt[<?= (int) $item[\'id\'] ?>][serials]')
        && str_contains($receive, 'inspection_status = ?')
        && str_contains($receive, 'delivery_details')
        && str_contains($receive, 'JOIN supplier_products sp')
        && str_contains($receive, 'View document'),
    'Scanning should load only a submitted delivery, its authorized supplier products, and its delivery note.'
);
assert_delivery_grn(
    str_contains($receive, 'Physically received')
        && str_contains($receive, 'Accepted into stock')
        && str_contains($receive, 'Rejected / damaged')
        && str_contains($receive, 'discrepancy')
        && str_contains($receive, 'inspection_note'),
    'The receiver should distinguish physical, accepted, rejected, and discrepancy data.'
);
assert_delivery_grn(
    str_contains($receive, 'not_received')
        && str_contains($receive, 'Received, accepted, and rejected quantities are calculated from these selections')
        && str_contains($adminView, 'Unit <?= e($serial[\'serial_number\']) ?>')
        && str_contains($supplierView, 'Unit <?= e($serial[\'serial_number\']) ?>'),
    'Receiving staff should verify each serialized unit and both portals should retain the per-unit GRN outcome.'
);
assert_delivery_grn(
    str_contains($receive, 'beginTransaction()')
        && str_contains($receive, 'FOR UPDATE')
        && str_contains($receive, '$accepted + $rejected !== $received')
        && str_contains($receive, '$accepted > $remaining')
        && str_contains($receive, 'stock_quantity = stock_quantity + ?')
        && str_contains($receive, 'record_inventory_movement(')
        && str_contains($receive, "status = 'verified'")
        && str_contains($receive, 'GRN-'),
    'Only verified accepted quantities should update stock in a locked transaction and create a GRN.'
);
assert_delivery_grn(
    str_contains($receive, 'notify_supplier(')
        && str_contains($receive, 'was verified with quantity or condition discrepancies'),
    'The supplier should be notified after verification, including when variances were found.'
);

$barcode = supplier_delivery_barcode_markup('0012345678');
assert_delivery_grn(str_contains($barcode, '<svg') && str_contains($barcode, '0012345678'), 'A valid delivery code should render as a barcode.');
assert_delivery_grn(supplier_delivery_barcode_markup('123') === '', 'An invalid delivery code should not render as a barcode.');

echo "Supplier delivery and GRN tests passed.\n";
