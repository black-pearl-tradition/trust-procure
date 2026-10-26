<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/google.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    redirect_user_by_role();
}

$errors = [];
$fullName = $email = $phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/register.php');
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($fullName === '') $errors[] = 'Please enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    $policyErrors = password_policy_errors($password);
    if ($policyErrors) {
        $errors = array_merge($errors, $policyErrors);
    }
    if ($password !== $confirmPassword) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$fullName, $email, $phone, $hash, 'user']);
        $userId = (int) $pdo->lastInsertId();

        log_activity($pdo, $userId, 'register', 'New account created');

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $fullName;
        $_SESSION['user_profile_image'] = null;
        $_SESSION['role'] = 'user';
        $_SESSION['session_version'] = 1;

        set_flash('success', 'Account created. Welcome to Trust Procurement.');
        redirect('/user/dashboard.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — Trust Procurement</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('/assets/css/style.css?v=20261026-header-height') ?>">
    <script defer src="<?= url('/assets/js/system-notifications.js?v=20261014') ?>"></script>
</head>

<body>
    <div class="auth-wrap">
        <div class="auth-visual">
            <a href="<?= url('/index.php') ?>" class="brand"><i class="bi bi-box-seam me-2" aria-hidden="true"></i>Trust<span>Procure</span></a>
            <div class="auth-message">
                <div class="auth-kicker"><i class="bi bi-stars" aria-hidden="true"></i> A clearer way to buy</div>
                <blockquote>Every request tracked, every payment recorded, from the first deposit to delivery.</blockquote>
                <cite>A procurement experience designed around trust.</cite>
            </div>
            <div class="auth-proof-grid">
                <span><i class="bi bi-sliders" aria-hidden="true"></i>Expert guidance</span>
                <span><i class="bi bi-shield-check" aria-hidden="true"></i>Trusted sourcing</span>
                <span><i class="bi bi-clock-history" aria-hidden="true"></i>Live progress</span>
            </div>
        </div>
        <div class="auth-form-side">
            <div class="form-shell wide auth-form-shell">
                <div class="auth-form-icon"><i class="bi bi-person-plus" aria-hidden="true"></i></div>
                <h1>Create your account</h1>
                <p class="subtitle">Request products, track orders, and manage payments in one place.</p>

                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-error"><?= e($error) ?></div>
                <?php endforeach; ?>

                <form method="post" id="register-form">
                    <?= csrf_field() ?>
                    <div class="field-row">
                        <div class="field">
                            <label for="full_name">Full name</label>
                            <input type="text" id="full_name" name="full_name" value="<?= e($fullName) ?>" required autofocus>
                        </div>
                        <div class="field">
                            <label for="phone">Phone number</label>
                            <input type="tel" id="phone" name="phone" value="<?= e($phone) ?>" placeholder="+256 7XX XXX XXX">
                        </div>
                    </div>
                    <div class="field">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email" value="<?= e($email) ?>" required>
                    </div>
                    <div class="field-row">
                        <div class="field">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required>
                            <div class="hint">At least 8 characters.</div>
                        </div>
                        <div class="field">
                            <label for="confirm_password">Confirm password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><i class="bi bi-person-plus me-2" aria-hidden="true"></i>Create account</button>
                </form>

                <?php if (google_oauth_enabled()): ?>
                    <div class="auth-divider"><span>or</span></div>
                    <a href="<?= e(url('/google_login.php')) ?>" class="btn google-signin-btn btn-block"><i class="bi bi-google me-2" aria-hidden="true"></i>Sign up with Google</a>
                <?php endif; ?>

                <div class="form-foot">
                    Already have an account? <a href="<?= url('/login.php') ?>">Log in</a>
                </div>
            </div>
        </div>
    </div>
    <script src="<?= url('/assets/js/script.js?v=20260915-1') ?>"></script>
</body>

</html>