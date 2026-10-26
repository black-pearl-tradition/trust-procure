<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Supplier Product Submissions';
$statusFilter = $_GET['status'] ?? 'pending';
$allowedStatuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/admin/supplier_submissions/index.php');
    $submissionId = (int) ($_POST['submission_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($submissionId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        set_flash('error', 'Invalid product submission action.');
        redirect('/admin/supplier_submissions/index.php');
    }

    $publicPriceInput = trim($_POST['public_price'] ?? '');
    $publicPrice = preg_match('/^\d+(?:\.\d{1,2})?$/', $publicPriceInput) === 1
        ? (float) $publicPriceInput
        : false;
    $reviewNote = trim($_POST['review_note'] ?? '');
    if ($action === 'approve' && ($publicPrice === false || $publicPrice <= 0 || $publicPrice > 999999999999.99)) {
        set_flash('error', 'Enter a customer-facing catalog price greater than zero before approving.');
        redirect('/admin/supplier_submissions/index.php');
    }
    if ($action === 'reject' && $reviewNote === '') {
        set_flash('error', 'Provide a reason when rejecting a product submission.');
        redirect('/admin/supplier_submissions/index.php');
    }
    if (mb_strlen($reviewNote) > 500) {
        set_flash('error', 'Review feedback must be 500 characters or fewer.');
        redirect('/admin/supplier_submissions/index.php');
    }

    try {
        $pdo->beginTransaction();
        $submissionStmt = $pdo->prepare(
            'SELECT * FROM supplier_product_submissions WHERE id = ? FOR UPDATE'
        );
        $submissionStmt->execute([$submissionId]);
        $submission = $submissionStmt->fetch();

        if (!$submission || $submission['status'] !== 'pending') {
            $pdo->rollBack();
            set_flash('error', 'That submission is no longer awaiting review.');
            redirect('/admin/supplier_submissions/index.php');
        }

        $productId = null;
        if ($action === 'approve') {
            $slug = slugify($submission['name']) . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
            $productStmt = $pdo->prepare(
                "INSERT INTO products
                    (category_id, name, slug, brand, description, specifications, price, image, stock_quantity,
                     low_stock_threshold, stock_status, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 5, 'made_to_order', 'active')"
            );
            $productStmt->execute([
                $submission['category_id'],
                $submission['name'],
                $slug,
                $submission['brand'],
                $submission['description'],
                $submission['specifications'],
                $publicPrice,
                $submission['image'],
            ]);
            $productId = (int) $pdo->lastInsertId();

            $supplierProductStmt = $pdo->prepare(
                'INSERT INTO supplier_products (supplier_id, product_id, supplier_price, warranty_covered, lead_time_days, notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $supplierProductStmt->execute([
                $submission['supplier_id'],
                $productId,
                $submission['supplier_price'],
                $submission['warranty_covered'],
                $submission['lead_time_days'],
                $submission['notes'],
            ]);
        }

        $reviewStmt = $pdo->prepare(
            'UPDATE supplier_product_submissions
             SET status = ?, product_id = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ? AND status = ?'
        );
        $reviewStmt->execute([
            $action === 'approve' ? 'approved' : 'rejected',
            $productId,
            $reviewNote !== '' ? $reviewNote : null,
            $_SESSION['user_id'],
            $submissionId,
            'pending',
        ]);
        if ($reviewStmt->rowCount() !== 1) {
            throw new RuntimeException('The submission status changed during review.');
        }

        log_activity(
            $pdo,
            (int) $_SESSION['user_id'],
            'supplier_product_' . $action,
            ucfirst($action) . " supplier product submission #{$submissionId}: {$submission['name']}"
        );
        $pdo->commit();

        notify_supplier(
            (int) $submission['supplier_id'],
            $action === 'approve' ? 'Product submission approved' : 'Product submission declined',
            $action === 'approve'
                ? "{$submission['name']} was approved and added to the catalog."
                : "{$submission['name']} was not approved. Admin feedback: {$reviewNote}",
            '/supplier/products/index.php'
        );

        set_flash('success', $action === 'approve'
            ? 'Product approved and added to the catalog.'
            : 'Product submission rejected and feedback sent to the supplier.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    redirect('/admin/supplier_submissions/index.php?status=' . urlencode($statusFilter));
}

$whereSql = '';
$params = [];
if ($statusFilter !== 'all') {
    $whereSql = 'WHERE sub.status = ?';
    $params[] = $statusFilter;
}
$submissionsStmt = $pdo->prepare(
    "SELECT sub.*, s.name AS supplier_name, s.email AS supplier_email, c.name AS category_name
     FROM supplier_product_submissions sub
     JOIN suppliers s ON s.id = sub.supplier_id
     LEFT JOIN categories c ON c.id = sub.category_id
     $whereSql
     ORDER BY FIELD(sub.status, 'pending', 'rejected', 'approved'), sub.created_at DESC"
);
$submissionsStmt->execute($params);
$submissions = $submissionsStmt->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Supplier product submissions</h2>
            <p class="muted mb-0">Review supplier information and quoted costs. Approved products use an admin-set public price.</p>
        </div>
        <form method="get" class="table-actions">
            <label class="visually-hidden" for="submission-status">Filter submissions</label>
            <select id="submission-status" name="status" onchange="this.form.submit()">
                <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= ucfirst(e($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="panel-body flush">
        <?php if (!$submissions): ?>
            <div class="empty-state">No supplier product submissions <?= $statusFilter === 'all' ? 'yet' : 'in this status' ?>.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Product submission</th><th>Supplier</th><th>Quoted cost (UGX)</th><th>Status</th><th>Submitted</th><th>Review</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $submission): ?>
                            <tr>
                                <td>
                                    <strong><?= e($submission['name']) ?></strong>
                                    <?php if ($submission['brand']): ?><small class="muted d-block"><?= e($submission['brand']) ?></small><?php endif; ?>
                                    <?php if ($submission['category_name']): ?><small class="muted d-block"><?= e($submission['category_name']) ?></small><?php endif; ?>
                                    <?php if ($submission['image']): ?><a class="d-block mt-2" href="<?= url('/uploads/products/' . e($submission['image'])) ?>" target="_blank" rel="noopener">View submitted image</a><?php endif; ?>
                                    <?php if ($submission['description']): ?><details class="mt-2"><summary>Description and specifications</summary><p><?= nl2br(e($submission['description'])) ?></p><?php if ($submission['specifications']): ?><p><?= nl2br(e($submission['specifications'])) ?></p><?php endif; ?></details><?php endif; ?>
                                </td>
                                <td><?= e($submission['supplier_name']) ?><small class="muted d-block"><?= e($submission['supplier_email'] ?: '') ?></small></td>
                                <td><?= money($submission['supplier_price']) ?></td>
                                <td><span class="badge badge-<?= $submission['status'] === 'approved' ? 'success' : ($submission['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= ucfirst(e($submission['status'])) ?></span></td>
                                <td><?= display_date($submission['created_at'], 'd M Y') ?></td>
                                <td>
                                    <?php if ($submission['status'] === 'pending'): ?>
                                        <form method="post" class="supplier-submission-review-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="submission_id" value="<?= (int) $submission['id'] ?>">
                                            <label for="public-price-<?= (int) $submission['id'] ?>">Public catalog price</label>
                                            <input type="number" id="public-price-<?= (int) $submission['id'] ?>" name="public_price" min="0.01" step="0.01" required>
                                            <button type="submit" name="action" value="approve" class="btn btn-accent btn-sm" data-confirm="Approve and publish this product at the entered catalog price?">Approve</button>
                                        </form>
                                        <form method="post" class="supplier-submission-review-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="submission_id" value="<?= (int) $submission['id'] ?>">
                                            <label for="review-note-<?= (int) $submission['id'] ?>">Reason for rejection</label>
                                            <textarea id="review-note-<?= (int) $submission['id'] ?>" name="review_note" maxlength="500" required></textarea>
                                            <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm" data-confirm="Reject this supplier product submission?">Reject</button>
                                        </form>
                                    <?php elseif ($submission['review_note']): ?>
                                        <?= e($submission['review_note']) ?>
                                    <?php else: ?>
                                        <?= $submission['product_id'] ? 'Catalog product #' . (int) $submission['product_id'] : '—' ?>
                                    <?php endif; ?>
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
