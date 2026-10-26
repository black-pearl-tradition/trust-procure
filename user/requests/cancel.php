<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/user/requests/index.php');
}
verify_csrf('/user/requests/index.php');

$id = (int) ($_POST['id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

$stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ? AND user_id = ? AND status = 'pending'");
$stmt->execute([$id, $_SESSION['user_id']]);
$request = $stmt->fetch();

if (!$request) {
    set_flash('error', 'This request can no longer be cancelled.');
    redirect('/user/requests/index.php');
}

$pdo->prepare("UPDATE requests SET status = 'cancelled', resolution_note = ? WHERE id = ?")
    ->execute([$reason ?: null, $id]);

log_activity($pdo, $_SESSION['user_id'], 'request_cancelled', "Cancelled request #$id");
set_flash('success', 'Your request has been cancelled.');
redirect('/user/requests/index.php');
