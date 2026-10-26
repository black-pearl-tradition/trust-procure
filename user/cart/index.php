<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

$pageTitle = 'Request cart';
$cart = $_SESSION['cart'] ?? [];
$items = [];
$total = 0;
$deposit = 0;

if ($cart) {
    $ids = array_map('intval', array_keys($cart));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id IN ($placeholders) AND p.status = 'active'");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $product) {
        $quantity = max(1, (int) ($cart[$product['id']] ?? 1));
        $lineTotal = (float) $product['price'] * $quantity;
        $depositPercentage = $product['is_high_end'] ? (int) $product['deposit_percentage'] : 100;
        $lineDeposit = round($lineTotal * ($depositPercentage / 100));
        $product['quantity'] = $quantity;
        $product['line_total'] = $lineTotal;
        $product['line_deposit'] = $lineDeposit;
        $items[] = $product;
        $total += $lineTotal;
        $deposit += $lineDeposit;
    }
}
$balance = max($total - $deposit, 0);

include __DIR__ . '/../../includes/user_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2><i class="bi bi-cart3 me-2" aria-hidden="true"></i>Request cart</h2>
    </div>
    <div class="panel-body">
        <?php if ($items): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit price</th>
                            <th>Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><strong><?= e($item['name']) ?></strong><br><small class="muted"><?= e($item['category_name'] ?? 'Uncategorised') ?></small></td>
                                <td><?= (int) $item['quantity'] ?></td>
                                <td><?= money($item['price']) ?></td>
                                <td><?= money($item['line_total']) ?></td>
                                <td>
                                    <form method="post" action="<?= url('/user/cart/remove.php') ?>">
                                        <?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Remove <?= e($item['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="cart-summary">
                <div><span>Total request value</span><strong><?= money($total) ?></strong></div>
                <div><span>Deposit required after approval</span><strong><?= money($deposit) ?></strong></div>
                <div><span>Balance due later</span><strong><?= money($balance) ?></strong></div>
            </div>
            <div class="row g-3 mb-4" style="max-width: 720px;">
                <div class="col-12">
                    <label for="delivery_address" class="form-label fw-semibold">Delivery address</label>
                    <textarea id="delivery_address" name="delivery_address" form="cart-submit-form" class="form-control" placeholder="Where should this order be delivered?" required></textarea>
                </div>
                <div class="col-12">
                    <label for="cart_notes" class="form-label fw-semibold">Request notes <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea id="cart_notes" name="notes" form="cart-submit-form" class="form-control" placeholder="Preferred colours, timing, or other details"></textarea>
                </div>
            </div>
            <form method="post" action="<?= url('/user/cart/submit.php') ?>" id="cart-submit-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary"><i class="bi bi-send me-2" aria-hidden="true"></i>Continue request</button>
            </form>
        <?php else: ?>
            <div class="empty-state"><i class="bi bi-cart-x fs-2 d-block mb-2" aria-hidden="true"></i>Your request cart is empty.</div>
            <a href="<?= url('/products.php') ?>" class="btn btn-primary"><i class="bi bi-grid me-2" aria-hidden="true"></i>Browse products</a>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/user_layout_end.php'; ?>