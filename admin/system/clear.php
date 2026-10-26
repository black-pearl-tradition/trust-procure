<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_super_admin();

verify_csrf('/admin/system/index.php');

$confirmation = trim((string) ($_POST['confirm_text'] ?? ''));
if ($confirmation !== 'DELETE SYSTEM DATA' || empty($_POST['acknowledge'])) {
    set_flash('error', 'The confirmation text and acknowledgement are required. No data was changed.');
    redirect('/admin/system/index.php');
}

global $pdo;

$cleanupQueries = [
    'DELETE FROM password_resets',
    'DELETE FROM notifications',
    'DELETE FROM order_events',
    'DELETE FROM product_reviews',
    'DELETE FROM contact_messages',
    'DELETE FROM audit_logs',
    'DELETE FROM inventory_movements',
    'DELETE FROM purchase_order_documents',
    'DELETE FROM purchase_order_items',
    'DELETE FROM purchase_orders',
    'DELETE FROM order_items',
    'DELETE FROM payments',
    'DELETE FROM orders',
    'DELETE FROM requests',
    'DELETE FROM product_images',
    'DELETE FROM supplier_products',
    'DELETE FROM promotional_banners',
    'DELETE FROM products',
    'DELETE FROM suppliers',
    'DELETE FROM categories',
    "DELETE FROM users WHERE role <> 'super_admin'",
];

foreach ($cleanupQueries as $sql) {
    $pdo->exec($sql);
}

$resetSummary = 'Full reset: cleared ' . count($cleanupQueries) . ' system tables and removed all non-super-admin accounts.';
log_activity($pdo, (int) $_SESSION['user_id'], 'system_data_cleared', $resetSummary);
set_flash('success', 'System data has been cleared. The super administrator account remains active.');
redirect('/admin/system/index.php');
