<?php
require_once __DIR__ . '/includes/auth_check.php';
require_login();

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

$pageTitle = 'My Account';
$profileErrors = [];
$passwordErrors = [];
$fullName = $user['full_name'];
$phone = $user['phone'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'profile') {
    verify_csrf('/account.php');
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $profileImage = $user['profile_image'] ?? null;

    if ($fullName === '') $profileErrors[] = 'Please enter your full name.';

    if (!empty($_FILES['profile_image']['name']) && !$profileErrors) {
        try {
            $uploadedImage = handle_upload($_FILES['profile_image'], 'users');
            if ($uploadedImage) {
                $profileImage = $uploadedImage;
                $previousProfileImage = $user['profile_image'] ?? null;
                if ($previousProfileImage && $previousProfileImage !== $uploadedImage) {
                    $previousPath = user_profile_picture_path($previousProfileImage);
                    if (file_exists($previousPath) && $previousPath !== user_profile_picture_path(null)) {
                        @unlink($previousPath);
                    }
                }
            }
        } catch (RuntimeException $e) {
            $profileErrors[] = $e->getMessage();
        }
    }

    if (!$profileErrors) {
        $pdo->prepare('UPDATE users SET full_name = ?, phone = ?, profile_image = ? WHERE id = ?')->execute([$fullName, $phone, $profileImage, $userId]);
        $_SESSION['user_name'] = $fullName;
        $_SESSION['user_profile_image'] = $profileImage;
        $user['profile_image'] = $profileImage;
        log_activity($pdo, $userId, 'profile_updated', 'Updated own profile');
        set_flash('success', 'Profile updated.');
        redirect('/account.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'password') {
    verify_csrf('/account.php');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!password_verify($currentPassword, $user['password_hash'])) {
        $passwordErrors[] = 'Your current password is incorrect.';
    }
    $policyErrors = password_policy_errors($newPassword);
    if ($policyErrors) {
        $passwordErrors = array_merge($passwordErrors, $policyErrors);
    }
    if ($newPassword !== $confirmPassword) $passwordErrors[] = 'New passwords do not match.';

    if (!$passwordErrors) {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')->execute([$hash, $userId]);
        $_SESSION = [];
        session_destroy();
        session_start();
        set_flash('success', 'Password updated. Please log in again.');
        redirect('/login.php');
    }
}

if (is_supplier()) {
    $layout = '/includes/supplier_layout_start.php';
    $layoutEnd = '/includes/supplier_layout_end.php';
} elseif (is_staff_or_above()) {
    $layout = '/includes/admin_layout_start.php';
    $layoutEnd = '/includes/admin_layout_end.php';
} else {
    $layout = '/includes/user_layout_start.php';
    $layoutEnd = '/includes/user_layout_end.php';
}

include __DIR__ . $layout;
?>

<div class="account-shell">
    <div class="account-summary">
        <div class="account-summary-main">
            <img class="account-summary-avatar" src="<?= e(user_profile_picture_url($user['profile_image'] ?? null)) ?>" alt="Profile picture">
            <div>
                <div class="section-kicker">Account overview</div>
                <h2><?= e($user['full_name'] ?: 'Your account') ?></h2>
                <p><?= e($user['email']) ?></p>
            </div>
        </div>
        <div class="account-summary-meta">
            <span><?= e(role_label(current_role())) ?></span>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>Profile</h2>
        </div>
        <div class="panel-body">
        <?php foreach ($profileErrors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <form method="post" style="max-width: 480px;" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="profile">
            <div class="field">
                <label>Profile picture</label>
                <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 10px;">
                    <img src="<?= e(user_profile_picture_url($user['profile_image'] ?? null)) ?>" alt="Profile picture" style="width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 2px solid #dbe1ea; background: #f7f8fa;">
                    <input type="file" name="profile_image" accept="image/png,image/jpeg,image/webp">
                </div>
                <div class="hint">Upload a JPG, PNG, or WEBP image up to 5MB.</div>
            </div>
            <div class="field">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" value="<?= e($fullName) ?>" required>
            </div>
            <div class="field">
                <label>Email</label>
                <input type="text" value="<?= e($user['email']) ?>" disabled>
                <div class="hint">Email addresses cannot be changed. Contact an administrator if this needs to change.</div>
            </div>
            <div class="field">
                <label for="phone">Phone</label>
                <input type="tel" id="phone" name="phone" value="<?= e($phone) ?>">
            </div>
            <button type="submit" class="btn btn-accent">Save profile</button>
        </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>Change password</h2>
        </div>
        <div class="panel-body">
        <?php foreach ($passwordErrors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <form method="post" style="max-width: 480px;">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="password">
            <div class="field">
                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
            <div class="field">
                <label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" required>
                <div class="hint">At least 8 characters.</div>
            </div>
            <div class="field">
                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn btn-accent">Update password</button>
        </form>
        </div>
    </div>
</div>

<?php include __DIR__ . $layoutEnd; ?>