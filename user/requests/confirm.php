<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/products.php');
}
verify_csrf('/products.php');

$productId = (int) ($_POST['product_id'] ?? 0);
$quantity = max(1, (int) ($_POST['quantity'] ?? 1));
$notes = trim($_POST['notes'] ?? '');
$deliveryAddress = trim($_POST['delivery_address'] ?? '');

$stmt = $pdo->prepare(
    "SELECT p.*, c.name AS category_name FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.id = ? AND p.status = 'active'"
);
$stmt->execute([$productId]);
$product = $stmt->fetch();

if (!$product) {
    set_flash('error', 'That product is not available for request.');
    redirect('/products.php');
}

$userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$userStmt->execute([$_SESSION['user_id']]);
$customer = $userStmt->fetch();

$total = $product['price'] * $quantity;
$depositPct = $product['is_high_end'] ? (int) $product['deposit_percentage'] : 100;
$deposit = round($total * ($depositPct / 100));
$balance = $total - $deposit;

$pageTitle = 'Confirm Your Purchase Order';
include __DIR__ . '/../../includes/user_layout_start.php';
?>

<div class="purchase-order-wrap">
    <div class="purchase-order-header">
        <div>
            <div class="section-kicker">Order preview</div>
            <h1>Review your purchase order</h1>
        </div>
        <span class="purchase-order-state">Awaiting approval</span>
    </div>

    <div class="panel">
        <div class="panel-head"><h2><?= e($product['name']) ?></h2></div>
        <div class="panel-body">
            <p class="mb-0">Please check the details below before submitting. Once submitted, this request goes to our team for approval.</p>

            <hr class="divider">

            <h3>Product</h3>
            <div class="detail-grid">
                <div class="detail-item"><div class="label">Product</div><div class="value"><?= e($product['name']) ?></div></div>
                <div class="detail-item"><div class="label">Brand / Category</div><div class="value"><?= e($product['brand'] ?: '—') ?> / <?= e($product['category_name'] ?: 'Uncategorised') ?></div></div>
                <div class="detail-item"><div class="label">Quantity</div><div class="value"><?= (int) $quantity ?></div></div>
                <div class="detail-item"><div class="label">Unit price</div><div class="value"><?= money($product['price']) ?></div></div>
            </div>

        <hr class="divider">

        <h3>Customer details</h3>
        <div class="detail-grid">
            <div class="detail-item"><div class="label">Name</div><div class="value"><?= e($customer['full_name']) ?></div></div>
            <div class="detail-item"><div class="label">Email</div><div class="value"><?= e($customer['email']) ?></div></div>
            <div class="detail-item"><div class="label">Phone</div><div class="value"><?= e($customer['phone'] ?: '—') ?></div></div>
            <div class="detail-item"><div class="label">Delivery address</div><div class="value"><?= e($deliveryAddress ?: 'Not provided — we\'ll confirm with you') ?></div></div>
        </div>

        <?php if ($notes): ?>
        <hr class="divider">
        <h3>Notes</h3>
        <p><?= nl2br(e($notes)) ?></p>
        <?php endif; ?>

        <hr class="divider">

        <h3>Payment / deposit information</h3>
        <div class="deposit-box">
            <div class="row"><span>Total order amount</span><strong><?= money($total) ?></strong></div>
            <?php if ($product['is_high_end']): ?>
                <div class="row"><span>Deposit due once approved (<?= $depositPct ?>%)</span><strong><?= money($deposit) ?></strong></div>
                <div class="row total"><span>Balance on collection</span><strong><?= money($balance) ?></strong></div>
            <?php else: ?>
                <div class="row total"><span>Payable in full once approved</span><strong><?= money($deposit) ?></strong></div>
            <?php endif; ?>
        </div>
        <p class="hint" style="margin-top: 10px;">No payment is collected at this stage. You'll be asked to pay once an administrator approves this request.</p>

        <hr class="divider">

            <form method="post" action="<?= url('/user/requests/add.php') ?>" data-ajax-submit="true">
                <?= csrf_field() ?>
                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                <input type="hidden" name="quantity" value="<?= (int) $quantity ?>">
                <input type="hidden" name="notes" value="<?= e($notes) ?>">
                <input type="hidden" name="delivery_address" value="<?= e($deliveryAddress) ?>">
                <div class="table-actions">
                    <button type="submit" class="btn btn-accent">Confirm &amp; submit purchase order</button>
                    <a href="<?= url('/product_details.php?id=' . $product['id']) ?>" class="btn btn-outline">Go back and edit</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/user_layout_end.php'; ?>
