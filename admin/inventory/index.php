<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Inventory';
$search = trim($_GET['q'] ?? '');
$categoryId = (int) ($_GET['category'] ?? 0);
$stockFilter = $_GET['stock'] ?? 'all';

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$where = ['p.status = \'active\''];
$params = [];
if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.brand LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($categoryId > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $categoryId;
}
if ($stockFilter === 'out') {
    $where[] = "p.stock_status <> 'made_to_order' AND p.stock_quantity <= p.reserved_quantity";
} elseif ($stockFilter === 'low') {
    $where[] = "p.stock_status <> 'made_to_order' AND (p.stock_quantity - p.reserved_quantity) > 0 AND (p.stock_quantity - p.reserved_quantity) <= p.low_stock_threshold";
} elseif ($stockFilter === 'available') {
    $where[] = "p.stock_status = 'made_to_order' OR p.stock_quantity > p.reserved_quantity";
}

$whereSql = implode(' AND ', $where);
$stmt = $pdo->prepare(
    "SELECT p.id, p.name, p.brand, p.image, p.stock_quantity, p.reserved_quantity, p.low_stock_threshold, p.stock_status, c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $whereSql
    ORDER BY (p.stock_quantity - p.reserved_quantity) ASC, p.name ASC"
);
$stmt->execute($params);
$products = $stmt->fetchAll();

$summary = $pdo->query(
    "SELECT COUNT(*) AS product_count,
            COALESCE(SUM(GREATEST(stock_quantity - reserved_quantity, 0)), 0) AS unit_count,
            COALESCE(SUM(reserved_quantity), 0) AS reserved_count,
            SUM(stock_status <> 'made_to_order' AND stock_quantity <= reserved_quantity) AS out_count,
            SUM(stock_status <> 'made_to_order' AND (stock_quantity - reserved_quantity) > 0 AND (stock_quantity - reserved_quantity) <= low_stock_threshold) AS low_count
     FROM products WHERE status = 'active'"
)->fetch();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="label">Active products</div>
        <div class="value"><?= (int) $summary['product_count'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Units available</div>
        <div class="value"><?= (int) $summary['unit_count'] ?></div>
    </div>
    <div class="stat-card inventory-reserved-stat">
        <div class="label">Units reserved</div>
        <div class="value"><?= (int) $summary['reserved_count'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Low stock</div>
        <div class="value"><?= (int) $summary['low_count'] ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Out of stock</div>
        <div class="value"><?= (int) $summary['out_count'] ?></div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Inventory overview</h2>
            <p class="muted mb-0">Track available units across the active catalog.</p>
        </div>
        <div class="table-actions">
            <?php if (is_admin()): ?>
                <a href="<?= url('/admin/inventory/recommendations.php') ?>" class="btn btn-outline btn-sm">
                    <i class="bi bi-arrow-repeat me-1"></i> Reorder suggestions
                </a>
                <a href="<?= url('/admin/purchase_orders/low_stock.php') ?>" class="btn btn-accent btn-sm">
                    <i class="bi bi-lightning-charge me-1"></i> Restock Low Inventory
                </a>
            <?php endif; ?>
            <a href="<?= url('/admin/inventory/history.php') ?>" class="btn btn-outline btn-sm">Movement history</a>
            <?php if (is_admin()): ?><a href="<?= url('/admin/purchase_orders/index.php') ?>" class="btn btn-outline btn-sm">Purchase orders</a><?php endif; ?>
        </div>
    </div>
    <div class="panel-body">
        <form method="get" class="filter-bar" style="margin-bottom: 0;">
            <label for="inventory-search" class="visually-hidden">Search inventory</label>
            <input type="search" id="inventory-search" name="q" placeholder="Search by product or brand" value="<?= e($search) ?>">
            <select name="category" aria-label="Filter by category">
                <option value="0">All categories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="stock" aria-label="Filter by stock level">
                <option value="all" <?= $stockFilter === 'all' ? 'selected' : '' ?>>All stock levels</option>
                <option value="available" <?= $stockFilter === 'available' ? 'selected' : '' ?>>Available</option>
                <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low stock</option>
                <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of stock</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
            <?php if ($search !== '' || $categoryId > 0 || $stockFilter !== 'all'): ?><a href="<?= url('/admin/inventory/index.php') ?>" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="panel-body flush">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Quantity</th>
                        <th>Alert at</th>
                        <th>Availability</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($product['image']): ?><img src="<?= url('/uploads/products/' . e($product['image'])) ?>" alt="" style="width: 44px; height: 44px; object-fit: cover; border-radius: 8px;"><?php endif; ?>
                                    <span><strong><?= e($product['name']) ?></strong><?php if ($product['brand']): ?><small class="muted d-block"><?= e($product['brand']) ?></small><?php endif; ?></span>
                                </div>
                            </td>
                            <td><?= e($product['category_name'] ?? '—') ?></td>
                            <td><strong><?= max(0, (int) $product['stock_quantity'] - (int) $product['reserved_quantity']) ?></strong> available<?php if ((int) $product['reserved_quantity'] > 0): ?><small class="muted d-block"><?= (int) $product['reserved_quantity'] ?> reserved</small><?php endif; ?></td>
                            <td><?= (int) $product['low_stock_threshold'] ?> units</td>
                            <td><span class="badge <?= e(stock_status_badge_class($product['stock_status'])) ?>"><?= e(stock_status_label($product['stock_status'])) ?></span></td>
                            <td>
                                <div class="table-actions"><a href="<?= url('/admin/products/view.php?id=' . $product['id']) ?>" class="btn btn-outline btn-sm icon-action" aria-label="View <?= e($product['name']) ?>" title="View product"><i class="bi bi-eye" aria-hidden="true"></i></a><a href="<?= url('/admin/inventory/adjust.php?id=' . $product['id']) ?>" class="btn btn-outline btn-sm icon-action" aria-label="Adjust stock for <?= e($product['name']) ?>" title="Adjust stock"><i class="bi bi-sliders" aria-hidden="true"></i></a></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$products): ?><tr>
                            <td colspan="6" class="empty-state">No inventory matches the selected filters.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>