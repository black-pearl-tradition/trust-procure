<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$uid = (int) ($_GET['uid'] ?? $_POST['uid'] ?? 0);
$errors = [];
$valid = false;

if (strlen($token) === 64 && $uid > 0) {
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        "SELECT * FROM password_resets WHERE user_id = ? AND token_hash = ? AND used = 0 AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$uid, $tokenHash]);
    $reset = $stmt->fetch();
    $valid = (bool) $reset;
}

if (!$valid) {
    $errors[] = 'This reset link is invalid or has expired. Please request a new one.';
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/forgot_password.php');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $errors = array_merge($errors, password_policy_errors($password));
    if ($password !== $confirmPassword) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $tokenHash = hash('sha256', $token);
            $resetStmt = $pdo->prepare(
                'SELECT id FROM password_resets WHERE user_id = ? AND token_hash = ? AND used = 0 AND expires_at > NOW() FOR UPDATE'
            );
            $resetStmt->execute([$uid, $tokenHash]);
            $lockedReset = $resetStmt->fetch();
            if (!$lockedReset) {
                throw new RuntimeException('This reset link is invalid or has expired.');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')
                ->execute([$hash, $uid]);
            $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')
                ->execute([$lockedReset['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $valid = false;
            $errors = ['This reset link is invalid or has expired. Please request a new one.'];
        }

        if (!$valid) {
            // Render the recovery link error rather than continuing to the success redirect.
        } else {
            log_activity($pdo, $uid, 'password_reset', 'Password reset via email link');
            set_flash('success', 'Your password has been reset. You can now log in.');
            redirect('/login.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — Trust Procurement</title>
    <link rel="stylesheet" href="<?= url('/assets/css/style.css?v=20261026-header-height') ?>">
    <script defer src="<?= url('/assets/js/system-notifications.js?v=20261014') ?>"></script>
</head>

<body>
    <div class="auth-wrap">
        <div class="auth-visual">
            <a href="<?= url('/index.php') ?>" class="brand">Trust<span>Procure</span></a>
            <blockquote>
                "Every request tracked, every payment recorded."
                <cite>— Trust Procurement System</cite>
            </blockquote>
            <div></div>
        </div>
        <div class="auth-form-side">
            <div class="form-shell">
                <h1>Set a new password</h1>
                <p class="subtitle">Choose a new password for your account.</p>

                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-error"><?= e($error) ?></div>
                <?php endforeach; ?>

                <?php if ($valid): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <input type="hidden" name="uid" value="<?= (int) $uid ?>">
                        <div class="field">
                            <label for="password">New password</label>
                            <input type="password" id="password" name="password" required autofocus>
                            <div class="hint">At least 8 characters.</div>
                        </div>
                        <div class="field">
                            <label for="confirm_password">Confirm new password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Reset password</button>
                    </form>
                <?php else: ?>
                    <div class="form-foot"><a href="<?= url('/forgot_password.php') ?>">Request a new reset link</a></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </div>
    <script src="<?= url('/assets/js/script.js?v=20260915-1') ?>"></script>
</body>

</html>