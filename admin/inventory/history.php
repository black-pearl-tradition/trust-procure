<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$productId = (int) ($_GET['product_id'] ?? 0);
$products = $pdo->query("SELECT id, name FROM products WHERE status = 'active' ORDER BY name")->fetchAll();

$where = '';
$params = [];
if ($productId > 0) {
    $where = 'WHERE im.product_id = ?';
    $params[] = $productId;
}

$movements = [];
$ledgerAvailable = true;
try {
    $stmt = $pdo->prepare(
        "SELECT im.*, p.name AS product_name, u.full_name, po.po_number, o.id AS order_number
         FROM inventory_movements im
         JOIN products p ON p.id = im.product_id
         LEFT JOIN users u ON u.id = im.user_id
         LEFT JOIN purchase_orders po ON po.id = im.purchase_order_id
         LEFT JOIN orders o ON o.id = im.order_id
         $where
         ORDER BY im.created_at DESC, im.id DESC
         LIMIT 200"
    );
    $stmt->execute($params);
    $movements = $stmt->fetchAll();
} catch (PDOException $e) {
    $ledgerAvailable = false;
}

$labels = ['received' => 'Received', 'shipped' => 'Shipped', 'adjustment' => 'Adjustment', 'return' => 'Return'];
$pageTitle = 'Inventory History';
include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Inventory history</h2>
            <p class="muted mb-0">Every stock movement recorded by the system.</p>
        </div>
        <a href="<?= url('/admin/inventory/index.php') ?>" class="btn btn-outline btn-sm">Inventory overview</a>
    </div>
    <div class="panel-body">
        <?php if (!$ledgerAvailable): ?>
            <div class="alert alert-warning">Inventory history is not available yet. Apply the inventory ledger section from <code>config/migrations.sql</code>, then reload this page.</div>
        <?php endif; ?>
        <form method="get" class="filter-bar" style="margin-bottom: 0;">
            <label for="history-product" class="visually-hidden">Filter by product</label>
            <select id="history-product" name="product_id" aria-label="Filter by product">
                <option value="0">All products</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?= (int) $product['id'] ?>" <?= $productId === (int) $product['id'] ? 'selected' : '' ?>><?= e($product['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        </form>
    </div>
    <div class="panel-body flush">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Movement</th>
                        <th>Change</th>
                        <th>Source / reason</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $movement): ?>
                        <tr>
                            <td><?= e(display_date($movement['created_at'])) ?></td>
                            <td><a href="<?= url('/admin/products/view.php?id=' . $movement['product_id']) ?>"><?= e($movement['product_name']) ?></a></td>
                            <td><?= e($labels[$movement['movement_type']] ?? ucfirst($movement['movement_type'])) ?></td>
                            <td><strong class="<?= $movement['quantity_change'] > 0 ? 'text-success' : 'text-danger' ?>"><?= $movement['quantity_change'] > 0 ? '+' : '' ?><?= (int) $movement['quantity_change'] ?></strong></td>
                            <td>
                                <?= e($movement['note'] ?: '—') ?>
                                <?php if ($movement['po_number']): ?><small class="muted d-block">PO <?= e($movement['po_number']) ?></small><?php endif; ?>
                                <?php if ($movement['order_number']): ?><small class="muted d-block">Order #<?= (int) $movement['order_number'] ?></small><?php endif; ?>
                            </td>
                            <td><?= e($movement['full_name'] ?: 'System') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$movements): ?><tr>
                            <td colspan="6" class="empty-state"><?= $ledgerAvailable ? 'No inventory movements recorded yet.' : 'The inventory ledger table has not been created.' ?></td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>