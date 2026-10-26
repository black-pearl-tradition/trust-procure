<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/user/notifications/index.php');
}
verify_csrf('/user/notifications/index.php');

$pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$_SESSION['user_id']]);
log_activity($pdo, $_SESSION['user_id'], 'notifications_cleared', 'Cleared all notifications.');
set_flash('success', 'All notifications have been cleared.');
redirect('/user/notifications/index.php');