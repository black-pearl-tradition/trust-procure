<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT p.*, c.name AS category_name FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.id = ? AND p.status = 'active'"
);
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product not found';
    include __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="container"><div class="empty-state">This product is not available. It may have been removed or is no longer active.</div></div></section>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = $product['name'];
$isCustomer = !empty($_SESSION['user_id']) && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin', 'staff'], true);
$inCompare = in_array($product['id'], get_compare_list(), true);

$specs = [];
if (!empty($product['specifications'])) {
    foreach (explode(',', $product['specifications']) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $specs[] = $line;
    }
}

$gallery = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order');
$gallery->execute([$product['id']]);
$gallery = $gallery->fetchAll();

$rating = get_product_rating($pdo, $product['id']);

$reviewsStmt = $pdo->prepare(
    "SELECT r.*, u.full_name FROM product_reviews r
     JOIN users u ON u.id = r.user_id
     WHERE r.product_id = ? AND r.status = 'approved'
     ORDER BY r.created_at DESC LIMIT 10"
);
$reviewsStmt->execute([$product['id']]);
$reviews = $reviewsStmt->fetchAll();

$myReview = null;
if ($isCustomer) {
    $myStmt = $pdo->prepare('SELECT * FROM product_reviews WHERE product_id = ? AND user_id = ?');
    $myStmt->execute([$product['id'], $_SESSION['user_id']]);
    $myReview = $myStmt->fetch();
}

include __DIR__ . '/includes/header.php';
?>

