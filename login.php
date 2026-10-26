<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/google.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    redirect_user_by_role();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/login.php');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (login_attempts_exceeded($email)) {
        $errors[] = 'Too many failed attempts. Please wait 15 minutes before trying again.';
    } elseif ($email === '' || $password === '') {
        $errors[] = 'Please enter your email and password.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            record_failed_login($email);
            $errors[] = 'Incorrect email or password.';
        } elseif ($user['status'] !== 'active') {
            $errors[] = 'This account has been suspended. Contact support for help.';
        } else {
            clear_login_attempts($email);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_profile_image'] = $user['profile_image'] ?? null;
            $_SESSION['role'] = $user['role'];
            $_SESSION['session_version'] = (int) $user['session_version'];

            log_activity($pdo, $user['id'], 'login', 'User logged in');
            set_flash('success', 'Welcome back, ' . $user['full_name'] . '.');
            redirect(in_array($user['role'], ['staff', 'admin', 'super_admin'], true) ? '/admin/dashboard.php' : (($user['role'] === 'supplier') ? '/supplier/dashboard.php' : '/user/dashboard.php'));
        }
    }
}

$pageTitle = 'Log In';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In — Trust Procurement</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('/assets/css/style.css?v=20261026-header-height') ?>">
    <script defer src="<?= url('/assets/js/system-notifications.js?v=20261014') ?>"></script>
</head>

<body>
    <div class="auth-wrap">
        <div class="auth-visual">
            <a href="<?= url('/index.php') ?>" class="brand"><i class="bi bi-box-seam me-2" aria-hidden="true"></i>Trust<span>Procure</span></a>
            <div class="auth-message">
                <div class="auth-kicker"><i class="bi bi-shield-check" aria-hidden="true"></i> Procurement with confidence</div>
                <blockquote>Advice from someone who knows the market, procurement you can actually track.</blockquote>
                <cite>Every request, payment, and delivery kept visible.</cite>
            </div>
            <div class="auth-proof-grid">
                <span><i class="bi bi-search" aria-hidden="true"></i>Better choices</span>
                <span><i class="bi bi-receipt" aria-hidden="true"></i>Clear payments</span>
                <span><i class="bi bi-truck" aria-hidden="true"></i>Tracked delivery</span>
            </div>
        </div>
        <div class="auth-form-side">
            <div class="form-shell auth-form-shell">
                <div class="auth-form-icon"><i class="bi bi-person-circle" aria-hidden="true"></i></div>
                <h1>Welcome back</h1>
                <p class="subtitle">Log in to track your requests, orders and payments.</p>

                <?php render_flashes(); ?>
                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-error"><?= e($error) ?></div>
                <?php endforeach; ?>

                <form method="post">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                        <div class="hint"><a href="<?= url('/forgot_password.php') ?>">Forgot your password?</a></div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Log in</button>
                </form>

                <?php if (google_oauth_enabled()): ?>
                    <div class="auth-divider"><span>or</span></div>
                    <a href="<?= e(url('/google_login.php')) ?>" class="btn google-signin-btn btn-block"><i class="bi bi-google me-2" aria-hidden="true"></i>Continue with Google</a>
                <?php endif; ?>

                <div class="form-foot">
                    Don't have an account? <a href="<?= url('/register.php') ?>">Create one</a>
                </div>
            </div>
        </div>
    </div>
    <script src="<?= url('/assets/js/script.js?v=20260915-1') ?>"></script>
</body>

</html>