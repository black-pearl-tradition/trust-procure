<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$pageTitle = 'Home';
$resolveBannerLink = static function (string $link): string {
    $link = trim($link);
    if ($link === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#)~i', $link)) {
        return $link;
    }
    return url('/' . ltrim($link, '/'));
};

// Active promotional banners (within their date window, if set).
$banners = $pdo->query(
    "SELECT * FROM promotional_banners
     WHERE status = 'active'
       AND (start_date IS NULL OR start_date <= CURDATE())
       AND (end_date IS NULL OR end_date >= CURDATE())
     ORDER BY sort_order, id"
)->fetchAll();
$heroBanner = $banners[0] ?? null;
$offerBanners = array_slice($banners, 1);

$categories = $pdo->query(
    "SELECT c.*, COUNT(p.id) AS product_count
     FROM categories c
     LEFT JOIN products p ON p.category_id = c.id AND p.status = 'active'
     GROUP BY c.id
     ORDER BY c.name"
)->fetchAll();

$newArrivals = $pdo->query(
    "SELECT p.*, c.name AS category_name FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.status = 'active' AND p.is_new_arrival = 1
     ORDER BY p.created_at DESC LIMIT 8"
)->fetchAll();

$featured = $pdo->query(
    "SELECT p.*, c.name AS category_name FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.status = 'active' AND p.is_featured = 1
     ORDER BY p.created_at DESC LIMIT 4"
)->fetchAll();
if (!$featured) {
    // Fall back to the latest active products so the section is never empty.
    $featured = $pdo->query(
        "SELECT p.*, c.name AS category_name FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.status = 'active' ORDER BY p.created_at DESC LIMIT 4"
    )->fetchAll();
}

$popular = $pdo->query(
    "SELECT p.*, c.name AS category_name, COUNT(o.id) AS order_count
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     LEFT JOIN orders o ON o.product_id = p.id
     WHERE p.status = 'active'
     GROUP BY p.id
     ORDER BY order_count DESC, p.created_at DESC
     LIMIT 4"
)->fetchAll();

$reviews = $pdo->query(
    "SELECT r.*, u.full_name, p.name AS product_name, p.id AS product_id FROM product_reviews r
     JOIN users u ON u.id = r.user_id
     JOIN products p ON p.id = r.product_id
     WHERE r.status = 'approved'
     ORDER BY r.created_at DESC LIMIT 4"
)->fetchAll();

$stats = [
    'products'   => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn(),
    'categories' => (int) $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn(),
    'suppliers'  => (int) $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status='active'")->fetchColumn(),
    'reviews'    => (int) $pdo->query("SELECT COUNT(*) FROM product_reviews WHERE status='approved'")->fetchColumn(),
];

$compareCount = count(get_compare_list());

include __DIR__ . '/includes/header.php';
?>

<?php if ($heroBanner): ?>
    <section class="banner-hero">
        <?php foreach ($banners as $i => $banner): ?>
            <div class="banner-slide<?= $i === 0 ? ' is-active' : '' ?><?= $i === 0 ? '' : ' banner-slide-hidden' ?>" data-banner-index="<?= $i ?>" aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>">
                <div class="container hero-grid">
                    <div>
                        <div class="hero-eyebrow">Promotion</div>
                        <h1><?= e($banner['title']) ?></h1>
                        <?php if ($banner['message']): ?>
                            <p class="lede"><?= e($banner['message']) ?></p>
                        <?php endif; ?>
                        <?php if ($banner['cta_text'] && $banner['cta_link']): ?>
                            <div class="hero-actions">
                                <a href="<?= e($resolveBannerLink($banner['cta_link'])) ?>" class="btn btn-accent"><?= e($banner['cta_text']) ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($banner['image'])): ?>
                        <div class="banner-image">
                            <img src="<?= url('/uploads/banners/' . e($banner['image'])) ?>" alt="<?= e($banner['title']) ?>">
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (count($banners) > 1): ?>
            <div class="banner-dots">
                <?php foreach ($banners as $i => $banner): ?>
                    <button type="button" class="banner-dot<?= $i === 0 ? ' active' : '' ?>" data-banner-dot="<?= $i ?>" aria-label="Show promotion <?= $i + 1 ?>"></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="hero">
        <div class="container hero-grid">
            <div>
                <div class="hero-eyebrow">Product advisory &amp; procurement</div>
                <h1>Buy electronics on the advice of someone you already trust.</h1>
                <p class="lede">We recommend the right device for your needs, coordinate the purchase with verified suppliers, and track delivery from order to doorstep, all with a transparent deposit and balance plan.</p>
                <div class="hero-actions">
                    <a href="<?= url('/products.php') ?>" class="btn btn-accent">Browse products</a>
                    <a href="<?= url('/recommend.php') ?>" class="btn btn-outline">Get a recommendation</a>
                    <?php if (empty($_SESSION['user_id'])): ?>
                        <a href="<?= url('/register.php') ?>" class="btn btn-outline">Create an account</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-panel">
                <dl>
                    <div class="stat">
                        <dt>Active products</dt>
                        <dd><?= $stats['products'] ?></dd>
                    </div>
                    <div class="stat">
                        <dt>Categories covered</dt>
                        <dd><?= $stats['categories'] ?></dd>
                    </div>
                    <div class="stat">
                        <dt>Verified suppliers</dt>
                        <dd><?= $stats['suppliers'] ?></dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="section section-tight home-trust-section">
    <div class="container">
        <div class="trust-strip">
            <div class="trust-item">
                <span class="trust-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
                <div>
                    <strong>Verified sourcing</strong>
                    <small>Only trusted suppliers and clear procurement terms.</small>
                </div>
            </div>
            <div class="trust-item">
                <span class="trust-icon"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></span>
                <div>
                    <strong>Transparent pricing</strong>
                    <small>Know the deposit, balance, and delivery expectations upfront.</small>
                </div>
            </div>
            <div class="trust-item">
                <span class="trust-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
                <div>
                    <strong>Tracked delivery</strong>
                    <small>Follow status updates from order approval to fulfilment.</small>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section section-tight home-category-section">
    <div class="container">
        <div class="section-head">
            <div>
                <h2>Shop by category</h2>
                <p>Find the type of device you're looking for.</p>
            </div>
        </div>
        <div class="grid-4">
            <?php foreach ($categories as $cat): ?>
                <a href="<?= url('/products.php?category=' . $cat['id']) ?>" class="value-card">
                    <h3><?= e($cat['name']) ?></h3>
                    <p><?= e($cat['description'] ?? '') ?></p>
                    <span class="category-count">
                        <span><?= (int) $cat['product_count'] ?> <?= (int) $cat['product_count'] === 1 ? 'product' : 'products' ?> available</span>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php $showCardSpecs = true; ?>
