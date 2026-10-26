<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_user();

$pageTitle = 'Dashboard';
$userId = $_SESSION['user_id'];

$stats = [
    'requests' => $pdo->prepare("SELECT COUNT(*) FROM requests WHERE user_id = ?"),
    'orders'   => $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status NOT IN ('completed','cancelled')"),
    'balance'  => $pdo->prepare("SELECT COALESCE(SUM(balance_amount),0) FROM orders WHERE user_id = ? AND status NOT IN ('completed','cancelled')"),
];
foreach ($stats as $key => $stmt) {
    $stmt->execute([$userId]);
    $stats[$key] = $stmt->fetchColumn();
}

$recentRequests = $pdo->prepare(
    "SELECT r.*, p.name AS product_name FROM requests r JOIN products p ON p.id = r.product_id
     WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 5"
);
$recentRequests->execute([$userId]);
$recentRequests = $recentRequests->fetchAll();

$recentOrders = $pdo->prepare(
    "SELECT o.*, p.name AS product_name FROM orders o JOIN products p ON p.id = o.product_id
     WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT 5"
);
$recentOrders->execute([$userId]);
$recentOrders = $recentOrders->fetchAll();

$nextStep = [
    'eyebrow' => 'Start here',
    'title' => 'Find the right device',
    'message' => 'Browse the catalog or get a recommendation based on your needs and budget.',
    'href' => '/products.php',
    'label' => 'Browse products',
];
foreach ($recentOrders as $order) {
    if (in_array($order['status'], ['request_approved', 'pending_deposit'], true)) {
        $nextStep = [
            'eyebrow' => 'Payment needed',
            'title' => 'Your order is ready to start',
            'message' => 'Submit the required payment so procurement can begin.',
            'href' => '/user/orders/view.php?id=' . (int) $order['id'],
            'label' => 'Review order',
        ];
        break;
    }
    if ($order['status'] === 'delivered' && (float) $order['balance_amount'] > 0) {
        $nextStep = [
            'eyebrow' => 'Balance due',
            'title' => 'Your order has been delivered',
            'message' => 'Submit the remaining balance to complete this order.',
            'href' => '/user/payments/add.php?order_id=' . (int) $order['id'] . '&type=balance',
            'label' => 'Pay balance',
        ];
        break;
    }
    if (!in_array($order['status'], ['completed', 'cancelled'], true)) {
        $nextStep = [
            'eyebrow' => 'Order in progress',
            'title' => 'Track your active order',
            'message' => 'See the latest status, payment summary, and order timeline.',
            'href' => '/user/orders/view.php?id=' . (int) $order['id'],
            'label' => 'View order',
        ];
        break;
    }
}

include __DIR__ . '/../includes/user_layout_start.php';
?>

<div class="dashboard-next-step">
    <div>
        <div class="section-kicker"><?= e($nextStep['eyebrow']) ?></div>
        <h2><?= e($nextStep['title']) ?></h2>
        <p><?= e($nextStep['message']) ?></p>
    </div>
    <a href="<?= url($nextStep['href']) ?>" class="btn btn-accent"><?= e($nextStep['label']) ?><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="label">Requests submitted</div>
        <div class="value"><?= (int) $stats['requests'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Orders in progress</div>
        <div class="value"><?= (int) $stats['orders'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Outstanding balance</div>
        <div class="value is-large"><?= money($stats['balance']) ?></div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h3>Recent requests</h3>
        <a href="<?= url('/user/requests/index.php') ?>" class="btn btn-outline btn-sm">View all</a>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Status</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentRequests as $r): ?>
                    <tr>
                        <td><?= e($r['product_name']) ?></td>
                        <td><?= (int) $r['quantity'] ?></td>
                        <td><span class="badge badge-<?= $r['status'] === 'pending' ? 'warning' : ($r['status'] === 'rejected' ? 'danger' : 'success') ?>"><?= e(request_status_label($r['status'])) ?></span></td>
                        <td><?= display_date($r['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentRequests): ?>
                    <tr>
                        <td colspan="4" class="empty-state">You haven't requested any products yet. <a href="<?= url('/products.php') ?>">Browse the catalog</a>.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h3>Recent orders</h3>
        <a href="<?= url('/user/orders/index.php') ?>" class="btn btn-outline btn-sm">View all</a>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentOrders as $o): ?>
                    <tr>
                        <td><?= e($o['product_name']) ?></td>
                        <td><?= money($o['total_amount']) ?></td>
                        <td><span class="badge badge-info"><?= e(order_status_label($o['status'])) ?></span></td>
                        <td><?= display_date($o['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentOrders): ?>
                    <tr>
                        <td colspan="4" class="empty-state">No orders yet. Orders appear here once a request is approved.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/user_layout_end.php'; ?>