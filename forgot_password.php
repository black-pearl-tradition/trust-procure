<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/forgot_password.php');
    $email = trim($_POST['email'] ?? '');

    if (validate_email_address($email)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always behave the same whether or not the account exists, so this
        // form can't be used to discover which emails are registered.
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);

            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? OR expires_at <= NOW()')
                ->execute([$user['id']]);
            $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
                ->execute([$user['id'], $tokenHash, $expiresAt]);

            $requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $requestHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $configuredBaseUrl = defined('APP_URL') ? APP_URL : '';
            $resetBaseUrl = $configuredBaseUrl !== '' ? $configuredBaseUrl : $requestScheme . '://' . $requestHost;
            $resetLink = $resetBaseUrl . url('/reset_password.php') . '?token=' . rawurlencode($token) . '&uid=' . $user['id'];

            $body = "Hello {$user['full_name']},\n\n"
                . "We received a request to reset your Trust Procurement password.\n"
                . "This link is valid for 1 hour:\n\n{$resetLink}\n\n"
                . "If you didn't request this, you can safely ignore this email.";

            send_email($pdo, $user['email'], 'Reset your Trust Procurement password', $body);
        }
    }

    $submitted = true;
}

$pageTitle = 'Forgot Password';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — Trust Procurement</title>
    <link rel="stylesheet" href="<?= url('/assets/css/style.css?v=20261026-header-height') ?>">
    <script defer src="<?= url('/assets/js/system-notifications.js?v=20261014') ?>"></script>
</head>

<body>
    <div class="auth-wrap">
        <div class="auth-visual">
            <a href="<?= url('/index.php') ?>" class="brand">Trust<span>Procure</span></a>
            <blockquote>
                "A structured process replaces scattered calls and messages."
                <cite>— Trust Procurement System</cite>
            </blockquote>
            <div></div>
        </div>
        <div class="auth-form-side">
            <div class="form-shell">
                <?php if ($submitted): ?>
                    <h1>Check your email</h1>
                    <p class="subtitle">If an account exists for that address, we've sent a password reset link. It expires in 1 hour.</p>
                    <div class="form-foot"><a href="<?= url('/login.php') ?>">Back to log in</a></div>
                <?php else: ?>
                    <h1>Forgot your password?</h1>
                    <p class="subtitle">Enter your account email and we'll send you a reset link.</p>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="field">
                            <label for="email">Email address</label>
                            <input type="email" id="email" name="email" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
                    </form>
                    <div class="form-foot"><a href="<?= url('/login.php') ?>">Back to log in</a></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>