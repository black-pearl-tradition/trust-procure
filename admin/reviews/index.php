<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Product Reviews';
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT r.*, u.full_name, p.name AS product_name
        FROM product_reviews r
        JOIN users u ON u.id = r.user_id
        JOIN products p ON p.id = r.product_id";
$params = [];
if (in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
    $sql .= " WHERE r.status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY (r.status = 'pending') DESC, r.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reviews = $stmt->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head"><h2>All reviews</h2></div>
    <div class="panel-body">
        <div class="table-actions">
            <a href="<?= url('/admin/reviews/index.php') ?>" class="btn btn-<?= $statusFilter === '' ? 'primary' : 'outline' ?> btn-sm">All</a>
            <a href="<?= url('/admin/reviews/index.php?status=pending') ?>" class="btn btn-<?= $statusFilter === 'pending' ? 'primary' : 'outline' ?> btn-sm">Pending</a>
            <a href="<?= url('/admin/reviews/index.php?status=approved') ?>" class="btn btn-<?= $statusFilter === 'approved' ? 'primary' : 'outline' ?> btn-sm">Approved</a>
            <a href="<?= url('/admin/reviews/index.php?status=rejected') ?>" class="btn btn-<?= $statusFilter === 'rejected' ? 'primary' : 'outline' ?> btn-sm">Rejected</a>
        </div>
    </div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>Product</th><th>Customer</th><th>Rating</th><th>Comment</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($reviews as $r): ?>
                <tr>
                    <td><a href="<?= url('/product_details.php?id=' . $r['product_id']) ?>" target="_blank"><?= e($r['product_name']) ?></a></td>
                    <td><?= e($r['full_name']) ?></td>
                    <td><?= render_stars((float) $r['rating'], 0, false) ?></td>
                    <td class="muted" style="max-width: 260px;"><?= e($r['comment'] ?: '—') ?></td>
                    <td><span class="badge badge-<?= $r['status'] === 'approved' ? 'success' : ($r['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= ucfirst(e($r['status'])) ?></span></td>
                    <td><?= display_date($r['created_at']) ?></td>
                    <td>
                        <div class="table-actions">
                            <?php if ($r['status'] !== 'approved'): ?>
                                <form method="post" action="<?= url('/admin/reviews/approve.php') ?>" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn btn-accent btn-sm">Approve</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($r['status'] !== 'rejected'): ?>
                                <form method="post" action="<?= url('/admin/reviews/reject.php') ?>" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm">Reject</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= url('/admin/reviews/delete.php') ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this review permanently?">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$reviews): ?>
                    <tr><td colspan="7" class="empty-state">No reviews to show.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
