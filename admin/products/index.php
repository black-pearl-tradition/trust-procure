<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Products';

$search = trim($_GET['q'] ?? '');
$page = current_page();
$perPage = 15;

$where = '';
$params = [];
if ($search !== '') {
    $where = " WHERE p.name LIKE ? OR p.brand LIKE ?";
    $params = ["%$search%", "%$search%"];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p" . $where);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$sql = "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id" . $where
    . " ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
foreach ($params as $i => $val) {
    $stmt->bindValue($i + 1, $val);
}
// Positional binding right through: mixing ? with :limit/:offset in one
// statement is rejected by PDO ("mixed named and positional parameters")
// whenever emulated prepares are off, which config/database.php turns off.
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>All products</h2>
        <a href="<?= url('/admin/products/add.php') ?>" class="btn btn-accent btn-sm">Add product</a>
    </div>
    <div class="panel-body">
        <form method="get" class="filter-bar" style="margin-bottom: 0;">
            <input type="search" name="q" placeholder="Search by name or brand" value="<?= e($search) ?>">
            <button type="submit" class="btn btn-outline btn-sm">Search</button>
        </form>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Deposit</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td><?= e($p['name']) ?><?php if ($p['brand']): ?><div class="muted" style="font-size: 0.8rem;"><?= e($p['brand']) ?></div><?php endif; ?><?php if (!empty($p['sku']) || !empty($p['barcode'])): ?><div class="muted" style="font-size: 0.75rem;"><?php if (!empty($p['sku'])): ?>SKU <?= e($p['sku']) ?><?php endif; ?><?php if (!empty($p['barcode'])): ?><?php if (!empty($p['sku'])): ?> &middot; <?php endif; ?>Barcode <?= e($p['barcode']) ?><?php endif; ?></div><?php endif; ?></td>
                        <td><?= e($p['category_name'] ?? '—') ?></td>
                        <td><?= money($p['price']) ?></td>
                        <td><?= (int) $p['stock_quantity'] ?> <span class="muted" style="font-size: 0.8rem;"><?= e(stock_status_label($p['stock_status'])) ?></span></td>
                        <td><?= $p['is_high_end'] ? (int) $p['deposit_percentage'] . '%' : '—' ?></td>
                        <td><span class="badge badge-<?= $p['status'] === 'active' ? 'success' : 'neutral' ?>"><?= ucfirst(e($p['status'])) ?></span></td>
                        <td>
                            <div class="table-actions">
                                <a href="<?= url('/admin/products/view.php?id=' . $p['id']) ?>" class="btn btn-outline btn-sm icon-action" aria-label="View <?= e($p['name']) ?>" title="View product"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                <a href="<?= url('/admin/products/edit.php?id=' . $p['id']) ?>" class="btn btn-outline btn-sm icon-action" aria-label="Edit <?= e($p['name']) ?>" title="Edit product"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                                <form method="post" action="<?= url('/admin/products/delete.php') ?>" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm icon-action" aria-label="Delete <?= e($p['name']) ?>" title="Delete product" data-confirm="Delete this product? This cannot be undone."><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$products): ?>
                    <tr>
                        <td colspan="7" class="empty-state">No products found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php render_pagination($page, $totalPages, '/admin/products/index.php', ['q' => $search]); ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>