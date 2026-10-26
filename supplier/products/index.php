<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_supplier();

$pageTitle = 'My Products';

// Get supplier info using the persisted supplier/account relationship.
$supplier = get_supplier_for_user((int) $_SESSION['user_id']);

$products = [];
$submissions = [];
if ($supplier) {
    $stmt = $pdo->prepare(
        "SELECT p.*, c.name AS category_name, sp.supplier_price, sp.warranty_covered, sp.lead_time_days, sp.notes AS supply_notes,
                (SELECT COUNT(*) FROM purchase_order_items poi 
                 JOIN purchase_orders po ON po.id = poi.purchase_order_id 
                 WHERE poi.product_id = p.id AND po.supplier_id = ?) AS times_ordered
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         JOIN supplier_products sp ON sp.product_id = p.id AND sp.supplier_id = ?
         ORDER BY p.name"
    );
    $stmt->execute([$supplier['id'], $supplier['id']]);
    $products = $stmt->fetchAll();

    $submissionStmt = $pdo->prepare(
        'SELECT name, supplier_price, status, review_note, created_at
         FROM supplier_product_submissions
         WHERE supplier_id = ?
         ORDER BY created_at DESC
         LIMIT 20'
    );
    $submissionStmt->execute([$supplier['id']]);
    $submissions = $submissionStmt->fetchAll();
}

include __DIR__ . '/../../includes/supplier_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>Products I Supply</h2>
        <?php if ($supplier): ?><a href="<?= url('/supplier/products/submit.php') ?>" class="btn btn-accent btn-sm">Submit a product</a><?php endif; ?>
    </div>
    <div class="panel-body flush">
        <?php if (!$supplier): ?>
            <div class="alert alert-warning">No supplier profile found.</div>
        <?php elseif (!$products): ?>
            <div class="empty-state">
                <i class="bi bi-box-seam" style="font-size: 2rem;"></i>
                <p>No products linked to your supplier account yet.</p>
                <p class="muted">Submit a product for admin review to add it to your supply catalog.</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Your Price (UGX)</th>
                        <th>Warranty</th>
                        <th>Lead Time</th>
                        <th>Times Ordered</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <strong><?= e($product['name']) ?></strong>
                                <?php if ($product['brand']): ?>
                                    <div class="muted" style="font-size: 0.85rem;"><?= e($product['brand']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($product['category_name'] ?: '—') ?></td>
                            <td>
                                <?php if ($product['supplier_price'] !== null): ?>
                                    <span class="fw-semibold"><?= money($product['supplier_price']) ?></span>
                                <?php else: ?>
                                    <span class="muted">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $product['warranty_covered'] ? 'Yes' : 'No' ?></td>
                            <td><?= $product['lead_time_days'] ? $product['lead_time_days'] . ' days' : '—' ?></td>
                            <td><?= (int) $product['times_ordered'] ?></td>
                            <td>
                                <span class="badge badge-<?= $product['status'] === 'active' ? 'success' : 'neutral' ?>">
                                    <?= ucfirst(e($product['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if ($supplier && $submissions): ?>
    <div class="panel" style="margin-top: 20px;">
        <div class="panel-head">
            <h2>Product submissions</h2>
            <a href="<?= url('/supplier/products/submit.php') ?>" class="btn btn-outline btn-sm">Submit another</a>
        </div>
        <div class="panel-body flush">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Product</th><th>Your quoted price (UGX)</th><th>Status</th><th>Admin feedback</th><th>Submitted</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $submission): ?>
                            <tr>
                                <td><?= e($submission['name']) ?></td>
                                <td><?= money($submission['supplier_price']) ?></td>
                                <td><span class="badge badge-<?= $submission['status'] === 'approved' ? 'success' : ($submission['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= ucfirst(e($submission['status'])) ?></span></td>
                                <td><?= e($submission['review_note'] ?: '—') ?></td>
                                <td><?= display_date($submission['created_at'], 'd M Y') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/supplier_layout_end.php'; ?>