<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    set_flash('error', 'User not found.');
    redirect('/admin/users/index.php');
}

$pageTitle = $user['full_name'];

$requests = $pdo->prepare(
    "SELECT r.*, p.name AS product_name FROM requests r JOIN products p ON p.id = r.product_id WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 10"
);
$requests->execute([$id]);
$requests = $requests->fetchAll();

$orders = $pdo->prepare(
    "SELECT o.*, p.name AS product_name FROM orders o JOIN products p ON p.id = o.product_id WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT 10"
);
$orders->execute([$id]);
$orders = $orders->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2><?= e($user['full_name']) ?></h2>
        <a href="<?= url('/admin/users/edit.php?id=' . $user['id']) ?>" class="btn btn-outline btn-sm">Edit user</a>
    </div>
    <div class="panel-body">
        <div class="detail-grid">
            <div class="detail-item"><div class="label">Email</div><div class="value"><?= e($user['email']) ?></div></div>
            <div class="detail-item"><div class="label">Phone</div><div class="value"><?= e($user['phone'] ?: '—') ?></div></div>
            <div class="detail-item"><div class="label">Role</div><div class="value"><?= e(role_label($user['role'])) ?></div></div>
            <div class="detail-item"><div class="label">Status</div><div class="value"><span class="badge badge-<?= $user['status'] === 'active' ? 'success' : 'danger' ?>"><?= ucfirst(e($user['status'])) ?></span></div></div>
            <div class="detail-item"><div class="label">Joined</div><div class="value"><?= display_date($user['created_at']) ?></div></div>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Recent requests</h3></div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>Product</th><th>Quantity</th><th>Status</th><th>Submitted</th></tr></thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                <tr>
                    <td><?= e($r['product_name']) ?></td>
                    <td><?= (int) $r['quantity'] ?></td>
                    <td><span class="badge badge-neutral"><?= e(request_status_label($r['status'])) ?></span></td>
                    <td><?= display_date($r['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$requests): ?><tr><td colspan="4" class="empty-state">No requests yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Recent orders</h3></div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>Product</th><th>Total</th><th>Status</th><th>Created</th></tr></thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td><?= e($o['product_name']) ?></td>
                    <td><?= money($o['total_amount']) ?></td>
                    <td><span class="badge badge-<?= order_status_badge_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
                    <td><?= display_date($o['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?><tr><td colspan="4" class="empty-state">No orders yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
