<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ?"
);
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    set_flash('error', 'Product not found.');
    redirect('/admin/products/index.php');
}

$pageTitle = $product['name'];

$gallery = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order');
$gallery->execute([$id]);
$gallery = $gallery->fetchAll();

$canSeeCost = is_admin();
$suppliers = $pdo->prepare(
    "SELECT sp.*, s.name AS supplier_name, s.id AS supplier_id, s.status AS supplier_status
     FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id
     WHERE sp.product_id = ? ORDER BY s.name"
);
$suppliers->execute([$id]);
$suppliers = $suppliers->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2><?= e($product['name']) ?></h2>
        <div class="table-actions">
            <a href="<?= url('/admin/products/edit.php?id=' . $product['id']) ?>" class="btn btn-outline btn-sm">Edit</a>
            <a href="<?= url('/product_details.php?id=' . $product['id']) ?>" class="btn btn-outline btn-sm" target="_blank">View on site</a>
        </div>
    </div>
    <div class="panel-body">
        <div class="pd-grid" style="gap: 32px;">
            <div class="pd-image" style="max-width: 260px;">
                <?php if ($product['image']): ?>
                    <img src="<?= url('/uploads/products/' . e($product['image'])) ?>" alt="">
                <?php else: ?>
                    <span>No image</span>
                <?php endif; ?>
            </div>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="label">Category</div>
                    <div class="value"><?= e($product['category_name'] ?? '—') ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Brand</div>
                    <div class="value"><?= e($product['brand'] ?: '—') ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Price</div>
                    <div class="value"><?= money($product['price']) ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Stock quantity</div>
                    <div class="value"><?= (int) $product['stock_quantity'] ?> units</div>
                </div>
                <div class="detail-item">
                    <div class="label">Availability</div>
                    <div class="value"><?= e(stock_status_label($product['stock_status'])) ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Low-stock alert at</div>
                    <div class="value"><?= (int) $product['low_stock_threshold'] ?> units</div>
                </div>
                <div class="detail-item">
                    <div class="label">Inventory history</div>
                    <div class="value"><a href="<?= url('/admin/inventory/history.php?product_id=' . $product['id']) ?>">View stock movements</a></div>
                </div>
                <div class="detail-item">
                    <div class="label">Deposit required</div>
                    <div class="value"><?= $product['is_high_end'] ? (int) $product['deposit_percentage'] . '%' : 'No — paid in full' ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Status</div>
                    <div class="value"><span class="badge badge-<?= $product['status'] === 'active' ? 'success' : 'neutral' ?>"><?= ucfirst(e($product['status'])) ?></span></div>
                </div>
                <div class="detail-item">
                    <div class="label">Added</div>
                    <div class="value"><?= display_date($product['created_at']) ?></div>
                </div>
            </div>
        </div>
        <hr class="divider">
        <div class="detail-item">
            <div class="label">Description</div>
            <div class="value"><?= nl2br(e($product['description'])) ?></div>
        </div>
        <?php if ($product['specifications']): ?>
            <div class="detail-item" style="margin-top: 16px;">
                <div class="label">Specifications</div>
                <div class="value"><?= e($product['specifications']) ?></div>
            </div>
        <?php endif; ?>
        <?php if ($gallery): ?>
            <hr class="divider">
            <div class="detail-item">
                <div class="label">Gallery (<?= count($gallery) ?>)</div>
                <div class="gallery-grid" style="margin-top: 10px;">
                    <?php foreach ($gallery as $g): ?>
                        <div class="gallery-thumb"><img src="<?= url('/uploads/products/' . e($g['image'])) ?>" alt=""></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>