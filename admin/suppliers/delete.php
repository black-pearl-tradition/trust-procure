<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/suppliers/index.php');
}
verify_csrf('/admin/suppliers/index.php');

$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT name FROM suppliers WHERE id = ?');
$stmt->execute([$id]);
$supplier = $stmt->fetch();

if (!$supplier) {
    set_flash('error', 'Supplier not found.');
    redirect('/admin/suppliers/index.php');
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM supplier_products WHERE supplier_id = ?');
$stmt->execute([$id]);
$productCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE supplier_id = ?');
$stmt->execute([$id]);
$orderCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?');
$stmt->execute([$id]);
$poCount = (int) $stmt->fetchColumn();

if ($productCount > 0 || $orderCount > 0 || $poCount > 0) {
    set_flash('error', 'This supplier has linked products, order history, or purchase orders and cannot be deleted. Deactivate it instead.');
    redirect('/admin/suppliers/view.php?id=' . $id);
}

$pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
log_activity($pdo, $_SESSION['user_id'], 'supplier_deleted', "Deleted supplier: {$supplier['name']}");
set_flash('success', 'Supplier deleted.');
redirect('/admin/suppliers/index.php');
