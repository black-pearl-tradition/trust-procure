<?php

/**
 * Reusable product card partial.
 *
 * Expects $product (an associative array with at least: id, name, price,
 * image, is_high_end, deposit_percentage, stock_status, category_name) and
 * $pdo to already be in scope. Optionally set $cardBadge to a short string
 * (e.g. "New") to show a corner badge.
 */
if (!isset($product) || !is_array($product) || empty($product['id'])) {
    return;
}

$rating = get_product_rating($pdo, (int) $product['id']);
$inCompare = in_array((int) $product['id'], get_compare_list(), true);
$cardSpecs = [];
if (!empty($showCardSpecs) && !empty($product['specifications'])) {
    foreach (explode(',', (string) $product['specifications']) as $spec) {
        $spec = trim($spec);
        if ($spec !== '') {
            $cardSpecs[] = $spec;
        }
        if (count($cardSpecs) === 2) {
            break;
        }
    }
}
$productImage = trim((string) ($product['image'] ?? ''));
$productImagePath = $productImage !== '' ? __DIR__ . '/../uploads/products/' . basename($productImage) : '';
$productImageUrl = $productImage !== '' && $productImagePath !== '' && file_exists($productImagePath)
    ? url('/uploads/products/' . rawurlencode(basename($productImage)))
    : url('/assets/images/product-placeholder.svg');
?>
<article class="product-card h-100">
    <?php if (!empty($cardBadge)): ?>
        <span class="product-card-badge"><?= e($cardBadge) ?></span>
    <?php endif; ?>
    <a href="<?= url('/product_details.php?id=' . $product['id']) ?>" class="product-thumb" aria-label="View <?= e($product['name']) ?> details">
        <img src="<?= e($productImageUrl) ?>" alt="<?= e($product['name']) ?>">
    </a>
    <div class="product-body">
        <div class="product-category"><?= e($product['category_name'] ?? 'Uncategorised') ?></div>
        <a href="<?= url('/product_details.php?id=' . $product['id']) ?>" class="stretched-link-title">
            <h3><?= e($product['name']) ?></h3>
        </a>
        <?php if ($cardSpecs): ?>
            <div class="product-key-specs" aria-label="Key specifications">
                <?php foreach ($cardSpecs as $spec): ?>
                    <span><?= e($spec) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="card-rating">
            <?php if ($rating['count'] > 0): ?>
                <?= render_stars($rating['avg'], $rating['count']) ?>
            <?php endif; ?>
        </div>
        <div class="price"><?= money($product['price']) ?></div>
        <?php if (!empty($product['is_high_end'])): ?>
            <div class="deposit-note"><?= (int) $product['deposit_percentage'] ?>% deposit required</div>
        <?php endif; ?>
        <?php if (!empty($product['stock_status'])): ?>
            <span class="badge product-availability <?= e(stock_status_badge_class($product['stock_status'])) ?>"><?= e(stock_status_label($product['stock_status'])) ?></span>
        <?php endif; ?>
    </div>
    <div class="product-footer d-flex gap-2">
        <a href="<?= url('/product_details.php?id=' . $product['id'] . '#request-product') ?>" class="btn btn-accent btn-sm flex-grow-1 product-request-button">
            Request
        </a>
        <a href="<?= url('/product_details.php?id=' . $product['id']) ?>" class="btn btn-primary btn-sm flex-grow-1">
            Details
        </a>
        <a href="<?= url('/compare.php?' . ($inCompare ? 'remove' : 'add') . '=' . $product['id']) ?>" class="btn btn-outline-dark btn-sm compare-toggle" aria-label="<?= $inCompare ? 'Remove ' : 'Add ' ?><?= e($product['name']) ?> <?= $inCompare ? 'from' : 'to' ?> comparison" title="<?= $inCompare ? 'Remove from comparison' : 'Add to comparison' ?>">
            <?= $inCompare ? 'Remove' : 'Compare' ?>
        </a>
    </div>
</article>