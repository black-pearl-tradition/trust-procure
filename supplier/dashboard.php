<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_supplier();

$pageTitle = 'Dashboard';

// Resolve the supplier record for the current user via the actual supplier/account link.
$supplier = get_supplier_for_user((int) $_SESSION['user_id']);

// Get counts
$productCount = 0;
$completedOrders = 0;
$pendingPOs = 0;
$awaitingResponse = 0;
$pendingProductSubmissions = 0;
$approvedProductSubmissions = 0;
$rejectedProductSubmissions = 0;
$recentSubmissions = [];

if ($supplier) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM supplier_products WHERE supplier_id = ?');
    $stmt->execute([$supplier['id']]);
    $productCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ? AND status = "received"');
    $stmt->execute([$supplier['id']]);
    $completedOrders = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ? AND status IN ("submitted", "approved", "sent", "partially_received")');
    $stmt->execute([$supplier['id']]);
    $pendingPOs = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ? AND status = "sent" AND supplier_response = "pending"');
    $stmt->execute([$supplier['id']]);
    $awaitingResponse = (int) $stmt->fetchColumn();

    $submissionCountsStmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS submission_count
         FROM supplier_product_submissions
         WHERE supplier_id = ?
         GROUP BY status"
    );
    $submissionCountsStmt->execute([$supplier['id']]);
    foreach ($submissionCountsStmt->fetchAll() as $submissionCount) {
        if ($submissionCount['status'] === 'pending') $pendingProductSubmissions = (int) $submissionCount['submission_count'];
        if ($submissionCount['status'] === 'approved') $approvedProductSubmissions = (int) $submissionCount['submission_count'];
        if ($submissionCount['status'] === 'rejected') $rejectedProductSubmissions = (int) $submissionCount['submission_count'];
    }

    $recentSubmissionsStmt = $pdo->prepare(
        'SELECT name, supplier_price, status, review_note, created_at
         FROM supplier_product_submissions
         WHERE supplier_id = ?
         ORDER BY created_at DESC
         LIMIT 5'
    );
    $recentSubmissionsStmt->execute([$supplier['id']]);
    $recentSubmissions = $recentSubmissionsStmt->fetchAll();
}

// Get recent purchase orders
$recentPOs = [];
if ($supplier) {
    $stmt = $pdo->prepare(
        "SELECT po.*, 
            (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS item_count
         FROM purchase_orders po 
         WHERE po.supplier_id = ? 
         ORDER BY po.created_at DESC LIMIT 5"
    );
    $stmt->execute([$supplier['id']]);
    $recentPOs = $stmt->fetchAll();
}

include __DIR__ . '/../includes/supplier_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>Welcome back, <?= e($_SESSION['user_name']) ?></h2>
        <?php if ($supplier): ?><a href="<?= url('/supplier/products/submit.php') ?>" class="btn btn-accent btn-sm">Submit a product</a><?php endif; ?>
    </div>
    <div class="panel-body flush">
        <div class="supplier-stat-grid">
            <div class="supplier-stat-card">
                <div class="meta">Products Supplied</div>
                <div class="value"><?= $productCount ?></div>
            </div>
            <div class="supplier-stat-card">
                <div class="meta">Product Submissions Pending</div>
                <div class="value"><?= $pendingProductSubmissions ?></div>
                <small class="meta-note"><?= $approvedProductSubmissions ?> approved · <?= $rejectedProductSubmissions ?> declined</small>
            </div>
            <div class="supplier-stat-card">
                <div class="meta">Active Orders</div>
                <div class="value"><?= $pendingPOs ?></div>
            </div>
            <div class="supplier-stat-card">
                <div class="meta">Orders Completed</div>
                <div class="value"><?= $completedOrders ?></div>
            </div>
            <div class="supplier-stat-card">
                <div class="meta">Awaiting Your Response</div>
                <div class="value"><?= $awaitingResponse ?></div>
                <?php if ($awaitingResponse > 0): ?>
                    <a href="<?= url('/supplier/purchase_orders/index.php?response=pending') ?>" class="btn btn-outline btn-sm">Review orders</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($supplier): ?>
        <div class="panel supplier-info-panel">
            <div class="panel-head">
                <h2>Quick Info</h2>
            </div>
            <div class="panel-body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="label">Company Name</div>
                        <div class="value"><?= e($supplier['name']) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Contact Person</div>
                        <div class="value"><?= e($supplier['contact_person'] ?: '—') ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Email</div>
                        <div class="value"><?= e($supplier['email']) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Phone</div>
                        <div class="value"><?= e($supplier['phone'] ?: '—') ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Status</div>
                        <div class="value"><span class="badge badge-<?= $supplier['status'] === 'active' ? 'success' : 'neutral' ?>"><?= ucfirst(e($supplier['status'])) ?></span></div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="panel supplier-warning-panel">
            <div class="panel-body">
                <i class="bi bi-exclamation-triangle alert-icon"></i>
                <p class="mb-0" style="margin-top: 16px; font-size: 1.1rem;">No supplier profile found for your account.</p>
                <p class="muted" style="margin-top: 8px;">Please contact the admin to set up your supplier profile.</p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($supplier && $recentSubmissions): ?>
        <div class="panel supplier-info-panel">
            <div class="panel-head">
                <h2>Recent product submissions</h2>
                <a href="<?= url('/supplier/products/index.php') ?>" class="btn btn-outline btn-sm">View products</a>
            </div>
            <div class="panel-body flush">
                <table>
                    <thead><tr><th>Product</th><th>Your quoted price</th><th>Status</th><th>Submitted</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentSubmissions as $submission): ?>
                            <tr>
                                <td><?= e($submission['name']) ?><?php if ($submission['status'] === 'rejected' && $submission['review_note']): ?><small class="muted d-block"><?= e($submission['review_note']) ?></small><?php endif; ?></td>
                                <td><?= money($submission['supplier_price']) ?></td>
                                <td><span class="badge badge-<?= $submission['status'] === 'approved' ? 'success' : ($submission['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= ucfirst(e($submission['status'])) ?></span></td>
                                <td><?= display_date($submission['created_at'], 'd M Y') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($recentPOs): ?>
        <div class="panel supplier-info-panel">
            <div class="panel-head">
                <h2>Recent Purchase Orders</h2>
                <a href="<?= url('/supplier/purchase_orders/index.php') ?>" class="btn btn-outline btn-sm">View all</a>
            </div>
            <div class="panel-body flush">
                <table>
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Status</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPOs as $po): ?>
                            <tr>
                                <td><a href="<?= url('/supplier/purchase_orders/view.php?id=' . $po['id']) ?>"><?= e($po['po_number']) ?></a></td>
                                <td><span class="badge badge-<?= po_status_badge_class($po['status']) ?>"><?= e(po_status_label($po['status'])) ?></span></td>
                                <td><?= (int) $po['item_count'] ?></td>
                                <td><?= money($po['grand_total']) ?></td>
                                <td><?= display_date($po['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

<?php include __DIR__ . '/../includes/supplier_layout_end.php'; ?>