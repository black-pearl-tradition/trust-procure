<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/notifications/index.php');
}
verify_csrf('/admin/notifications/index.php');

$pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$_SESSION['user_id']]);
set_flash('success', 'All notifications marked as read.');
redirect('/admin/notifications/index.php');