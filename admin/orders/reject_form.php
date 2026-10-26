<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT r.*, u.full_name, p.name AS product_name FROM requests r
     JOIN users u ON u.id = r.user_id
     JOIN products p ON p.id = r.product_id
     WHERE r.id = ? AND r.status = 'pending'"
);
$stmt->execute([$id]);
$request = $stmt->fetch();

if (!$request) {
    set_flash('error', 'Request not found or already reviewed.');
    redirect('/admin/orders/index.php');
}

$pageTitle = 'Reject Request';
include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head"><h2>Reject request — <?= e($request['product_name']) ?></h2></div>
    <div class="panel-body">
        <p class="mb-0">From <?= e($request['full_name']) ?>, quantity <?= (int) $request['quantity'] ?>. Let them know why this request is being declined — this note is visible to the customer.</p>
        <form method="post" action="<?= url('/admin/orders/reject.php') ?>" style="max-width: 480px; margin-top: 20px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $request['id'] ?>">
            <div class="field">
                <label for="reason">Reason (optional but recommended)</label>
                <textarea id="reason" name="reason" placeholder="e.g. Out of stock at all verified suppliers this month"></textarea>
            </div>
            <div class="table-actions">
                <button type="submit" class="btn btn-danger">Reject request</button>
                <a href="<?= url('/admin/orders/index.php') ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
