<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$pageTitle = 'New Arrivals';

$products = $pdo->query(
    "SELECT p.*, c.name AS category_name FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.status = 'active' AND p.is_new_arrival = 1
     ORDER BY p.created_at DESC"
)->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<section class="page-banner">
    <div class="container">
        <h1>New arrivals</h1>
        <p>The newest additions to our catalog, available for procurement now.</p>
    </div>
</section>

<section class="section-tight">
    <div class="container">
        <div class="grid-4">
            <?php foreach ($products as $product): $cardBadge = 'New'; ?>
                <?php include __DIR__ . '/includes/product_card.php'; ?>
            <?php endforeach; ?>
        </div>
        <?php if (!$products): ?>
            <div class="empty-state">No new arrivals right now — check back soon, or <a href="<?= url('/products.php') ?>">browse the full catalog</a>.</div>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script src="<?= url('/assets/js/script.js?v=20260915') ?>"></script>