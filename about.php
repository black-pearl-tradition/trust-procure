<?php
require_once __DIR__ . "/includes/functions.php";
$pageTitle = "About";
include __DIR__ . "/includes/header.php";
?>

<!-- ═══════════════════════════════════════════════════════════════
     HERO
     ═══════════════════════════════════════════════════════════════ -->
<section class="about-hero">
    <div class="about-hero-bg" aria-hidden="true">
        <div class="about-hero-blob about-hero-blob-1"></div>
        <div class="about-hero-blob about-hero-blob-2"></div>
    </div>
    <div class="container">
        <div class="about-hero-inner">
            <div class="about-hero-text">
                <div class="about-kicker"><i class="bi bi-compass" aria-hidden="true"></i> Procurement with perspective</div>
                <h1>Good advice should scale without losing its human touch.</h1>
                <p class="about-lead">TrustProcure turns personal electronics advice into a clear, accountable buying experience for people and businesses.</p>
                <div class="about-hero-actions">
                    <a href="<?= url("/products.php") ?>" class="btn btn-accent btn-lg"><i class="bi bi-grid me-2" aria-hidden="true"></i>Explore products</a>
                    <a href="<?= url("/contact.php") ?>" class="btn btn-outline-dark btn-lg"><i class="bi bi-chat-dots me-2" aria-hidden="true"></i>Talk to our team</a>
                </div>
            </div>
            <div class="about-hero-side">
                <div class="about-proof-panel">
                    <div class="about-proof-head"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Built on trust</span></div>
                    <div class="about-proof-item"><i class="bi bi-currency-exchange" aria-hidden="true"></i><strong>Clear pricing</strong><span>Know what you are paying before you request.</span></div>
                    <div class="about-proof-item"><i class="bi bi-patch-check" aria-hidden="true"></i><strong>Verified sourcing</strong><span>Products coordinated with trusted suppliers.</span></div>
                    <div class="about-proof-item"><i class="bi bi-eye" aria-hidden="true"></i><strong>Full visibility</strong><span>Follow requests, payments, and delivery in one place.</span></div>
                </div>
                <div class="about-hero-stats">
                    <div class="about-hero-stat">
                        <strong>100%</strong>
                        <span>Transparent pricing on every listing</span>
                    </div>
                    <div class="about-hero-stat">
                        <strong>3</strong>
                        <span>Simple steps to receive your device</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════
     VALUES
     ═══════════════════════════════════════════════════════════════ -->
<section class="section about-values">
    <div class="container">
        <div class="section-head section-head-center">
            <div>
                <div class="section-eyebrow">What we stand for</div>
                <h2>Principles that guide every recommendation</h2>
                <p>Four commitments that shape how we source, advise, and deliver.</p>
            </div>
        </div>
        <div class="about-values-grid">
            <div class="about-value-card">
                <div class="about-value-icon"><i class="bi bi-search" aria-hidden="true"></i></div>
                <h3>Clarity first</h3>
                <p>We present real specifications and clear pricing so you can compare with confidence, not guesswork.</p>
            </div>
            <div class="about-value-card">
                <div class="about-value-icon"><i class="bi bi-link-45deg" aria-hidden="true"></i></div>
                <h3>Accountable sourcing</h3>
                <p>Devices are coordinated through trusted supplier relationships, never anonymous stock from unverified channels.</p>
            </div>
            <div class="about-value-card">
                <div class="about-value-icon"><i class="bi bi-receipt-cutoff" aria-hidden="true"></i></div>
                <h3>Tracked payments</h3>
                <p>Every deposit, balance, and proof of payment is recorded and visible to both you and our team.</p>
            </div>
            <div class="about-value-card">
                <div class="about-value-icon"><i class="bi bi-person-check" aria-hidden="true"></i></div>
                <h3>Human guidance</h3>
                <p>Recommendations come from people who know the products, not algorithms chasing a commission.</p>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════
     STORY
     ═══════════════════════════════════════════════════════════════ -->