<?php if ($newArrivals): ?>
    <section class="section section-tight home-product-band home-product-band-muted">
        <div class="container">
            <div class="section-head">
                <div>
                    <h2>New arrivals</h2>
                    <p>Recently added to the catalog.</p>
                </div>
                <a href="<?= url('/new_arrivals.php') ?>" class="btn btn-outline btn-sm">View all new arrivals</a>
            </div>
            <div class="grid-4 product-card-grid">
                <?php foreach ($newArrivals as $product): $cardBadge = 'New'; ?>
                    <?php include __DIR__ . '/includes/product_card.php'; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="section home-product-band home-product-band-featured">
    <div class="container">
        <div class="section-head">
            <div>
                <h2>Featured products</h2>
                <p>A sample of what's currently available for procurement.</p>
            </div>
            <a href="<?= url('/products.php') ?>" class="btn btn-outline btn-sm">View all products</a>
        </div>
        <div class="grid-4 product-card-grid">
            <?php foreach ($featured as $product): $cardBadge = $product['is_featured'] ? 'Featured' : null; ?>
                <?php include __DIR__ . '/includes/product_card.php'; ?>
            <?php endforeach; ?>
            <?php if (!$featured): ?>
                <div class="home-featured-empty">
                    <i class="bi bi-box-seam" aria-hidden="true"></i>
                    <div>
                        <strong>Featured products are coming soon</strong>
                        <p>Browse the full catalog to see devices currently available for procurement.</p>
                    </div>
                    <a href="<?= url('/products.php') ?>" class="btn btn-outline btn-sm">Browse products</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($popular): ?>
    <section class="section section-tight home-product-band home-product-band-muted">
        <div class="container">
            <div class="section-head">
                <div>
                    <h2>Popular products</h2>
                    <p>The devices customers request most often.</p>
                </div>
            </div>
            <div class="grid-4 product-card-grid">
                <?php foreach ($popular as $product): $cardBadge = null; ?>
                    <?php include __DIR__ . '/includes/product_card.php'; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="section home-compare-section">
    <div class="container">
        <div class="compare-cta">
            <div>
                <h2>Not sure which device to pick?</h2>
                <p>Compare specifications, pricing and ratings side by side before you request a product.</p>
            </div>
            <a href="<?= url('/compare.php') ?>" class="btn btn-accent">
                <?= $compareCount > 0 ? "Compare {$compareCount} selected" : 'Start comparing products' ?>
            </a>
        </div>
    </div>
</section>

<?php if ($offerBanners): ?>
    <section class="section section-tight home-offers-section">
        <div class="container">
            <div class="section-head">
                <div>
                    <h2>Promotional offers</h2>
                    <p>More ways to save right now.</p>
                </div>
            </div>
            <div class="offer-strip">
                <?php foreach ($offerBanners as $offer): ?>
                    <div class="offer-card">
                        <h3><?= e($offer['title']) ?></h3>
                        <?php if ($offer['message']): ?><p><?= e($offer['message']) ?></p><?php endif; ?>
                        <?php if ($offer['cta_text'] && $offer['cta_link']): ?>
                            <a href="<?= e($resolveBannerLink($offer['cta_link'])) ?>" class="btn btn-outline btn-sm"><?= e($offer['cta_text']) ?></a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($reviews): ?>
    <section class="section section-tight home-reviews-section">
        <div class="container">
            <div class="section-head">
                <div>
                    <h2>What customers say</h2>
                    <p><?= $stats['reviews'] > 0 ? $stats['reviews'] . ' approved reviews from verified buyers.' : 'See how customers experience the procurement process.' ?></p>
                </div>
            </div>
            <div class="grid-4">
                <?php foreach ($reviews as $r): ?>
                    <div class="review-card">
                        <?= render_stars((float) $r['rating'], 0, false) ?>
                        <?php if ($r['comment']): ?><p style="margin-top: 10px;"><?= e($r['comment']) ?></p><?php endif; ?>
                        <div class="reviewer"><?= e($r['full_name']) ?></div>
                        <div class="product-ref">on <a href="<?= url('/product_details.php?id=' . $r['product_id']) ?>"><?= e($r['product_name']) ?></a></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script src="<?= url('/assets/js/script.js?v=20260915') ?>"></script>