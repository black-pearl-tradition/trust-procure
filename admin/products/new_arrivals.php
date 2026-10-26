<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'New Arrivals';
$search = trim($_GET['q'] ?? '');
$params = [];
$where = "WHERE p.is_new_arrival = 1";

if ($search !== '') {
    $where .= ' AND (p.name LIKE ? OR p.brand LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT p.*, c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     $where
     ORDER BY p.created_at DESC"
);
$stmt->execute($params);
$products = $stmt->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2><i class="bi bi-stars me-2" aria-hidden="true"></i>New arrivals</h2>
            <p class="muted mb-0">Products currently highlighted as recently added to the catalog.</p>
        </div>
        <a href="<?= url('/admin/products/add.php') ?>" class="btn btn-accent btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add product</a>
    </div>
    <div class="panel-body">
        <form method="get" class="filter-bar" style="margin-bottom: 0;">
            <label for="new-arrivals-search" class="visually-hidden">Search new arrivals</label>
            <input type="search" id="new-arrivals-search" name="q" placeholder="Search by name or brand" value="<?= e($search) ?>">
            <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-search me-1" aria-hidden="true"></i>Search</button>
            <?php if ($search !== ''): ?><a href="<?= url('/admin/products/new_arrivals.php') ?>" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="panel-body flush">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Deposit</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Added</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($product['image']): ?><img src="<?= url('/uploads/products/' . e($product['image'])) ?>" alt="" style="width: 48px; height: 48px; object-fit: cover; border-radius: 8px;"><?php endif; ?>
                                    <span><strong><?= e($product['name']) ?></strong><?php if ($product['brand']): ?><small class="muted d-block"><?= e($product['brand']) ?></small><?php endif; ?></span>
                                </div>
                            </td>
                            <td><?= e($product['category_name'] ?? '—') ?></td>
                            <td><?= money($product['price']) ?></td>
                            <td><?= $product['is_high_end'] ? (int) $product['deposit_percentage'] . '%' : 'Full payment' ?></td>
                            <td><span class="badge <?= e(stock_status_badge_class($product['stock_status'])) ?>"><?= e(stock_status_label($product['stock_status'])) ?></span></td>
                            <td><span class="badge badge-<?= $product['status'] === 'active' ? 'success' : 'neutral' ?>"><?= ucfirst(e($product['status'])) ?></span></td>
                            <td><?= display_date($product['created_at'], 'd M Y') ?></td>
                            <td>
                                <div class="table-actions"><a href="<?= url('/admin/products/view.php?id=' . $product['id']) ?>" class="btn btn-outline btn-sm icon-action" aria-label="View <?= e($product['name']) ?>" title="View product"><i class="bi bi-eye" aria-hidden="true"></i></a><a href="<?= url('/admin/products/edit.php?id=' . $product['id']) ?>" class="btn btn-outline btn-sm icon-action" aria-label="Edit <?= e($product['name']) ?>" title="Edit product"><i class="bi bi-pencil" aria-hidden="true"></i></a></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$products): ?><tr>
                            <td colspan="8" class="empty-state"><i class="bi bi-stars fs-2 d-block mb-2" aria-hidden="true"></i><?= $search !== '' ? 'No new arrivals match your search.' : 'No products are marked as new arrivals yet.' ?><br><a href="<?= url('/admin/products/add.php') ?>">Add a product</a> or mark one as a new arrival.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-meta muted"><?= $total ?> <?= $total === 1 ? 'product' : 'products' ?> marked as new arrival</div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>