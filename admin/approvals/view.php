<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT r.*, u.full_name, p.name AS product_name, p.price, p.category_id, c.name AS category_name,
            (p.price * r.quantity) AS total_amount
     FROM requests r
     JOIN users u ON u.id = r.user_id
     JOIN products p ON p.id = r.product_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE r.id = ?"
);
$stmt->execute([$id]);
$request = $stmt->fetch();

if (!$request) {
    set_flash('error', 'Request not found.');
    redirect('/admin/approvals/index.php');
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

$pageTitle = 'Request approval history';
include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Request #<?= (int) $request['id'] ?> · <?= e($request['product_name']) ?></h2>
            <p class="muted mb-0">Review the approval decision trail for this procurement request.</p>
        </div>
        <a href="<?= url('/admin/approvals/index.php') ?>" class="btn btn-outline btn-sm">Back to queue</a>
    </div>
    <div class="panel-body">
        <div class="detail-grid">
            <div class="detail-item"><div class="label">Customer</div><div class="value"><?= e($request['full_name']) ?></div></div>
            <div class="detail-item"><div class="label">Category</div><div class="value"><?= e($request['category_name'] ?: 'Uncategorized') ?></div></div>
            <div class="detail-item"><div class="label">Quantity</div><div class="value"><?= (int) $request['quantity'] ?></div></div>
            <div class="detail-item"><div class="label">Total</div><div class="value"><?= money((float) $request['total_amount']) ?></div></div>
            <div class="detail-item"><div class="label">Submitted</div><div class="value"><?= display_date($request['created_at']) ?></div></div>
            <div class="detail-item"><div class="label">Current status</div><div class="value"><span class="badge badge-<?= $request['status'] === 'pending' ? 'warning' : ($request['status'] === 'rejected' ? 'danger' : 'success') ?>"><?= e(request_status_label($request['status'])) ?></span></div></div>
        </div>

        <?php if (!empty($request['notes'])): ?>
            <hr class="divider">
            <div class="detail-item"><div class="label">Customer notes</div><div class="value"><?= nl2br(e($request['notes'])) ?></div></div>
        <?php endif; ?>

        <?php if (!empty($request['resolution_note'])): ?>
            <hr class="divider">
            <div class="detail-item"><div class="label">Resolution note</div><div class="value"><?= nl2br(e($request['resolution_note'])) ?></div></div>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Approval history</h3></div>
    <div class="panel-body flush">
        <?php if (!$history): ?>
            <div class="empty-state">No approval actions have been recorded for this request yet.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Decision</th>
                        <th>Stage</th>
                        <th>Approver</th>
                        <th>Comment</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $entry): ?>
                        <tr>
                            <td><span class="badge badge-<?= $entry['decision'] === 'approved' ? 'success' : 'danger' ?>"><?= ucfirst(e($entry['decision'])) ?></span></td>
                            <td><?= e($entry['stage'] ?: 'review') ?></td>
                            <td><?= e($entry['approver_name']) ?></td>
                            <td><?= nl2br(e($entry['comments'] ?: '—')) ?></td>
                            <td><?= display_date($entry['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
