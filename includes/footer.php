</main>

<footer class="site-footer">
    <div class="footer-glow" aria-hidden="true"></div>
    <div class="container">
        <!-- Top CTA band -->
        <div class="footer-cta-band">
            <div class="footer-cta-band-text">
                <h3>Find the right device for your budget</h3>
                <p>Get a personalised recommendation matched to your needs and price range.</p>
            </div>
            <a href="<?= url('/recommend.php') ?>" class="footer-cta-btn">
                <span>Get a recommendation</span>
                <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
            </a>
        </div>

        <!-- Main footer grid -->
        <div class="footer-grid">
            <!-- Brand column -->
            <div class="footer-col footer-brand-col">
                <div class="footer-brand">
                    <i class="bi bi-box-seam" aria-hidden="true"></i>
                    Trust<span>Procure</span>
                </div>
                <p class="footer-tagline">Smarter electronics procurement, guided by transparent pricing, trusted sourcing, and practical advice.</p>
                <div class="footer-social">
                    <a href="#" aria-label="Facebook"><i class="bi bi-facebook" aria-hidden="true"></i></a>
                    <a href="#" aria-label="Twitter"><i class="bi bi-twitter-x" aria-hidden="true"></i></a>
                    <a href="#" aria-label="LinkedIn"><i class="bi bi-linkedin" aria-hidden="true"></i></a>
                    <a href="#" aria-label="Instagram"><i class="bi bi-instagram" aria-hidden="true"></i></a>
                </div>
            </div>

            <!-- Explore -->
            <div class="footer-col">
                <h4>Explore</h4>
                <ul class="footer-links">
                    <li><a href="<?= url('/products.php') ?>"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>Browse products</a></li>
                    <li><a href="<?= url('/new_arrivals.php') ?>"><i class="bi bi-stars" aria-hidden="true"></i>New arrivals</a></li>
                    <li><a href="<?= url('/compare.php') ?>"><i class="bi bi-arrow-left-right" aria-hidden="true"></i>Compare devices</a></li>
                    <li><a href="<?= url('/recommend.php') ?>"><i class="bi bi-lightbulb" aria-hidden="true"></i>Get a recommendation</a></li>
                </ul>
            </div>

            <!-- Company -->
            <div class="footer-col">
                <h4>Company</h4>
                <ul class="footer-links">
                    <li><a href="<?= url('/about.php') ?>"><i class="bi bi-info-circle" aria-hidden="true"></i>About us</a></li>
                    <li><a href="<?= url('/contact.php') ?>"><i class="bi bi-chat-dots" aria-hidden="true"></i>Contact support</a></li>
                    <li><a href="mailto:support@trustprocure.com"><i class="bi bi-envelope" aria-hidden="true"></i>Email support</a></li>
                </ul>
            </div>

            <!-- Account -->
            <div class="footer-col">
                <h4>Account</h4>
                <ul class="footer-links">
                    <li><a href="<?= url('/login.php') ?>"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Log in</a></li>
                    <li><a href="<?= url('/register.php') ?>"><i class="bi bi-person-plus" aria-hidden="true"></i>Create account</a></li>
                    <li><a href="<?= url('/account.php') ?>"><i class="bi bi-person-gear" aria-hidden="true"></i>My account</a></li>
                </ul>
                <div class="footer-trust">
                    <i class="bi bi-shield-check" aria-hidden="true"></i>
                    <span>Transparent procurement<small>Every request and payment tracked.</small></span>
                </div>
            </div>
        </div>

        <!-- Bottom bar -->
        <div class="footer-bottom">
            <div class="footer-bottom-left">
                <span class="footer-copy">&copy; <?= date('Y') ?> TrustProcure</span>
                <span class="footer-sep" aria-hidden="true">&middot;</span>
                <span class="footer-location"><i class="bi bi-geo-alt" aria-hidden="true"></i>Kampala, Uganda</span>
            </div>
            <div class="footer-bottom-right">
                <a href="#">Privacy</a>
                <a href="#">Terms</a>
                <a href="#">Cookies</a>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

</body>

</html>