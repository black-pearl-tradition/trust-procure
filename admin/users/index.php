<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Users';

$search = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$showDeleted = ($_GET['view'] ?? '') === 'deleted';
$page = current_page();
$perPage = 20;

$where = $showDeleted ? " WHERE deleted_at IS NOT NULL" : " WHERE deleted_at IS NULL";
$params = [];
if ($search !== '') {
    $where .= " AND (full_name LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($roleFilter !== '' && in_array($roleFilter, ['super_admin', 'admin', 'staff', 'supplier', 'user'], true)) {
    $where .= " AND role = ?";
    $params[] = $roleFilter;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users" . $where);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$sql = "SELECT * FROM users" . $where . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
foreach ($params as $i => $val) {
    $stmt->bindValue($i + 1, $val);
}
// Positional binding right through: mixing ? with :limit/:offset in one
// statement is rejected by PDO ("mixed named and positional parameters")
// whenever emulated prepares are off, which config/database.php turns off.
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

$roleBadge = [
    'super_admin' => 'info',
    'admin'       => 'info',
    'staff'       => 'warning',
    'supplier'    => 'success',
    'user'        => 'neutral',
];

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2><?= $showDeleted ? 'Deleted users' : 'All users' ?></h2>
        <a href="<?= url('/admin/users/add.php') ?>" class="btn btn-accent btn-sm">Add user</a>
    </div>
    <div class="panel-body">
        <div class="table-actions" style="margin-bottom: 14px;">
            <a href="<?= url('/admin/users/index.php') ?>" class="btn btn-<?= !$showDeleted ? 'primary' : 'outline' ?> btn-sm">Active &amp; suspended</a>
            <a href="<?= url('/admin/users/index.php?view=deleted') ?>" class="btn btn-<?= $showDeleted ? 'primary' : 'outline' ?> btn-sm">Deleted</a>
        </div>
        <form method="get" class="filter-bar" style="margin-bottom: 0;">
            <?php if ($showDeleted): ?><input type="hidden" name="view" value="deleted"><?php endif; ?>
            <input type="search" name="q" placeholder="Search by name or email" value="<?= e($search) ?>">
            <select name="role" onchange="this.form.submit()">
                <option value="">All roles</option>
                <option value="super_admin" <?= $roleFilter === 'super_admin' ? 'selected' : '' ?>>Super Administrator</option>
                <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Administrator</option>
                <option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>Staff</option>
                <option value="supplier" <?= $roleFilter === 'supplier' ? 'selected' : '' ?>>Supplier</option>
                <option value="user" <?= $roleFilter === 'user' ? 'selected' : '' ?>>Customer</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Search</button>
        </form>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['full_name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge badge-<?= $roleBadge[$u['role']] ?? 'neutral' ?>"><?= e(role_label($u['role'])) ?></span></td>
                    <td><span class="badge badge-<?= $u['status'] === 'active' ? 'success' : 'danger' ?>"><?= ucfirst(e($u['status'])) ?></span></td>
                    <td><?= display_date($u['created_at'], 'd M Y') ?></td>
                    <td>
                        <div class="table-actions">
                            <?php if ($showDeleted): ?>
                                <form method="post" action="<?= url('/admin/users/restore.php') ?>" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" data-confirm="Restore this account?">Restore</button>
                                </form>
                            <?php else: ?>
                                <a href="<?= url('/admin/users/view.php?id=' . $u['id']) ?>" class="btn btn-outline btn-sm">View</a>
                                <a href="<?= url('/admin/users/edit.php?id=' . $u['id']) ?>" class="btn btn-outline btn-sm">Edit</a>
                                <?php if ($u['id'] !== (int) $_SESSION['user_id'] && $u['role'] !== 'super_admin'): ?>
                                <form method="post" action="<?= url('/admin/users/delete.php') ?>" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this user account? Their history is kept, but they will no longer be able to log in.">Delete</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?>
                    <tr><td colspan="6" class="empty-state">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php render_pagination($page, $totalPages, '/admin/users/index.php', ['q' => $search, 'role' => $roleFilter, 'view' => $showDeleted ? 'deleted' : '']); ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