<section class="section about-story">
    <div class="container">
        <div class="about-story-grid">
            <div class="about-story-left">
                <div class="section-eyebrow">Our approach</div>
                <h2>Personal guidance, professional process.</h2>
                <div class="about-story-timeline">
                    <div class="about-timeline-item">
                        <div class="about-timeline-dot"></div>
                        <div class="about-timeline-content">
                            <h4>Advice that started it</h4><p>It began with a knowledgeable advisor helping friends and family choose the right electronics, then coordinating purchases on their behalf.</p>
                        </div>
                    </div>
                    <div class="about-timeline-item">
                        <div class="about-timeline-dot"></div>
                        <div class="about-timeline-content">
                            <h4>The limits of word-of-mouth</h4>
                            <p>As the circle of trust grew, phone calls and scattered messages could no longer keep up with demand.</p>
                        </div>
                    </div>
                    <div class="about-timeline-item">
                        <div class="about-timeline-dot"></div>
                        <div class="about-timeline-content">
                            <h4>A platform that scales trust</h4>
                            <p>We built this system to keep the honest recommendation while replacing what did not scale with clear, trackable process.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="about-story-right">
                <div class="about-story-quote">
                    <i class="bi bi-quote" aria-hidden="true"></i>
                    <blockquote>Every listing carries clear specifications. Every request is tracked. Every payment has a paper trail both sides can see.</blockquote>
                    <cite>&mdash; The TrustProcure team</cite>
                </div>
                <div class="about-story-stats">
                    <div class="about-stat-card">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <strong>Clear choices</strong>
                        <span>Compare products by real specifications and price.</span>
                    </div>
                    <div class="about-stat-card">
                        <i class="bi bi-receipt" aria-hidden="true"></i>
                        <strong>Visible payments</strong>
                        <span>Track deposits, balances, proofs, and verification.</span>
                    </div>
                    <div class="about-stat-card">
                        <i class="bi bi-truck" aria-hidden="true"></i>
                        <strong>Accountable delivery</strong>
                        <span>Follow your request from approval to arrival.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════
     PROCESS
     ═══════════════════════════════════════════════════════════════ -->
<section class="section about-process">
    <div class="container">
        <div class="section-head section-head-center">
            <div>
                <div class="section-eyebrow">The journey</div>
                <h2>How procurement works</h2>
                <p>A straightforward path from a good decision to a delivered device.</p>
            </div>
        </div>
        <div class="about-process-grid">
            <div class="about-process-connector" aria-hidden="true"></div>
            <div class="about-step">
                <div class="about-step-top">
                    <span class="about-step-num">01</span>
                    <i class="bi bi-bag-check" aria-hidden="true"></i>
                </div>
                <h3>Request</h3>
                <p>Browse the catalog and submit a request for the device you want, in the quantity you need.</p>
            </div>
            <div class="about-step">
                <div class="about-step-top">
                    <span class="about-step-num">02</span>
                    <i class="bi bi-wallet2" aria-hidden="true"></i>
                </div>
                <h3>Approve &amp; pay</h3>
                <p>Once approved, deposit products require the calculated deposit before procurement begins; the rest is settled later.</p>
            </div>
            <div class="about-step">
                <div class="about-step-top">
                    <span class="about-step-num">03</span>
                    <i class="bi bi-box-seam" aria-hidden="true"></i>
                </div>
                <h3>Deliver</h3>
                <p>Track your order through procurement, shipping, and delivery from your own dashboard.</p>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════
     DIFFERENTIATORS
     ═══════════════════════════════════════════════════════════════ -->
<section class="about-principle">
    <div class="container">
        <div class="about-principle-grid">
            <div class="about-principle-left">
                <div class="section-eyebrow">Why source per order</div>
                <h2>Less inventory risk. More confidence in every purchase.</h2>
                <p>Because devices are sourced per order once a deposit is confirmed, we avoid the capital risk and warranty gaps that come with pre-purchasing stock from unverified suppliers, and we can pass that reliability on to you.</p>
            </div>
            <div class="about-principle-right">
                <div class="about-diff-grid">
                    <div class="about-diff-card about-diff-negative">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                        <span>Pre-buying unverified stock</span>
                    </div>
                    <div class="about-diff-card about-diff-negative">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                        <span>Hidden fees and unclear pricing</span>
                    </div>
                    <div class="about-diff-card about-diff-negative">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                        <span>No visibility on order status</span>
                    </div>
                    <div class="about-diff-sep"><span>vs</span></div>
                    <div class="about-diff-card about-diff-positive">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        <span>Sourced per confirmed order</span>
                    </div>
                    <div class="about-diff-card about-diff-positive">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        <span>Transparent, upfront costs</span>
                    </div>
                    <div class="about-diff-card about-diff-positive">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        <span>Tracked from request to delivery</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════
     FINAL CTA
     ═══════════════════════════════════════════════════════════════ -->
<section class="about-final-cta">
    <div class="container">
        <div class="about-final-cta-inner">
            <div class="about-final-cta-text">
                <h2>Ready to make your next electronics purchase the smart way?</h2>
                <p>Browse our catalog, compare devices, or get a personalised recommendation.</p>
            </div>
            <div class="about-final-cta-actions">
                <a href="<?= url("/products.php") ?>" class="btn btn-accent btn-lg"><i class="bi bi-grid me-2" aria-hidden="true"></i>Browse products</a>
                <a href="<?= url("/recommend.php") ?>" class="btn btn-outline-dark btn-lg"><i class="bi bi-stars me-2" aria-hidden="true"></i>Get a recommendation</a>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . "/includes/footer.php"; ?>