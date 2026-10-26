<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/suppliers/index.php');
}

$supplierId = (int) ($_POST['supplier_id'] ?? 0);
verify_csrf('/admin/suppliers/view.php?id=' . $supplierId);

$id = (int) ($_POST['id'] ?? 0);
$pdo->prepare('DELETE FROM supplier_products WHERE id = ? AND supplier_id = ?')->execute([$id, $supplierId]);
log_activity($pdo, $_SESSION['user_id'], 'supplier_product_unlinked', "Removed supplier_product link #$id");
set_flash('success', 'Product removed from this supplier.');
redirect('/admin/suppliers/view.php?id=' . $supplierId);
