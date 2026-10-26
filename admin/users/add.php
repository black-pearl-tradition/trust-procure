<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Add User';
$errors = [];
$fullName = $email = $phone = '';
$role = 'user';

// Only a super_admin can create another admin or super_admin account —
// a regular admin can only create staff, supplier, or customer accounts.
$assignableRoles = is_super_admin()
    ? ['user' => 'Customer', 'supplier' => 'Supplier', 'staff' => 'Staff', 'admin' => 'Administrator', 'super_admin' => 'Super Administrator']
    : ['user' => 'Customer', 'supplier' => 'Supplier', 'staff' => 'Staff'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/admin/users/index.php');
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'user';
    $password = $_POST['password'] ?? '';

    if ($fullName === '') $errors[] = 'Please enter a full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    $policyErrors = password_policy_errors($password);
    if ($policyErrors) {
        $errors = array_merge($errors, $policyErrors);
    }
    if (!array_key_exists($role, $assignableRoles)) $errors[] = 'You are not permitted to assign that role.';

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
        $stmt->execute([$fullName, $email, $phone, $hash, $role]);
        $newId = (int) $pdo->lastInsertId();
        log_activity($pdo, $_SESSION['user_id'], 'user_created', "Created user #$newId ({$role}): $email");
        set_flash('success', 'User account created.');
        redirect('/admin/users/index.php');
    }
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>Add user</h2>
    </div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <form method="post" style="max-width: 480px;">
            <?= csrf_field() ?>
            <div class="field">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" value="<?= e($fullName) ?>" required autofocus>
            </div>
            <div class="field">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>" required>
            </div>
            <div class="field">
                <label for="phone">Phone</label>
                <input type="tel" id="phone" name="phone" value="<?= e($phone) ?>">
            </div>
            <div class="field">
                <label for="role">Role</label>
                <select id="role" name="role">
                    <?php foreach ($assignableRoles as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $role === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!is_super_admin()): ?>
                    <div class="hint">Only a Super Administrator can create Administrator or Super Administrator accounts.</div>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="password">Temporary password</label>
                <input type="password" id="password" name="password" required>
                <div class="hint">At least 8 characters. Share this with the user securely — they can change it from My Account.</div>
            </div>
            <div class="table-actions">
                <button type="submit" class="btn btn-accent">Create user</button>
                <a href="<?= url('/admin/users/index.php') ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>