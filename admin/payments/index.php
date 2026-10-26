<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Payments';
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));

// Status counts (global, unaffected by current filter/search so stats reflect whole register)
$statCounts = [
    'total'    => (int) $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn(),
    'pending'  => (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn(),
    'verified' => (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'verified'")->fetchColumn(),
    'rejected' => (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'rejected'")->fetchColumn(),
];
$pendingCount = $statCounts['pending'];

// Query construction with filters
$whereClauses = [];
$params = [];

if (in_array($statusFilter, ['pending', 'verified', 'rejected'], true)) {
    $whereClauses[] = "pay.status = ?";
    $params[] = $statusFilter;
}

if ($search !== '') {
    $searchTerm = '%' . $search . '%';
    $whereClauses[] = "(u.full_name LIKE ? OR u.email LIKE ? OR p.name LIKE ? OR pay.reference_note LIKE ? OR CAST(pay.order_id AS CHAR) LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereSql = $whereClauses ? (' WHERE ' . implode(' AND ', $whereClauses)) : '';

// Count total rows matching filters
$countSql = "SELECT COUNT(*)
             FROM payments pay
             JOIN users u ON u.id = pay.user_id
             JOIN orders o ON o.id = pay.order_id
             JOIN products p ON p.id = o.product_id" . $whereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$filteredTotal = (int) $countStmt->fetchColumn();

// Pagination
$perPage = 15;
$page = current_page();
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// Fetch paginated data
$dataSql = "SELECT pay.*, u.full_name, o.id AS order_id, p.name AS product_name
            FROM payments pay
            JOIN users u ON u.id = pay.user_id
            JOIN orders o ON o.id = pay.order_id
            JOIN products p ON p.id = o.product_id"
            . $whereSql
            . " ORDER BY (pay.status = 'pending') DESC, pay.created_at DESC
               LIMIT ? OFFSET ?";

$stmt = $pdo->prepare($dataSql);
$paramIdx = 1;
foreach ($params as $val) {
    $stmt->bindValue($paramIdx++, $val, PDO::PARAM_STR);
}
$stmt->bindValue($paramIdx++, (int) $perPage, PDO::PARAM_INT);
$stmt->bindValue($paramIdx++, (int) $offset, PDO::PARAM_INT);
$stmt->execute();
$payments = $stmt->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="account-shell">
    <div class="account-summary">
        <div class="account-summary-main">
            <div class="account-summary-meta">Payment queue</div>
            <div>
                <div class="section-kicker">Finance review</div>
                <h2>All payments</h2>
                <p>Review customer payment submissions, verify proof, and clear orders for fulfillment.</p>
            </div>
        </div>
        <span class="badge badge-warning"><?= $pendingCount ?> pending</span>
    </div>

    <div class="request-stat-grid">
        <div class="request-stat-card">
            <div class="label">Total</div>
            <div class="value"><?= $statCounts['total'] ?></div>
        </div>
        <div class="request-stat-card is-alert">
            <div class="label">Pending</div>
            <div class="value"><?= $statCounts['pending'] ?></div>
        </div>
        <div class="request-stat-card is-success">
            <div class="label">Verified</div>
            <div class="value"><?= $statCounts['verified'] ?></div>
        </div>
        <div class="request-stat-card is-danger">
            <div class="label">Rejected</div>
            <div class="value"><?= $statCounts['rejected'] ?></div>
        </div>
    </div>

    <div class="detail-progress-strip" aria-label="Payment operations flow">
        <span class="detail-progress-item active"><strong>01</strong> Review</span>
        <span class="detail-progress-item"><strong>02</strong> Verify</span>
        <span class="detail-progress-item"><strong>03</strong> Fulfill</span>
    </div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Payment register</h2>
            <?php if ($pendingCount > 0): ?><p class="admin-list-subtitle"><?= $pendingCount ?> payment<?= $pendingCount === 1 ? '' : 's' ?> need review.</p><?php endif; ?>
        </div>
    </div>
    <div class="panel-body">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
            <div class="filter-bar" style="margin-bottom: 0;">
                <a href="<?= url('/admin/payments/index.php' . ($search !== '' ? '?q=' . urlencode($search) : '')) ?>" class="btn btn-<?= $statusFilter === '' ? 'primary' : 'outline' ?> btn-sm">All</a>
                <a href="<?= url('/admin/payments/index.php?status=pending' . ($search !== '' ? '&q=' . urlencode($search) : '')) ?>" class="btn btn-<?= $statusFilter === 'pending' ? 'primary' : 'outline' ?> btn-sm">Pending</a>
                <a href="<?= url('/admin/payments/index.php?status=verified' . ($search !== '' ? '&q=' . urlencode($search) : '')) ?>" class="btn btn-<?= $statusFilter === 'verified' ? 'primary' : 'outline' ?> btn-sm">Verified</a>
                <a href="<?= url('/admin/payments/index.php?status=rejected' . ($search !== '' ? '&q=' . urlencode($search) : '')) ?>" class="btn btn-<?= $statusFilter === 'rejected' ? 'primary' : 'outline' ?> btn-sm">Rejected</a>
            </div>
            <form method="get" action="<?= url('/admin/payments/index.php') ?>" style="display: flex; gap: 8px; align-items: center;">
                <?php if ($statusFilter !== ''): ?>
                    <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
                <?php endif; ?>
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search customer, order, product, ref..." class="form-input form-input-sm" style="min-width: 240px;">
                <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-search me-1" aria-hidden="true"></i>Search</button>
                <?php if ($search !== ''): ?>
                    <a href="<?= url('/admin/payments/index.php' . ($statusFilter !== '' ? '?status=' . urlencode($statusFilter) : '')) ?>" class="btn btn-outline btn-sm">Reset</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Order</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Proof</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr class="<?= $p['status'] === 'pending' ? 'payment-pending-row' : '' ?>" id="payment-row-<?= (int) $p['id'] ?>">
                        <td><?= e($p['full_name']) ?></td>
                        <td>#<?= (int) $p['order_id'] ?> — <?= e($p['product_name']) ?></td>
                        <td><?= ucfirst(e($p['payment_type'])) ?></td>
                        <td><?= money($p['amount']) ?></td>
                        <td><?= e($p['method'] ?: '—') ?></td>
                        <td><?= e($p['reference_note'] ?: '—') ?></td>
                        <td>
                            <span class="payment-proof-badge <?= $p['proof_image'] ? 'has-proof' : 'no-proof' ?>">
                                <?php if ($p['proof_image']): ?>
                                    <button type="button"
                                            class="payment-proof-thumb-btn js-open-proof-modal"
                                            data-id="<?= (int) $p['id'] ?>"
                                            data-order-id="<?= (int) $p['order_id'] ?>"
                                            data-customer="<?= e($p['full_name']) ?>"
                                            data-amount="<?= money($p['amount']) ?>"
                                            data-type="<?= ucfirst(e($p['payment_type'])) ?>"
                                            data-method="<?= e($p['method'] ?: '—') ?>"
                                            data-status="<?= e($p['status']) ?>"
                                            data-proof-url="<?= url('/payment-proof.php?id=' . (int) $p['id']) ?>"
                                            title="Click to preview proof">
                                        <img src="<?= url('/payment-proof.php?id=' . (int) $p['id']) ?>"
                                             alt="Proof thumbnail"
                                             loading="lazy"
                                             class="payment-proof-thumb"
                                             onerror="this.closest('.payment-proof-thumb-btn').outerHTML='Attached'">
                                    </button>
                                <?php else: ?>
                                    <i class="bi bi-exclamation-circle" aria-hidden="true"></i>Missing
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="payment-status-cell"><span class="badge badge-<?= $p['status'] === 'verified' ? 'success' : ($p['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= e(payment_status_label($p['status'])) ?></span></td>
                        <td><?= display_date($p['created_at']) ?></td>
                        <td class="payment-actions-cell"><a href="<?= url('/admin/payments/view.php?id=' . $p['id']) ?>" class="btn btn-outline btn-sm">Review</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$payments): ?>
                    <tr>
                        <td colspan="10" class="empty-state">No payments found<?= ($search !== '' || $statusFilter !== '') ? ' matching your filters' : '' ?>.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <div class="panel-body">
            <?php
            $paginationParams = [];
            if ($statusFilter !== '') {
                $paginationParams['status'] = $statusFilter;
            }
            if ($search !== '') {
                $paginationParams['q'] = $search;
            }
            render_pagination($page, $totalPages, '/admin/payments/index.php', $paginationParams);
            ?>
        </div>
    <?php endif; ?>
</div>

<!-- Inline Proof Lightbox Modal -->
<div id="proofLightbox" class="proof-modal-backdrop" aria-hidden="true" role="dialog" aria-labelledby="proofModalTitle">
    <div class="proof-modal-card">
        <div class="proof-modal-head">
            <h3 class="proof-modal-title" id="proofModalTitle">
                <i class="bi bi-image text-accent" aria-hidden="true"></i>
                <span>Payment proof</span>
            </h3>
            <button type="button" class="proof-modal-close js-close-proof-modal" aria-label="Close modal">&times;</button>
        </div>
        <div class="proof-modal-body">
            <div class="proof-modal-image-wrap">
                <img id="proofModalImg" src="" alt="Payment proof preview" class="proof-modal-image">
            </div>
            <div class="proof-modal-meta-grid">
                <div class="proof-modal-meta-item">
                    <div class="label">Customer</div>
                    <div class="value" id="proofModalCustomer">—</div>
                </div>
                <div class="proof-modal-meta-item">
                    <div class="label">Order</div>
                    <div class="value" id="proofModalOrder">—</div>
                </div>
                <div class="proof-modal-meta-item">
                    <div class="label">Type / Method</div>
                    <div class="value" id="proofModalTypeMethod">—</div>
                </div>
                <div class="proof-modal-meta-item">
                    <div class="label">Amount</div>
                    <div class="value" id="proofModalAmount" style="color: var(--accent); font-size: 1.05rem;">—</div>
                </div>
            </div>
        </div>
        <div class="proof-modal-foot">
            <div>
                <a id="proofModalNewTab" href="#" target="_blank" rel="noopener" class="btn btn-outline btn-sm">
                    <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Open full size
                </a>
                <a id="proofModalReviewLink" href="#" class="btn btn-outline btn-sm ms-1">
                    <i class="bi bi-eye me-1" aria-hidden="true"></i>Full details
                </a>
            </div>
            <div class="proof-modal-actions" id="proofModalActions">
                <button type="button" class="btn btn-danger btn-sm js-modal-action" data-action="reject">
                    <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Reject
                </button>
                <button type="button" class="btn btn-accent btn-sm js-modal-action" data-action="verify">
                    <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Verify &amp; clear
                </button>
            </div>
        </div>
    </div>
</div>

</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>


<script>
(function() {
    var modal = document.getElementById('proofLightbox');
    if (!modal) return;

    var currentPayment = null;
    var modalImg = document.getElementById('proofModalImg');
    var modalCustomer = document.getElementById('proofModalCustomer');
    var modalOrder = document.getElementById('proofModalOrder');
    var modalTypeMethod = document.getElementById('proofModalTypeMethod');
    var modalAmount = document.getElementById('proofModalAmount');
    var modalNewTab = document.getElementById('proofModalNewTab');
    var modalReviewLink = document.getElementById('proofModalReviewLink');
    var modalActions = document.getElementById('proofModalActions');

    function openModal(data) {
        currentPayment = data;
        modalImg.src = data.proofUrl;
        modalCustomer.textContent = data.customer;
        modalOrder.innerHTML = '<a href="<?= url('/admin/orders/view.php?id=') ?>' + data.orderId + '">#' + data.orderId + '</a>';
        modalTypeMethod.textContent = data.type + ' (' + data.method + ')';
        modalAmount.textContent = data.amount;
        modalNewTab.href = data.proofUrl;
        modalReviewLink.href = '<?= url('/admin/payments/view.php?id=') ?>' + data.id;

        if (data.status === 'pending') {
            modalActions.style.display = 'flex';
        } else {
            modalActions.style.display = 'none';
        }

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        currentPayment = null;
    }

    document.querySelectorAll('.js-open-proof-modal').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            openModal({
                id: btn.dataset.id,
                orderId: btn.dataset.orderId,
                customer: btn.dataset.customer,
                amount: btn.dataset.amount,
                type: btn.dataset.type,
                method: btn.dataset.method,
                status: btn.dataset.status,
                proofUrl: btn.dataset.proofUrl
            });
        });
    });

    document.querySelectorAll('.js-close-proof-modal').forEach(function(btn) {
        btn.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });

    modal.querySelectorAll('.js-modal-action').forEach(function(btn) {
        btn.addEventListener('click', async function() {
            if (!currentPayment) return;
            var action = btn.dataset.action;
            var reason = '';

            if (action === 'reject') {
                reason = window.prompt('Reason for rejecting payment proof (visible to customer):', 'Receipt text is blurry or cropped — please re-upload a clear copy');
                if (reason === null) return;
                reason = reason.trim();
                if (!reason) {
                    window.alert('A rejection reason is required.');
                    return;
                }
            } else if (!window.confirm('Verify this payment of ' + currentPayment.amount + ' for Order #' + currentPayment.orderId + '?')) {
                return;
            }

            btn.disabled = true;
            try {
                var body = new URLSearchParams({
                    payment_id: currentPayment.id,
                    action: action,
                    reason: reason,
                    csrf_token: '<?= e(csrf_token()) ?>'
                });

                var res = await fetch('<?= url('/ajax/verify-payment.php') ?>', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': '<?= e(csrf_token()) ?>'
                    },
                    body: body
                });

                var data = await res.json();
                if (!res.ok || !data.success) {
                    throw new Error(data.message || 'Payment review failed.');
                }

                // Update row in DOM without reload
                var row = document.getElementById('payment-row-' + currentPayment.id);
                if (row) {
                    row.classList.remove('payment-pending-row');
                    var statusCell = row.querySelector('.payment-status-cell');
                    if (statusCell) {
                        var badgeClass = data.status === 'verified' ? 'success' : 'danger';
                        var badgeLabel = data.status === 'verified' ? 'Verified' : 'Rejected';
                        statusCell.innerHTML = '<span class="badge badge-' + badgeClass + '">' + badgeLabel + '</span>';
                    }
                    var proofBtn = row.querySelector('.js-open-proof-modal');
                    if (proofBtn) {
                        proofBtn.dataset.status = data.status;
                    }
                }

                closeModal();
            } catch (err) {
                window.alert(err.message || 'Network error occurred.');
            } finally {
                btn.disabled = false;
            }
        });
    });
})();
</script>
