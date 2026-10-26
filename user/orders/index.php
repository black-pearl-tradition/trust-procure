<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

$pageTitle = 'My Orders';
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT o.*, p.name AS product_name FROM orders o
     JOIN products p ON p.id = o.product_id
     WHERE o.user_id = ? ORDER BY o.created_at DESC"
);
$stmt->execute([$userId]);
$orders = $stmt->fetchAll();

include __DIR__ . '/../../includes/user_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>My orders</h2>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Product</th>
                    <th>Total</th>
                    <th>Deposit</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                    <?php $needsPayment = in_array($o['status'], ['request_approved', 'pending_deposit'], true) || ($o['status'] === 'delivered' && (float) $o['balance_amount'] > 0); ?>
                    <tr class="<?= $needsPayment ? 'customer-action-row' : '' ?>">
                        <td>#<?= (int) $o['id'] ?></td>
                        <td><?= e($o['product_name']) ?></td>
                        <td><?= money($o['total_amount']) ?></td>
                        <td><?= money($o['deposit_amount']) ?></td>
                        <td><?= money($o['balance_amount']) ?></td>
                        <td><span class="badge badge-<?= order_status_badge_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
                        <td>
                            <div class="table-actions">
                                <?php if ($needsPayment): ?><span class="customer-action-badge"><?= $o['status'] === 'delivered' ? 'Balance due' : 'Payment needed' ?></span><?php endif; ?>
                                <a href="<?= url('/user/orders/view.php?id=' . $o['id']) ?>" class="btn btn-outline btn-sm">View</a>
                                <?php if ($o['status'] === 'delivered' && (float) $o['balance_amount'] > 0): ?>
                                    <a href="<?= url('/user/payments/add.php?order_id=' . $o['id'] . '&type=balance') ?>" class="btn btn-accent btn-sm">Pay balance</a>
                                <?php endif; ?>
                                <?php if ($o['status'] === 'delivered'): ?>
                                    <a href="<?= url('/user/orders/view.php?id=' . $o['id']) ?>" class="btn btn-accent btn-sm">View &amp; confirm</a>
                                <?php endif; ?>
                                <?php if ($o['status'] === 'pending_deposit'): ?>
                                    <form method="post" action="<?= url('/user/orders/cancel.php') ?>" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $o['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" data-confirm="Cancel this order?">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?>
                    <tr>
                        <td colspan="7" class="empty-state">No orders yet. Orders appear here once a request is approved.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/user_layout_end.php'; ?>