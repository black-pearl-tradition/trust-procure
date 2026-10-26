<?php

/**
 * Homepage hero.
 *
 * A single structure serves both cases: the promotional hero (shown while at
 * least one banner is live) and the default advisory hero (shown when there
 * are none). The differences are only the .hero-promo theme class on the
 * section and how many .hero-slide articles it holds — the visible slide is
 * always the one carrying .is-active, and the dots only render with 2+ slides.
 *
 * Structure:
 *   .hero > .container > .hero-grid
 *       .hero-main  > .hero-slide(.is-active) [+ .hero-dots > .hero-dot]
 *       .hero-panel > .hero-panel-head + dl.stat rows
 *
 * Optional data, set by the including page. Safe defaults are applied so the
 * partial never emits undefined-variable warnings:
 *   $banners    array        Active promotional banners, in display order.
 *   $heroBanner array|null   First banner, or null when there are none.
 *   $stats      array        ['products' => int, 'categories' => int].
 */

$heroBanners = !empty($heroBanner) && !empty($banners) ? $banners : [];
$heroStats = $stats ?? [];
?>
<section class="hero<?= $heroBanners ? ' hero-promo' : '' ?>">
    <div class="container">
        <div class="hero-grid">
            <div class="hero-main">
                <?php if ($heroBanners): ?>
                    <?php foreach ($heroBanners as $i => $banner): ?>
                        <article class="hero-slide<?= $i === 0 ? ' is-active' : '' ?>" data-hero-slide="<?= $i ?>" aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>">
                            <div class="hero-eyebrow">Promotion</div>
                            <h1><?= e($banner['title']) ?></h1>
                            <?php if ($banner['message']): ?>
                                <p class="lede"><?= e($banner['message']) ?></p>
                            <?php endif; ?>
                            <?php if ($banner['cta_text'] && $banner['cta_link']): ?>
                                <div class="hero-actions">
                                    <a href="<?= e(link_url($banner['cta_link'])) ?>" class="btn btn-accent"><?= e($banner['cta_text']) ?></a>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>

                    <?php if (count($heroBanners) > 1): ?>
                        <div class="hero-dots">
                            <?php foreach ($heroBanners as $i => $banner): ?>
                                <button type="button" class="hero-dot<?= $i === 0 ? ' is-active' : '' ?>" data-hero-dot="<?= $i ?>" aria-label="Show promotion <?= $i + 1 ?>: <?= e($banner['title']) ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <article class="hero-slide is-active">
                        <div class="hero-eyebrow">Product advisory &amp; procurement</div>
                        <h1>Buy electronics on the advice of someone you already trust.</h1>
                        <p class="lede">We recommend the right device for your needs, coordinate the purchase with verified suppliers, and track delivery from order to doorstep &mdash; all with a transparent deposit and balance plan.</p>
                        <div class="hero-actions">
                            <a href="<?= url('/products.php') ?>" class="btn btn-accent">Browse products</a>
                            <?php if (empty($_SESSION['user_id'])): ?>
                                <a href="<?= url('/register.php') ?>" class="btn btn-outline">Create an account</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endif; ?>
            </div>

            <aside class="hero-panel" aria-label="Procurement at a glance">
                <div class="hero-panel-head">Procurement at a glance</div>
                <dl>
                    <?php if (isset($heroStats['products'])): ?>
                        <div class="stat">
                            <dt>Products listed</dt>
                            <dd><?= (int) $heroStats['products'] ?>+</dd>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($heroStats['categories'])): ?>
                        <div class="stat">
                            <dt>Categories covered</dt>
                            <dd><?= (int) $heroStats['categories'] ?></dd>
                        </div>
                    <?php endif; ?>
                    <div class="stat">
                        <dt>Deposit required on high-end devices</dt>
                        <dd>70%</dd>
                    </div>
                </dl>
            </aside>
        </div>
    </div>
</section>