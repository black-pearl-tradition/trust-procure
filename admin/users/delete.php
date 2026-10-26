<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/users/index.php');
}
verify_csrf('/admin/users/index.php');

$id = (int) ($_POST['id'] ?? 0);

if ($id === (int) $_SESSION['user_id']) {
    set_flash('error', 'You cannot delete your own account.');
    redirect('/admin/users/index.php');
}

$stmt = $pdo->prepare('SELECT full_name, role FROM users WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    set_flash('error', 'User not found.');
    redirect('/admin/users/index.php');
}

// Super Administrator accounts are protected from deletion entirely — the
// role can only be removed by first reassigning it via Edit, which itself
// requires another super_admin to do.
if ($user['role'] === 'super_admin') {
    set_flash('error', 'The Super Administrator account cannot be deleted.');
    redirect('/admin/users/index.php');
}

// Soft delete: keep the row (and all their orders/payments/reviews history
// intact via foreign keys) but mark it deleted and suspended so they can no
// longer log in. This preserves historical records rather than breaking them.
$pdo->prepare("UPDATE users SET deleted_at = NOW(), status = 'suspended' WHERE id = ?")->execute([$id]);

log_activity($pdo, $_SESSION['user_id'], 'user_deleted', "Deleted user: {$user['full_name']}");
set_flash('success', 'User deleted. Their order and payment history has been preserved and the account can be restored if needed.');
redirect('/admin/users/index.php');
