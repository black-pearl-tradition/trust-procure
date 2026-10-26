<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    set_flash('error', 'User not found.');
    redirect('/admin/users/index.php');
}

// A regular admin cannot edit a super_admin account, and can only assign
// staff/supplier/customer roles. A super_admin can edit anyone and assign any role.
$editingSuperAdmin = $user['role'] === 'super_admin';
if ($editingSuperAdmin && !is_super_admin()) {
    set_flash('error', 'Only a Super Administrator can edit a Super Administrator account.');
    redirect('/admin/users/index.php');
}

$assignableRoles = is_super_admin()
    ? ['user' => 'Customer', 'supplier' => 'Supplier', 'staff' => 'Staff', 'admin' => 'Administrator', 'super_admin' => 'Super Administrator']
    : ['user' => 'Customer', 'supplier' => 'Supplier', 'staff' => 'Staff'];

$pageTitle = 'Edit User';
$errors = [];
$fullName = $user['full_name'];
$phone = $user['phone'];
$role = $user['role'];
$status = $user['status'];
$newPassword = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/admin/users/index.php');
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? $user['role'];
    $status = $_POST['status'] ?? 'active';
    $newPassword = $_POST['new_password'] ?? '';

    if ($fullName === '') $errors[] = 'Please enter a full name.';
    if (!array_key_exists($role, $assignableRoles) && $role !== $user['role']) {
        $errors[] = 'You are not permitted to assign that role.';
    }
    if ($id === (int) $_SESSION['user_id'] && ($role !== $user['role'] || $status !== 'active')) {
        $errors[] = 'You cannot change your own role or suspend your own account.';
    }
    if ($newPassword !== '' && $id === (int) $_SESSION['user_id']) {
        $errors[] = 'Use My Account to change your own password.';
    } elseif ($newPassword !== '') {
        $errors = array_merge($errors, password_policy_errors($newPassword));
    }

    if (!$errors) {
        if ($newPassword !== '') {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET full_name=?, phone=?, role=?, status=?, password_hash=?, session_version = session_version + 1 WHERE id=?');
            $stmt->execute([$fullName, $phone, $role, $status, $passwordHash, $id]);
            log_activity($pdo, $_SESSION['user_id'], 'user_password_reset', "Updated user #$id (role: $role, status: $status) and reset password");
        } else {
            $stmt = $pdo->prepare('UPDATE users SET full_name=?, phone=?, role=?, status=? WHERE id=?');
            $stmt->execute([$fullName, $phone, $role, $status, $id]);
            log_activity($pdo, $_SESSION['user_id'], 'user_updated', "Updated user #$id (role: $role, status: $status)");
        }
        set_flash('success', 'User updated.');
        redirect('/admin/users/index.php');
    }
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head"><h2>Edit user</h2></div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <form method="post" style="max-width: 480px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $user['id'] ?>">
            <div class="field">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" value="<?= e($fullName) ?>" required autofocus>
            </div>
            <div class="field">
                <label>Email</label>
                <input type="text" value="<?= e($user['email']) ?>" disabled>
            </div>
            <div class="field">
                <label for="phone">Phone</label>
                <input type="tel" id="phone" name="phone" value="<?= e($phone) ?>">
            </div>
            <?php if ($id !== (int) $_SESSION['user_id']): ?>
                <div class="field">
                    <label for="new_password">Set a new password <span class="muted">(optional)</span></label>
                    <input type="password" id="new_password" name="new_password" autocomplete="new-password">
                    <div class="hint">Leave blank to keep the current password. A reset signs the user out of existing sessions. Passwords are stored securely and cannot be viewed.</div>
                </div>
            <?php endif; ?>
            <div class="field-row">
                <div class="field">
                    <label for="role">Role</label>
                    <select id="role" name="role" <?= $id === (int) $_SESSION['user_id'] ? 'disabled' : '' ?>>
                        <?php if (!array_key_exists($role, $assignableRoles)): ?>
                            <option value="<?= e($role) ?>" selected><?= e(role_label($role)) ?> (current)</option>
                        <?php endif; ?>
                        <?php foreach ($assignableRoles as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $role === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($id === (int) $_SESSION['user_id']): ?>
                        <div class="hint">You can't change your own role.</div>
                        <input type="hidden" name="role" value="<?= e($role) ?>">
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status" <?= $id === (int) $_SESSION['user_id'] ? 'disabled' : '' ?>>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                    </select>
                    <?php if ($id === (int) $_SESSION['user_id']): ?>
                        <input type="hidden" name="status" value="active">
                    <?php endif; ?>
                </div>
            </div>
            <div class="table-actions">
                <button type="submit" class="btn btn-accent">Save changes</button>
                <a href="<?= url('/admin/users/index.php') ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
