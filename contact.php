<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$pageTitle = 'Contact Us';
$errors = [];
$departmentOptions = [
    'general' => 'General inquiry',
    'orders' => 'Orders & delivery',
    'payments' => 'Payments',
    'products' => 'Product info',
    'support' => 'Technical support',
];
$department = 'general';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/contact.php');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $submittedDepartment = $_POST['department'] ?? 'general';
    $department = is_string($submittedDepartment) && array_key_exists($submittedDepartment, $departmentOptions)
        ? $submittedDepartment
        : 'general';
    $characterLength = static function (string $value): int {
        $length = preg_match_all('/./us', $value);
        return $length === false ? PHP_INT_MAX : $length;
    };

    if ($name === '') $errors[] = 'Please enter your name.';
    if ($characterLength($name) > 120) $errors[] = 'Your name must be 120 characters or fewer.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($characterLength($email) > 150) $errors[] = 'Your email address must be 150 characters or fewer.';
    if ($characterLength($phone) > 30) $errors[] = 'Your phone number must be 30 characters or fewer.';
    if ($subject === '') $errors[] = 'Please enter a subject.';
    if ($characterLength($subject) > 150) $errors[] = 'The subject must be 150 characters or fewer.';
    if ($message === '') $errors[] = 'Please enter a message.';
    if (strlen($message) < 10) $errors[] = 'Please provide more detail (at least 10 characters).';

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO contact_messages (name, email, phone, subject, message, department) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$name, $email, $phone, $subject, $message, $department]);

        $messageId = (int) $pdo->lastInsertId();
        foreach (staff_user_ids($pdo) as $staffUserId) {
            create_notification(
                $pdo,
                (int) $staffUserId,
                'New contact message',
                "A new contact message from {$name} ({$email}) about \"{$subject}\" is awaiting review.",
                '/admin/messages/view.php?id=' . $messageId
            );
        }

        set_flash('success', 'Thanks for reaching out — we have received your message and will be in touch as soon as possible.');
        redirect('/contact.php');
    }
}

include __DIR__ . '/includes/header.php';
?>

<section class="page-banner contact-page-banner">
    <div class="container">
        <div class="contact-banner-copy">
            <div class="page-kicker">We're here to help</div>
            <h1>Contact us</h1>
            <p>Have a question? Our team is ready to assist you.</p>
        </div>
        <div class="contact-banner-art" aria-hidden="true">
            <div class="contact-banner-orbit">
                <i class="bi bi-chat-dots-fill"></i>
            </div>
            <span>Real people. Helpful answers.</span>
        </div>
    </div>
</section>

<section class="section-tight contact-section">
    <div class="container contact-container">
        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="contact-intro">
                    <div class="section-kicker">Talk to TrustProcure</div>
                    <h2>Let's solve the next step together.</h2>
                    <p>Whether you are choosing a device, checking a payment, or following up on delivery, our team can point you in the right direction.</p>
                </div>
                <div class="contact-info-card">
                    <div class="contact-info-icon"><i class="bi bi-geo-alt"></i></div>
                    <h3>Visit us</h3>
                    <p>Plot 14, Kampala Road<br>Kampala, Uganda</p>
                </div>
                <div class="contact-info-card">
                    <div class="contact-info-icon"><i class="bi bi-telephone"></i></div>
                    <h3>Call us</h3>
                    <p><a href="tel:+256772891280">+256 772 891 280</a></p>
                </div>
                <div class="contact-info-card">
                    <div class="contact-info-icon"><i class="bi bi-whatsapp" aria-hidden="true"></i></div>
                    <h3>WhatsApp</h3>
                    <p><a href="https://wa.me/256772891280" target="_blank" rel="noopener noreferrer">Chat with us</a></p>
                </div>
                <div class="contact-info-card">
                    <div class="contact-info-icon"><i class="bi bi-envelope"></i></div>
                    <h3>Email us</h3>
                    <p><a href="mailto:servicehelp.tps@gmail.com">servicehelp.tps@gmail.com</a></p>
                </div>
                <div class="contact-info-card">
                    <div class="contact-info-icon"><i class="bi bi-clock"></i></div>
                    <h3>Office hours</h3>
                    <p>Mon-Fri: 8AM-6PM<br>Sat: 9AM-1PM</p>
                </div>
            </div>
            <div class="col-12 col-lg-8">
                <div class="contact-form-panel" id="contact-form">
                    <div class="section-kicker">Send a message</div>
                    <h2>Send us a message</h2>
                    <p class="text-muted">Fill out the form and our team will be in touch as soon as possible.</p>
                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-error"><?= e($error) ?></div>
                    <?php endforeach; ?>
                    <form method="post" class="contact-form">
                        <?= csrf_field() ?>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label for="name">Full name <span class="text-danger">*</span></label>
                                <input type="text" id="name" name="name" value="<?= e($_POST['name'] ?? '') ?>" maxlength="120" autocomplete="name" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="email">Email <span class="text-danger">*</span></label>
                                <input type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" maxlength="150" autocomplete="email" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="phone">Phone number</label>
                                <input type="tel" id="phone" name="phone" value="<?= e($_POST['phone'] ?? '') ?>" maxlength="30" autocomplete="tel">
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="department">Department</label>
                                <select id="department" name="department">
                                    <?php foreach ($departmentOptions as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= $department === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="subject">Subject <span class="text-danger">*</span></label>
                                <input type="text" id="subject" name="subject" value="<?= e($_POST['subject'] ?? $_GET['subject'] ?? '') ?>" maxlength="150" required>
                            </div>
                            <div class="col-12">
                                <label for="message">Message <span class="text-danger">*</span></label>
                                <textarea id="message" name="message" required><?= e($_POST['message'] ?? $_GET['message'] ?? '') ?></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary btn-lg">Send message</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section-tight faq-section">
    <div class="container">
        <div class="section-head text-center"><h2>Frequently asked questions</h2></div>
        <div class="row g-4">
            <div class="col-12 col-md-6">
                <div class="faq-card">
                    <div class="faq-icon"><i class="bi bi-credit-card"></i></div>
                    <div><h3>How does the deposit system work?</h3><p>Eligible high-end products show the deposit amount before you submit a request. <a href="<?= url('/products.php') ?>">Browse products</a> to see pricing and deposit details.</p></div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="faq-card">
                    <div class="faq-icon"><i class="bi bi-truck"></i></div>
                    <div><h3>How long does delivery take?</h3><p>Procurement typically takes 5-14 business days after payment verification. <a href="<?= url('/user/orders/index.php') ?>">View your orders</a> to follow an order's progress.</p></div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="faq-card">
                    <div class="faq-icon"><i class="bi bi-receipt"></i></div>
                    <div><h3>How can I check a payment?</h3><p>Sign in to review payment status and details. <a href="<?= url('/user/payments/index.php') ?>">View your payment history</a>.</p></div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="faq-card">
                    <div class="faq-icon"><i class="bi bi-arrow-left-right"></i></div>
                    <div><h3>Can I compare products?</h3><p>Yes. Add up to four products and compare their specifications side by side. <a href="<?= url('/compare.php') ?>">Open product comparison</a>.</p></div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="faq-card">
                    <div class="faq-icon"><i class="bi bi-shield-check"></i></div>
                    <div><h3>Are products covered by warranty?</h3><p>Warranty coverage may vary by product. <a href="#contact-form">Contact us</a> with the product name to confirm its coverage.</p></div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>