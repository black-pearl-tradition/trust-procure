<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/messages/index.php');
}
verify_csrf('/admin/messages/index.php');

$id = (int) ($_POST['id'] ?? 0);
$pdo->prepare('DELETE FROM contact_messages WHERE id = ?')->execute([$id]);
log_activity($pdo, $_SESSION['user_id'], 'message_deleted', "Deleted contact message #$id");
set_flash('success', 'Message deleted.');
redirect('/admin/messages/index.php');
