<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_super_admin();

verify_csrf('/admin/system/index.php');

$confirmation = trim((string) ($_POST['confirm_text'] ?? ''));
if ($confirmation !== 'SOFT RESET' || empty($_POST['acknowledge'])) {
    set_flash('error', 'The soft reset confirmation text and acknowledgement are required. No data was changed.');
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
];

foreach ($cleanupQueries as $sql) {
    $pdo->exec($sql);
}

$resetSummary = 'Soft reset: cleared ' . count($cleanupQueries) . ' operational tables while keeping all user accounts intact.';
log_activity($pdo, (int) $_SESSION['user_id'], 'system_soft_reset', $resetSummary);
set_flash('success', 'Soft reset completed. User accounts were preserved, but the operational system data was cleared.');
redirect('/admin/system/index.php');