<section class="section-tight">
    <div class="container">
        <div class="breadcrumb">
            <a href="<?= url('/products.php') ?>">Products</a> / <?= e($product['category_name'] ?? 'Product') ?>
        </div>

        <div class="detail-progress-strip" aria-label="Request process">
            <span class="detail-progress-item"><strong>01</strong> Select</span>
            <span class="detail-progress-item active"><strong>02</strong> Review</span>
            <span class="detail-progress-item"><strong>03</strong> Approve</span>
        </div>

        <div class="pd-grid">
            <div>
                <?php $primaryImage = $product['image'] ?: ($gallery[0]['image'] ?? null); ?>
                <?php $primaryImagePath = $primaryImage ? __DIR__ . '/uploads/products/' . basename($primaryImage) : ''; ?>
                <?php $primaryImageUrl = $primaryImage && $primaryImagePath !== '' && file_exists($primaryImagePath)
                    ? url('/uploads/products/' . rawurlencode(basename($primaryImage)))
                    : url('/assets/images/product-placeholder.svg'); ?>
                <div class="pd-image" id="pd-main-image">
                    <img src="<?= e($primaryImageUrl) ?>" alt="<?= e($product['name']) ?>">
                </div>
                <?php if ($gallery && ($product['image'] || count($gallery) > 1)): ?>
                    <div class="pd-thumbs">
                        <?php if ($product['image']): ?>
                            <?php $thumbUrl = file_exists(__DIR__ . '/uploads/products/' . basename($product['image'])) ? url('/uploads/products/' . rawurlencode(basename($product['image']))) : url('/assets/images/product-placeholder.svg'); ?>
                            <button type="button" class="pd-thumb active" data-full="<?= e($thumbUrl) ?>">
                                <img src="<?= e($thumbUrl) ?>" alt="">
                            </button>
                        <?php endif; ?>
                        <?php foreach ($gallery as $i => $g): ?>
                            <?php $galleryImage = trim((string) ($g['image'] ?? '')); ?>
                            <?php $galleryPath = $galleryImage !== '' ? __DIR__ . '/uploads/products/' . basename($galleryImage) : ''; ?>
                            <?php $galleryUrl = $galleryImage !== '' && $galleryPath !== '' && file_exists($galleryPath)
                                ? url('/uploads/products/' . rawurlencode(basename($galleryImage)))
                                : url('/assets/images/product-placeholder.svg'); ?>
                            <button type="button" class="pd-thumb<?= !$product['image'] && $i === 0 ? ' active' : '' ?>" data-full="<?= e($galleryUrl) ?>">
                                <img src="<?= e($galleryUrl) ?>" alt="">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <div class="product-category"><?= e($product['category_name'] ?? 'Uncategorised') ?><?= $product['brand'] ? ' · ' . e($product['brand']) : '' ?></div>
                <h1><?= e($product['name']) ?></h1>
                <div class="card-rating" style="margin-bottom: 10px;"><?= render_stars($rating['avg'], $rating['count']) ?></div>
                <div class="pd-meta-row">
                    <span class="pd-meta-pill"><i class="bi bi-box-seam me-1" aria-hidden="true"></i><?= e(stock_status_label($product['stock_status'])) ?></span>
                    <?php if ($product['brand']): ?>
                        <span class="pd-meta-pill"><i class="bi bi-tag me-1" aria-hidden="true"></i><?= e($product['brand']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($product['is_high_end'])): ?>
                        <span class="pd-meta-pill"><i class="bi bi-percent me-1" aria-hidden="true"></i><?= (int) $product['deposit_percentage'] ?>% deposit</span>
                    <?php endif; ?>
                </div>
                <p><?= nl2br(e($product['description'])) ?></p>
                <div class="pd-price"><?= money($product['price']) ?></div>

                <div class="table-actions" style="margin-top: 16px;">
                    <a href="<?= url('/compare.php?' . ($inCompare ? 'remove' : 'add') . '=' . $product['id']) ?>" class="btn btn-outline">
                        <?= $inCompare ? 'Remove from compare' : 'Add to compare' ?>
                    </a>
                </div>

                <?php if ($isCustomer && $product['stock_status'] !== 'out_of_stock'): ?>
                    <form method="post" action="<?= url('/user/requests/confirm.php') ?>" id="request-product" style="margin-top: 20px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                        <div class="field" style="max-width: 160px;">
                            <label for="quantity">Quantity</label>
                            <input type="number" id="quantity" name="quantity" value="1" min="1">
                        </div>

                        <div class="field">
                            <label for="delivery_address">Delivery address</label>
                            <input type="text" id="delivery_address" name="delivery_address" placeholder="Where should this be delivered?">
                        </div>

                        <div class="field">
                            <label for="notes">Notes for this request (optional)</label>
                            <textarea id="notes" name="notes" placeholder="e.g. preferred colour, delivery timing, or any other detail"></textarea>
                        </div>

                        <div class="deposit-box" id="deposit-calc" data-price="<?= e($product['price']) ?>" data-deposit-pct="<?= $product['is_high_end'] ? (int) $product['deposit_percentage'] : 100 ?>">
                            <div class="row"><span>Total cost</span><strong id="calc-total"><?= money($product['price']) ?></strong></div>
                            <?php if ($product['is_high_end']): ?>
                                <div class="row"><span>Deposit due now (<?= (int) $product['deposit_percentage'] ?>%)</span><strong id="calc-deposit"></strong></div>
                                <div class="row total"><span>Balance on collection</span><strong id="calc-balance"></strong></div>
                            <?php else: ?>
                                <div class="row total"><span>Payable in full to start the order</span><strong id="calc-deposit"></strong></div>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-accent btn-block" style="margin-top: 20px;">Review &amp; request this product</button>
                    </form>
                    <form method="post" action="<?= url('/user/cart/add.php') ?>" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                        <input type="hidden" id="cart-quantity" name="quantity" value="1">
                        <button type="submit" class="btn btn-outline-dark btn-block"><i class="bi bi-cart-plus me-2" aria-hidden="true"></i>Add to request cart</button>
                    </form>
                    <a href="<?= url('/compare_request.php?product=' . $product['id']) ?>" class="btn btn-dark btn-block mt-2"><i class="bi bi-layers me-2" aria-hidden="true"></i>Compare & Request</a>
                <?php elseif ($isCustomer): ?>
                    <div class="alert alert-warning" style="margin-top: 20px;">This item is currently out of stock and can't be requested right now.</div>
                <?php elseif (empty($_SESSION['user_id'])): ?>
                    <div class="alert alert-info" style="margin-top: 20px;">
                        <a href="<?= url('/login.php') ?>">Log in</a> or <a href="<?= url('/register.php') ?>">create an account</a> to request this product.
                    </div>
                <?php endif; ?>

                <?php if ($specs): ?>
                    <div class="pd-specs">
                        <h3>Specifications</h3>
                        <dl>
                            <?php foreach ($specs as $spec):
                                $parts = explode(':', $spec, 2);
                                $label = count($parts) === 2 ? trim($parts[0]) : 'Specification';
                                $value = count($parts) === 2 ? trim($parts[1]) : trim($parts[0]);
                            ?>
                                <dt><?= e($label) ?></dt>
                                <dd><?= e($value) ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <hr class="divider">

        <div class="pd-reviews">
            <h2>Customer reviews</h2>

            <?php if ($isCustomer): ?>
                <div class="panel" style="max-width: 560px; margin-bottom: 28px;">
                    <div class="panel-head">
                        <h3><?= $myReview ? 'Update your review' : 'Write a review' ?></h3>
                    </div>
                    <div class="panel-body">
                        <?php if ($myReview && $myReview['status'] === 'pending'): ?>
                            <div class="alert alert-info">Your review is awaiting moderation. You can still update it below.</div>
                        <?php endif; ?>
                        <form method="post" action="<?= url('/user/reviews/add.php') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                            <div class="field">
                                <label for="rating">Rating</label>
                                <select id="rating" name="rating" required>
                                    <option value="">Select a rating</option>
                                    <?php for ($i = 5; $i >= 1; $i--): ?>
                                        <option value="<?= $i ?>" <?= ($myReview['rating'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?> star<?= $i === 1 ? '' : 's' ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="comment">Your review (optional)</label>
                                <textarea id="comment" name="comment" placeholder="What did you think of this product?"><?= e($myReview['comment'] ?? '') ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-accent"><?= $myReview ? 'Update review' : 'Submit review' ?></button>
                        </form>
                    </div>
                </div>
            <?php elseif (empty($_SESSION['user_id'])): ?>
                <p class="muted"><a href="<?= url('/login.php') ?>">Log in</a> to write a review.</p>
            <?php endif; ?>

            <?php if ($reviews): ?>
                <div class="grid-3">
                    <?php foreach ($reviews as $r): ?>
                        <div class="review-card">
                            <?= render_stars((float) $r['rating'], 0, false) ?>
                            <p style="margin-top: 8px;"><?= e($r['comment'] ?: 'No written comment.') ?></p>
                            <div class="reviewer"><?= e($r['full_name']) ?></div>
                            <div class="product-ref"><?= display_date($r['created_at'], 'd M Y') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted">No reviews yet — be the first to share your experience.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script src="<?= url('/assets/js/script.js?v=20260915') ?>"></script>