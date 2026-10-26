<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_supplier();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/supplier/notifications/index.php');
}
verify_csrf('/supplier/notifications/index.php');

$pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$_SESSION['user_id']]);
log_activity($pdo, (int) $_SESSION['user_id'], 'supplier_notifications_cleared', 'Cleared all supplier notifications.');
set_flash('success', 'All notifications have been cleared.');
redirect('/supplier/notifications/index.php');
