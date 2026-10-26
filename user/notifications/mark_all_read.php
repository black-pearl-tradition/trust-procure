<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/user/notifications/index.php');
}
verify_csrf('/user/notifications/index.php');

$pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$_SESSION['user_id']]);
set_flash('success', 'All notifications marked as read.');
redirect('/user/notifications/index.php');