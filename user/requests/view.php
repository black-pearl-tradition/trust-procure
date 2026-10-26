<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT r.*, p.name AS product_name, p.price, p.image, c.name AS category_name FROM requests r
     JOIN products p ON p.id = r.product_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE r.id = ? AND r.user_id = ?"
);
$stmt->execute([$id, $_SESSION['user_id']]);
$request = $stmt->fetch();

if (!$request) {
    set_flash('error', 'Request not found.');
    redirect('/user/requests/index.php');
}

$historyStmt = $pdo->prepare(
    "SELECT pra.*, u.full_name AS approver_name
     FROM purchase_request_approvals pra
     JOIN users u ON u.id = pra.approver_id
     WHERE pra.request_id = ?
     ORDER BY pra.created_at DESC"
);
$historyStmt->execute([$id]);
$history = $historyStmt->fetchAll();

$pageTitle = 'Request #' . $request['id'];
include __DIR__ . '/../../includes/user_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2><?= e($request['product_name']) ?></h2>
        <span class="badge badge-<?= $request['status'] === 'pending' ? 'warning' : ($request['status'] === 'rejected' || $request['status'] === 'cancelled' ? 'danger' : 'success') ?>"><?= e(request_status_label($request['status'])) ?></span>
    </div>
    <div class="panel-body">
        <div class="detail-grid">
            <div class="detail-item"><div class="label">Quantity</div><div class="value"><?= (int) $request['quantity'] ?></div></div>
            <div class="detail-item"><div class="label">Estimated total</div><div class="value"><?= money($request['price'] * $request['quantity']) ?></div></div>
            <div class="detail-item"><div class="label">Category</div><div class="value"><?= e($request['category_name'] ?: 'Uncategorized') ?></div></div>
            <div class="detail-item"><div class="label">Submitted</div><div class="value"><?= display_date($request['created_at']) ?></div></div>
            <div class="detail-item"><div class="label">Reviewed</div><div class="value"><?= display_date($request['reviewed_at']) ?></div></div>
            <div class="detail-item"><div class="label">Status</div><div class="value"><span class="badge badge-<?= $request['status'] === 'pending' ? 'warning' : ($request['status'] === 'rejected' || $request['status'] === 'cancelled' ? 'danger' : 'success') ?>"><?= e(request_status_label($request['status'])) ?></span></div></div>
        </div>
        <?php if ($request['notes']): ?>
        <hr class="divider">
        <div class="detail-item"><div class="label">Your notes</div><div class="value"><?= nl2br(e($request['notes'])) ?></div></div>
        <?php endif; ?>

        <?php if ($request['resolution_note']): ?>
        <hr class="divider">
        <div class="detail-item"><div class="label"><?= $request['status'] === 'cancelled' ? 'Cancellation note' : 'Reason' ?></div><div class="value"><?= nl2br(e($request['resolution_note'])) ?></div></div>
        <?php endif; ?>

        <?php if ($history): ?>
        <hr class="divider">
        <div class="detail-item"><div class="label">Approval history</div><div class="value">
            <table>
                <thead>
                    <tr>
                        <th>Decision</th>
                        <th>Reviewer</th>
                        <th>Stage</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $entry): ?>
                        <tr>
                            <td><span class="badge badge-<?= $entry['decision'] === 'approved' ? 'success' : 'danger' ?>"><?= ucfirst(e($entry['decision'])) ?></span></td>
                            <td><?= e($entry['approver_name']) ?></td>
                            <td><?= e($entry['stage'] ?: 'review') ?></td>
                            <td><?= nl2br(e($entry['comments'] ?: '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
        <?php endif; ?>

        <?php if ($request['status'] === 'converted'): ?>
        <hr class="divider">
        <p>This request has been approved and converted into an order. <a href="<?= url('/user/orders/index.php') ?>">View your orders</a>.</p>
        <?php endif; ?>

        <?php if ($request['status'] === 'pending'): ?>
        <hr class="divider">
        <form method="post" action="<?= url('/user/requests/cancel.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $request['id'] ?>">
            <button type="submit" class="btn btn-danger" data-confirm="Cancel this request?">Cancel this request</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/user_layout_end.php'; ?>
