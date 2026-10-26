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

$stmt = $pdo->prepare("UPDATE product_reviews SET status = 'rejected' WHERE id = ?");
$stmt->execute([$id]);

if ($review) {
    create_notification($pdo, (int) $review['user_id'], 'Review not approved', "Your review for {$review['product_name']} was not approved. Please ensure it meets our guidelines.", '/product_details.php?id=' . $review['product_id']);
}

log_activity($pdo, $_SESSION['user_id'], 'review_rejected', "Rejected review #$id");
set_flash('success', 'Review rejected and hidden from the product page.');
redirect('/admin/reviews/index.php');
