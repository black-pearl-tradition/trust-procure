<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/suppliers/index.php');
}

$supplierId = (int) ($_POST['supplier_id'] ?? 0);
verify_csrf('/admin/suppliers/view.php?id=' . $supplierId);

$productId = (int) ($_POST['product_id'] ?? 0);
$warrantyCovered = isset($_POST['warranty_covered']) ? 1 : 0;
$leadTimeDays = $_POST['lead_time_days'] !== '' ? (int) $_POST['lead_time_days'] : null;
$notes = trim($_POST['notes'] ?? '');

$stmt = $pdo->prepare('SELECT id FROM suppliers WHERE id = ?');
$stmt->execute([$supplierId]);
if (!$stmt->fetch() || !$productId) {
    set_flash('error', 'Please choose a valid product.');
    redirect('/admin/suppliers/view.php?id=' . $supplierId);
}

// Pricing is admin/super_admin only — staff can link a product to a
// supplier (warranty, lead time, notes), but never sets or changes cost.
$stmt = $pdo->prepare('SELECT id FROM supplier_products WHERE supplier_id = ? AND product_id = ?');
$stmt->execute([$supplierId, $productId]);
$existing = $stmt->fetch();

if ($existing) {
    if (is_admin()) {
        $priceInput = $_POST['supplier_price'] ?? '';
        $price = $priceInput !== '' ? (float) $priceInput : null;
        $stmt = $pdo->prepare(
            'UPDATE supplier_products SET warranty_covered=?, lead_time_days=?, notes=?, supplier_price=? WHERE id=?'
        );
        $stmt->execute([$warrantyCovered, $leadTimeDays, $notes ?: null, $price, $existing['id']]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE supplier_products SET warranty_covered=?, lead_time_days=?, notes=? WHERE id=?'
        );
        $stmt->execute([$warrantyCovered, $leadTimeDays, $notes ?: null, $existing['id']]);
    }
    log_activity($pdo, $_SESSION['user_id'], 'supplier_product_updated', "Updated product #$productId link for supplier #$supplierId");
    set_flash('success', 'Supplier/product link updated.');
} else {
    $price = null;
    if (is_admin()) {
        $priceInput = $_POST['supplier_price'] ?? '';
        $price = $priceInput !== '' ? (float) $priceInput : null;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO supplier_products (supplier_id, product_id, supplier_price, warranty_covered, lead_time_days, notes) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$supplierId, $productId, $price, $warrantyCovered, $leadTimeDays, $notes ?: null]);
    log_activity($pdo, $_SESSION['user_id'], 'supplier_product_linked', "Linked product #$productId to supplier #$supplierId");
    set_flash('success', 'Product linked to supplier.');
}

redirect('/admin/suppliers/view.php?id=' . $supplierId);
