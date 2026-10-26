<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/products.php');
}

$productId = (int) ($_POST['product_id'] ?? 0);
verify_csrf('/product_details.php?id=' . $productId);
$rating = (int) ($_POST['rating'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

if ($rating < 1 || $rating > 5) {
    set_flash('error', 'Please select a rating between 1 and 5.');
    redirect('/product_details.php?id=' . $productId);
}

$stmt = $pdo->prepare("SELECT id FROM products WHERE id = ? AND status = 'active'");
$stmt->execute([$productId]);
if (!$stmt->fetch()) {
    set_flash('error', 'That product is not available.');
    redirect('/products.php');
}

// One review per customer per product — resubmitting updates the existing
// review and sends it back to pending for re-moderation.
$stmt = $pdo->prepare(
    'INSERT INTO product_reviews (product_id, user_id, rating, comment, status)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), status = ?'
);
$stmt->execute([$productId, $_SESSION['user_id'], $rating, $comment, 'pending', 'pending']);

log_activity($pdo, $_SESSION['user_id'], 'review_submitted', "Reviewed product #$productId ($rating stars)");
set_flash('success', 'Thanks — your review has been submitted for moderation.');
redirect('/product_details.php?id=' . $productId);
