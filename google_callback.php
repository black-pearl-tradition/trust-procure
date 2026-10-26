<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/google.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$config = google_oauth_config();
$state = $_GET['state'] ?? '';
$expectedState = $_SESSION['google_oauth_state'] ?? '';
unset($_SESSION['google_oauth_state']);

if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {
    set_flash('error', 'Google sign-in could not be verified. Please try again.');
    redirect('/login.php');
}
if (!empty($_GET['error']) || empty($_GET['code'])) {
    set_flash('error', 'Google sign-in was cancelled or failed.');
    redirect('/login.php');
}

try {
    $token = google_oauth_post('https://oauth2.googleapis.com/token', [
        'code' => $_GET['code'],
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri' => $config['redirect_uri'],
        'grant_type' => 'authorization_code',
    ]);
    if (empty($token['access_token'])) {
        throw new RuntimeException('Missing Google access token.');
    }

    $userInfo = google_oauth_get('https://openidconnect.googleapis.com/v1/userinfo', $token['access_token']);
    $email = filter_var($userInfo['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $name = trim($userInfo['name'] ?? '');
    if (!$email || $name === '' || empty($userInfo['email_verified'])) {
        throw new RuntimeException('Google account details were incomplete or unverified.');
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'user']);
        $userId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        log_activity($pdo, $userId, 'register_google', 'Account created through Google sign-in');
    }

    if (!$user || $user['status'] !== 'active') {
        throw new RuntimeException('This account is unavailable. Contact support for help.');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_profile_image'] = $user['profile_image'] ?? null;
    $_SESSION['role'] = $user['role'];
    $_SESSION['session_version'] = (int) $user['session_version'];
    log_activity($pdo, $user['id'], 'login_google', 'User signed in with Google');
    set_flash('success', 'Welcome back, ' . $user['full_name'] . '.');
    redirect(in_array($user['role'], ['admin', 'super_admin', 'staff'], true) ? '/admin/dashboard.php' : '/user/dashboard.php');
} catch (Throwable $e) {
    set_flash('error', 'Google sign-in could not be completed. Please use email and password.');
    redirect('/login.php');
}
