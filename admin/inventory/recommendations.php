<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Reorder suggestions';

$products = $pdo->query(
    "SELECT p.id,
            p.name,
            p.brand,
            p.image,
            p.stock_quantity,
            p.reserved_quantity,
            p.low_stock_threshold,
            GREATEST(0, p.stock_quantity - p.reserved_quantity) AS available_stock,
            c.name AS category_name,
            (
                SELECT sp.supplier_price
                FROM supplier_products sp
                JOIN suppliers s ON s.id = sp.supplier_id
                WHERE sp.product_id = p.id AND s.status = 'active'
                ORDER BY CASE s.performance_status WHEN 'preferred' THEN 0 WHEN 'watchlist' THEN 1 ELSE 2 END ASC,
                         sp.supplier_price ASC,
                         sp.lead_time_days ASC,
                         s.name ASC
                LIMIT 1
            ) AS preferred_supplier_price,
            (
                SELECT s.id
                FROM supplier_products sp
                JOIN suppliers s ON s.id = sp.supplier_id
                WHERE sp.product_id = p.id AND s.status = 'active'
                ORDER BY CASE s.performance_status WHEN 'preferred' THEN 0 WHEN 'watchlist' THEN 1 ELSE 2 END ASC,
                         sp.supplier_price ASC,
                         sp.lead_time_days ASC,
                         s.name ASC
                LIMIT 1
            ) AS preferred_supplier_id,
            (
                SELECT s.name
                FROM supplier_products sp
                JOIN suppliers s ON s.id = sp.supplier_id
                WHERE sp.product_id = p.id AND s.status = 'active'
                ORDER BY CASE s.performance_status WHEN 'preferred' THEN 0 WHEN 'watchlist' THEN 1 ELSE 2 END ASC,
                         sp.supplier_price ASC,
                         sp.lead_time_days ASC,
                         s.name ASC
                LIMIT 1
            ) AS preferred_supplier_name,
            (
                SELECT sp.lead_time_days
                FROM supplier_products sp
                JOIN suppliers s ON s.id = sp.supplier_id
                WHERE sp.product_id = p.id AND s.status = 'active'
                ORDER BY CASE s.performance_status WHEN 'preferred' THEN 0 WHEN 'watchlist' THEN 1 ELSE 2 END ASC,
                         sp.supplier_price ASC,
                         sp.lead_time_days ASC,
                         s.name ASC
                LIMIT 1
            ) AS preferred_supplier_lead_time
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.status = 'active'
       AND p.stock_status <> 'made_to_order'
       AND GREATEST(0, p.stock_quantity - p.reserved_quantity) <= p.low_stock_threshold
     ORDER BY available_stock ASC, p.name ASC"
)->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Reorder suggestions</h2>
            <p class="muted mb-0">Products at or below their alert threshold with a suggested replenishment quantity and preferred supplier.</p>
        </div>
        <div class="table-actions">
            <a href="<?= url('/admin/inventory/index.php') ?>" class="btn btn-outline btn-sm">Inventory overview</a>
            <a href="<?= url('/admin/purchase_orders/low_stock.php') ?>" class="btn btn-accent btn-sm">Create restock PO</a>
        </div>
    </div>

    <div class="panel-body flush">
        <?php if (!$products): ?>
            <div class="empty-state" style="padding: 36px 20px;">
                <i class="bi bi-check-circle text-success" style="font-size: 2rem; display: block; margin-bottom: 12px;"></i>
                <h3>Everything is stocked well.</h3>
                <p class="muted mb-0">No reorder suggestions are currently needed.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Available</th>
                            <th>Threshold</th>
                            <th>Preferred supplier</th>
                            <th>Suggested order</th>
                            <th>Est. cost</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <?php
                            $available = (int) $product['available_stock'];
                            $threshold = (int) $product['low_stock_threshold'];
                            $suggestedQty = max(1, ($threshold * 2) - $available);
                            $unitCost = (float) ($product['preferred_supplier_price'] ?? 0);
                            $estimatedCost = $unitCost * $suggestedQty;
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($product['image']): ?>
                                            <img src="<?= url('/uploads/products/' . e($product['image'])) ?>" alt="" style="width: 42px; height: 42px; object-fit: cover; border-radius: 8px;">
                                        <?php endif; ?>
                                        <div>
                                            <strong><?= e($product['name']) ?></strong>
                                            <?php if ($product['brand']): ?><div class="muted small"><?= e($product['brand']) ?></div><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?= e($product['category_name'] ?? '—') ?></td>
                                <td>
                                    <span class="badge badge-warning"><?= $available ?> units</span>
                                </td>
                                <td><?= $threshold ?> units</td>
                                <td>
                                    <?php if ($product['preferred_supplier_name']): ?>
                                        <strong><?= e($product['preferred_supplier_name']) ?></strong>
                                        <?php if ($product['preferred_supplier_lead_time']): ?>
                                            <div class="small muted">Lead time: <?= (int) $product['preferred_supplier_lead_time'] ?> days</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-danger">No supplier linked</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $suggestedQty ?> units</td>
                                <td>
                                    <?php if ($unitCost > 0): ?>
                                        <?= money($estimatedCost) ?>
                                        <div class="small muted"><?= money($unitCost) ?> / unit</div>
                                    <?php else: ?>
                                        <span class="muted">Cost not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?= url('/admin/purchase_orders/low_stock.php?product_id=' . (int) $product['id'] . '&supplier_id=' . (int) ($product['preferred_supplier_id'] ?? 0)) ?>" class="btn btn-accent btn-sm">Quick PO</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
