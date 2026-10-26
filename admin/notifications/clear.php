<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/notifications/index.php');
}
verify_csrf('/admin/notifications/index.php');

$pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$_SESSION['user_id']]);
log_activity($pdo, (int) $_SESSION['user_id'], 'admin_notifications_cleared', 'Cleared all admin notifications.');
set_flash('success', 'All notifications have been cleared.');
redirect('/admin/notifications/index.php');
