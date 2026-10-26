<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/reviews/index.php');
}
verify_csrf('/admin/reviews/index.php');

$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('DELETE FROM product_reviews WHERE id = ?');
$stmt->execute([$id]);
log_activity($pdo, $_SESSION['user_id'], 'review_deleted', "Deleted review #$id");
set_flash('success', 'Review deleted.');
redirect('/admin/reviews/index.php');
