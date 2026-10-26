<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/reviews/index.php');
}
verify_csrf('/admin/reviews/index.php');

$id = (int) ($_POST['id'] ?? 0);

// Get review details for notification
$reviewStmt = $pdo->prepare("SELECT r.user_id, r.product_id, p.name AS product_name FROM product_reviews r JOIN products p ON p.id = r.product_id WHERE r.id = ?");
$reviewStmt->execute([$id]);
$review = $reviewStmt->fetch();

$stmt = $pdo->prepare("UPDATE product_reviews SET status = 'approved' WHERE id = ?");
$stmt->execute([$id]);

if ($review) {
    create_notification($pdo, (int) $review['user_id'], 'Review approved', "Your review for {$review['product_name']} has been approved and is now visible.", '/product_details.php?id=' . $review['product_id']);
}

log_activity($pdo, $_SESSION['user_id'], 'review_approved', "Approved review #$id");
set_flash('success', 'Review approved and now visible on the product page.');
redirect('/admin/reviews/index.php');
