<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/users/index.php');
}
verify_csrf('/admin/users/index.php');

$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ? AND deleted_at IS NOT NULL');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    set_flash('error', 'Deleted user not found.');
    redirect('/admin/users/index.php?view=deleted');
}

$pdo->prepare("UPDATE users SET deleted_at = NULL, status = 'active' WHERE id = ?")->execute([$id]);
log_activity($pdo, $_SESSION['user_id'], 'user_restored', "Restored user: {$user['full_name']}");
set_flash('success', 'User restored.');
redirect('/admin/users/index.php');
