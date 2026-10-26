<?php $products = $products ?? []; ?>
<?php $showCardSpecs = true; ?>
<div class="row g-4 product-grid">
    <?php foreach ($products as $product): ?>
        <div class="col-6 col-md-6 col-lg-4 col-xl-3">
            <?php include __DIR__ . '/product_card.php'; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!$products): ?>
    <div class="empty-state catalog-empty-state">
        <p>No products match your current search or filters. Try a different keyword, or clear filters to browse the full catalog.</p>
        <a href="<?= url('/products.php') ?>" class="btn btn-outline btn-sm">Clear filters</a>
    </div>
<?php endif; ?>
