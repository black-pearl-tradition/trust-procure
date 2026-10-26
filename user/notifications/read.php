<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/user/notifications/index.php');
}
verify_csrf('/user/notifications/index.php');

$notificationId = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT link FROM notifications WHERE id = ? AND user_id = ?');
$stmt->execute([$notificationId, $_SESSION['user_id']]);
$notification = $stmt->fetch();

if (!$notification) {
    set_flash('error', 'Notification not found.');
    redirect('/user/notifications/index.php');
}

$pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
    ->execute([$notificationId, $_SESSION['user_id']]);

$target = trim((string) ($notification['link'] ?? ''));
if ($target !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#)~i', $target)) {
    redirect('/' . ltrim($target, '/'));
}

redirect('/user/notifications/index.php');
