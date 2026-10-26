<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT po.*, s.name AS supplier_name, s.id AS supplier_id,
        s.payment_terms, s.advance_percentage, s.bank_name, s.bank_account_name,
        s.bank_account_number, s.bank_branch, s.mobile_money_provider, s.mobile_money_number,
        s.tax_identification_number,
        creator.full_name AS created_by_name, approver.full_name AS approved_by_name
     FROM purchase_orders po
     JOIN suppliers s ON s.id = po.supplier_id
     JOIN users creator ON creator.id = po.created_by
     LEFT JOIN users approver ON approver.id = po.approved_by
     WHERE po.id = ?"
);
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'Purchase order not found.');
    redirect('/admin/purchase_orders/index.php');
}

$pageTitle = $po['po_number'];
$isDraft = $po['status'] === 'draft';
$canRecordSupplierPayment = in_array($po['status'], ['approved', 'sent', 'partially_received', 'received'], true);

$items = $pdo->prepare(
    "SELECT poi.*, p.name AS product_name,
        o.id AS order_ref_id, ou.full_name AS order_customer_name,
        sp_quote.id AS source_supplier_product_id, sp_quote.supplier_price AS source_quote_price,
        src_supplier.name AS source_supplier_name
     FROM purchase_order_items poi
     JOIN products p ON p.id = poi.product_id
     LEFT JOIN orders o ON o.id = poi.order_id
     LEFT JOIN users ou ON ou.id = o.user_id
     LEFT JOIN supplier_products sp_quote ON sp_quote.id = poi.source_supplier_product_id
     LEFT JOIN suppliers src_supplier ON src_supplier.id = sp_quote.supplier_id
     WHERE poi.purchase_order_id = ?
     ORDER BY poi.id"
);
$items->execute([$id]);
$items = $items->fetchAll();

$documents = po_documents_for_order($id);
// Supplier payments recorded against this PO
$paymentsStmt = $pdo->prepare(
    "SELECT sp.*, u.full_name AS recorded_by_name, pod.original_name AS proof_name
     FROM supplier_payments sp
     JOIN users u ON u.id = sp.recorded_by
     LEFT JOIN purchase_order_documents pod ON pod.id = sp.proof_document_id
     WHERE sp.purchase_order_id = ?
     ORDER BY sp.payment_date DESC, sp.id DESC"
);
$paymentsStmt->execute([$id]);
$supplierPayments = $paymentsStmt->fetchAll();

// Supplier invoices
$invoicesStmt = $pdo->prepare(
    "SELECT si.*, u.full_name AS uploaded_by_name, rev.full_name AS reviewed_by_name,
            pod.original_name AS document_name
     FROM supplier_invoices si
     JOIN users u ON u.id = si.uploaded_by
     LEFT JOIN users rev ON rev.id = si.reviewed_by
     LEFT JOIN purchase_order_documents pod ON pod.id = si.document_id
     WHERE si.purchase_order_id = ?
     ORDER BY si.invoice_date DESC, si.id DESC"
);
$invoicesStmt->execute([$id]);
$supplierInvoices = $invoicesStmt->fetchAll();
$grnCoverage = po_verified_grn_coverage($pdo, $id);
$approvedInvoiceNeedsReview = false;
foreach ($supplierInvoices as &$invoice) {
    $invoice['match_summary'] = supplier_invoice_match_summary(
        $invoice,
        (float) $po['grand_total'],
        $grnCoverage
    );
    if (
        $invoice['status'] === 'approved'
        && $invoice['invoice_type'] !== 'proforma'
        && !$invoice['match_summary']['matched']
    ) {
        $approvedInvoiceNeedsReview = true;
    }
}
unset($invoice);
$pendingSupplierInvoices = count(array_filter(
    $supplierInvoices,
    static fn(array $invoice): bool => $invoice['status'] === 'pending'
));

$deliveriesStmt = $pdo->prepare(
    'SELECT d.*, u.full_name AS submitted_by_name, v.full_name AS verified_by_name,
            COALESCE(SUM(di.declared_quantity), 0) AS declared_quantity,
            COALESCE(SUM(di.received_quantity), 0) AS received_quantity,
            COALESCE(SUM(di.accepted_quantity), 0) AS accepted_quantity,
            COALESCE(SUM(di.rejected_quantity), 0) AS rejected_quantity
     FROM supplier_deliveries d
     JOIN users u ON u.id = d.submitted_by
     LEFT JOIN users v ON v.id = d.verified_by
     LEFT JOIN supplier_delivery_items di ON di.delivery_id = d.id
     WHERE d.purchase_order_id = ?
     GROUP BY d.id
     ORDER BY d.submitted_at DESC, d.id DESC'
);
$deliveriesStmt->execute([$id]);
$supplierDeliveries = $deliveriesStmt->fetchAll();
$deliveryLinesStmt = $pdo->prepare(
    'SELECT di.id AS delivery_item_id, di.delivery_id, di.delivery_details, di.declared_quantity, di.received_quantity,
            di.accepted_quantity, di.rejected_quantity, di.discrepancy_type,
            di.inspection_note, p.name AS product_name, p.sku, p.barcode,
            p.specifications, p.track_serial_numbers
     FROM supplier_delivery_items di
     JOIN purchase_order_items poi ON poi.id = di.purchase_order_item_id
     JOIN products p ON p.id = poi.product_id
     WHERE di.delivery_id IN (
         SELECT id FROM supplier_deliveries WHERE purchase_order_id = ?
     )
     ORDER BY di.delivery_id DESC, p.name'
);
$deliveryLinesStmt->execute([$id]);
$deliveryLinesByDelivery = [];
foreach ($deliveryLinesStmt->fetchAll() as $deliveryLine) {
    $deliveryLinesByDelivery[(int) $deliveryLine['delivery_id']][] = $deliveryLine;
}
$deliverySerialStmt = $pdo->prepare(
    'SELECT ds.delivery_item_id, ds.serial_number, ds.inspection_status
     FROM supplier_delivery_serials ds
     JOIN supplier_delivery_items di ON di.id = ds.delivery_item_id
     WHERE di.delivery_id IN (
         SELECT id FROM supplier_deliveries WHERE purchase_order_id = ?
     )
     ORDER BY ds.id'
);
$deliverySerialStmt->execute([$id]);
$deliverySerialsByItem = [];
foreach ($deliverySerialStmt->fetchAll() as $serial) {
    $deliverySerialsByItem[(int) $serial['delivery_item_id']][] = $serial;
}

$productsStmt = $pdo->prepare(
    'SELECT p.id, p.name FROM products p
     JOIN supplier_products sp ON sp.product_id = p.id AND sp.supplier_id = ?
     ORDER BY p.name'
);
$productsStmt->execute([(int) $po['supplier_id']]);
$products = $productsStmt->fetchAll();

// If we arrived via the "Create purchase order" link on a customer order's
// page, prefill the add-item form with that order's product and quantity.
$prefillOrderId = (int) ($_GET['order_id'] ?? 0);
$prefillProductId = (int) ($_GET['product_id'] ?? 0);
$prefillSourceSupplierProductId = (int) ($_GET['source_supplier_product_id'] ?? 0);
$prefillQuantity = 1;
$prefillUnitCost = null;
if ($prefillOrderId) {
    $prefillStmt = $pdo->prepare('SELECT product_id, quantity FROM orders WHERE id = ?');
    $prefillStmt->execute([$prefillOrderId]);
    $prefillOrder = $prefillStmt->fetch();
    if ($prefillOrder) {
        $prefillProductId = (int) $prefillOrder['product_id'];
        $prefillQuantity = (int) $prefillOrder['quantity'];
    }
}
if (isset($_GET['quote_price']) && $_GET['quote_price'] !== '') {
    $prefillUnitCost = (float) $_GET['quote_price'];
}

// Suggest a starting cost from this supplier's known pricing, if any —
// admin can still type over it.
$knownPrices = $pdo->prepare('SELECT product_id, supplier_price FROM supplier_products WHERE supplier_id = ? AND supplier_price IS NOT NULL');
$knownPrices->execute([$po['supplier_id']]);
$knownPricesMap = array_column($knownPrices->fetchAll(), 'supplier_price', 'product_id');

// Orders this PO could be sourcing for — kept to a manageable list of
// open, non-cancelled orders from this supplier's assigned orders or
// generally open ones, so the dropdown doesn't grow unbounded.
$openOrders = $pdo->query(
    "SELECT o.id, o.product_id, u.full_name AS customer_name, p.name AS product_name
     FROM orders o
     JOIN users u ON u.id = o.user_id
     JOIN products p ON p.id = o.product_id
     WHERE o.status NOT IN ('cancelled', 'completed')
     ORDER BY o.created_at DESC LIMIT 100"
)->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="po-detail-page">
<header class="po-hero">
    <div>
        <div class="po-eyebrow">Purchase order</div>
        <h1><?= e($po['po_number']) ?></h1>
        <p><a href="<?= url('/admin/suppliers/view.php?id=' . $po['supplier_id']) ?>"><?= e($po['supplier_name']) ?></a>
            · Created <?= display_date($po['created_at'], 'd M Y') ?></p>
        <div class="po-hero-status">
            <span class="badge badge-<?= po_status_badge_class($po['status']) ?>"><?= e(po_status_label($po['status'])) ?></span>
        </div>
    </div>
    <div class="po-hero-actions">
        <a href="<?= url('/admin/purchase_orders/index.php') ?>" class="btn btn-outline">All purchase orders</a>
        <a href="<?= url('/admin/purchase_orders/print.php?id=' . $po['id']) ?>" class="btn btn-outline" target="_blank" rel="noopener">Print / Save PDF</a>
    </div>
</header>

<section class="po-metrics" aria-label="Purchase order summary">
    <div class="po-metric"><div class="po-metric-label">Grand total</div><div class="po-metric-value"><?= money($po['grand_total']) ?></div></div>
    <div class="po-metric"><div class="po-metric-label">Paid to supplier</div><div class="po-metric-value"><?= money($po['amount_paid'] ?? 0) ?></div></div>
    <div class="po-metric"><div class="po-metric-label">Outstanding</div><div class="po-metric-value"><?= money(max(0, (float) $po['grand_total'] - (float) ($po['amount_paid'] ?? 0))) ?></div></div>
    <div class="po-metric"><div class="po-metric-label">Items · accepted</div><div class="po-metric-value"><?= count($items) ?> lines · <?= array_sum(array_map(static fn(array $item): int => (int) $item['quantity_received'], $items)) ?> units</div></div>
</section>

<section class="panel po-action-panel" aria-labelledby="po-actions-title">
    <div class="panel-head">
        <div>
            <h3 id="po-actions-title">Next steps</h3>
            <p class="muted mb-0">Priority actions for this purchase order.</p>
        </div>
    </div>
    <div class="panel-body po-action-list">
        <?php foreach ($supplierDeliveries as $delivery): ?>
            <?php if ($delivery['status'] === 'submitted'): ?>
                <a class="btn btn-accent" href="<?= url('/admin/purchase_orders/receive.php?code=' . rawurlencode($delivery['delivery_code'])) ?>">Verify delivery</a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (
            !empty($po['supplier_proposed_delivery_date'])
            && in_array($po['status'], ['sent', 'partially_received'], true)
        ): ?>
            <button type="button" class="btn btn-outline" data-open-dialog="po-accept-date-dialog">Accept proposed date</button>
        <?php endif; ?>

        <?php if ($isDraft): ?>
            <button type="button" class="btn btn-outline" data-open-dialog="po-header-dialog">Edit PO details</button>
            <button type="button" class="btn btn-outline" data-open-dialog="po-add-item-dialog">Add item</button>
            <button type="button" class="btn btn-accent" data-open-dialog="po-submit-dialog" <?= $items ? '' : ' disabled' ?>>Submit for approval</button>
        <?php endif; ?>
        <?php if ($po['status'] === 'submitted'): ?>
            <button type="button" class="btn btn-accent" data-open-dialog="po-approve-dialog">Approve</button>
            <button type="button" class="btn btn-outline" data-open-dialog="po-reject-dialog">Reject</button>
        <?php endif; ?>
        <?php if ($po['status'] === 'approved'): ?>
            <button type="button" class="btn btn-accent" data-open-dialog="po-send-dialog">Send to supplier</button>
        <?php endif; ?>
        <?php if ($po['status'] === 'rejected'): ?>
            <button type="button" class="btn btn-outline" data-open-dialog="po-revise-dialog">Revise PO</button>
        <?php endif; ?>
        <?php if ($pendingSupplierInvoices > 0 || $approvedInvoiceNeedsReview): ?>
            <a class="btn btn-outline" href="#po-invoice-section">
                <?= $pendingSupplierInvoices > 0 ? 'Review pending invoices (' . $pendingSupplierInvoices . ')' : 'Review PO / GRN mismatch' ?>
            </a>
        <?php endif; ?>
        <?php if ($canRecordSupplierPayment && ($po['payment_status'] ?? 'unpaid') !== 'paid'): ?>
            <button type="button" class="btn btn-outline" data-open-dialog="po-payment-dialog">Record supplier payment</button>
        <?php endif; ?>
        <?php if (
            in_array($po['status'], ['draft', 'submitted', 'approved', 'rejected'], true)
            || (in_array($po['status'], ['sent', 'partially_received'], true) && ($po['supplier_response'] ?? 'pending') === 'declined')
        ): ?>
            <button type="button" class="btn btn-danger btn-sm" data-open-dialog="po-cancel-dialog">
                <?= in_array($po['status'], ['sent', 'partially_received'], true) ? 'Cancel declined PO' : 'Cancel PO' ?>
            </button>
        <?php endif; ?>
        <button type="button" class="btn btn-outline" data-open-dialog="po-duplicate-dialog">Reorder / Duplicate PO</button>
    </div>
</section>

<div class="panel po-overview">
    <div class="panel-body">
        <div class="detail-grid">
            <div class="detail-item">
                <div class="label">Supplier</div>
                <div class="value"><a href="<?= url('/admin/suppliers/view.php?id=' . $po['supplier_id']) ?>"><?= e($po['supplier_name']) ?></a></div>
            </div>
            <div class="detail-item">
                <div class="label">Created by</div>
                <div class="value"><?= e($po['created_by_name']) ?> on <?= display_date($po['created_at']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Expected delivery</div>
                <div class="value"><?= $po['expected_delivery_date'] ? display_date($po['expected_delivery_date'], 'd M Y') : '—' ?></div>
            </div>
            <?php if (!empty($po['supplier_proposed_delivery_date'])): ?>
                <div class="detail-item">
                    <div class="label">Supplier proposed</div>
                    <div class="value">
                        <?= display_date($po['supplier_proposed_delivery_date'], 'd M Y') ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="detail-item">
                <div class="label">Notes</div>
                <div class="value"><?= e($po['notes'] ?: '—') ?></div>
            </div>
            <?php if ($po['approved_by_name']): ?>
                <div class="detail-item">
                    <div class="label">Approved by</div>
                    <div class="value"><?= e($po['approved_by_name']) ?> on <?= display_date($po['approved_at']) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($po['approval_notes']): ?>
                <div class="detail-item">
                    <div class="label"><?= $po['status'] === 'rejected' ? 'Rejection reason' : 'Approval notes' ?></div>
                    <div class="value"><?= e($po['approval_notes']) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($po['status'] === 'sent' || !empty($po['supplier_response'])): ?>
                <div class="detail-item">
                    <div class="label">Supplier response</div>
                    <div class="value">
                        <span class="badge badge-<?= supplier_response_badge_class($po['supplier_response'] ?? 'pending') ?>"><?= e(supplier_response_label($po['supplier_response'] ?? 'pending')) ?></span>
                        <?php if (!empty($po['supplier_response_notes'])): ?>
                            <div class="po-detail-spaced-sm"><?= e($po['supplier_response_notes']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="detail-item">
                <div class="label">Supplier payments</div>
                <div class="value">
                    <span class="badge badge-<?= po_payment_status_badge_class($po['payment_status'] ?? 'unpaid') ?>">
                        <?= e(po_payment_status_label($po['payment_status'] ?? 'unpaid')) ?>
                    </span>
                    <span class="po-paid-value"><?= money($po['amount_paid'] ?? 0) ?> paid</span>
                    <span class="muted">/ <?= money($po['grand_total'] ?? 0) ?></span>
                    <div class="muted po-detail-spaced-xs">Outstanding: <?= money(max(0, (float) $po['grand_total'] - (float) ($po['amount_paid'] ?? 0))) ?></div>
                </div>
            </div>
            <div class="detail-item">
                <div class="label">Payment Terms & Due Date</div>
                <div class="value">
                    <span><?= e(supplier_payment_terms_label($po['payment_terms'] ?? 'net_30', (int) ($po['advance_percentage'] ?? 0))) ?></span>
                    <?php if ($po['payment_due_date']): ?>
                        <div class="po-due-date">
                            Due: <strong><?= display_date($po['payment_due_date'], 'd M Y') ?></strong>
                            <?php
                            $isOverdue = ($po['payment_status'] ?? 'unpaid') !== 'paid' && strtotime($po['payment_due_date']) < strtotime(date('Y-m-d'));
                            if ($isOverdue):
                            ?>
                                <span class="badge badge-danger po-overdue">Overdue</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($po['cancellation_reason']): ?>
                <div class="detail-item">
                    <div class="label">Cancellation reason</div>
                    <div class="value"><?= e($po['cancellation_reason']) ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h3>Items</h3>
        <?php if ($isDraft): ?><button type="button" class="btn btn-accent btn-sm" data-open-dialog="po-add-item-dialog">Add item</button><?php endif; ?>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>For order</th>
                    <th>Qty ordered</th>
                    <th>Accepted into stock</th>
                    <th>Unit cost</th>
                    <th>Line total</th><?php if ($isDraft): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= e($item['product_name']) ?></td>
                        <td><?= $item['order_ref_id'] ? '<a href="' . url('/admin/orders/view.php?id=' . $item['order_ref_id']) . '">#' . (int) $item['order_ref_id'] . ' — ' . e($item['order_customer_name']) . '</a>' : '<span class="muted">General restock</span>' ?></td>
                        <td><?= (int) $item['quantity_ordered'] ?></td>
                        <td><?= (int) $item['quantity_received'] ?></td>
                        <td>
                            <?= money($item['unit_cost']) ?>
                            <?php if (!empty($item['source_supplier_product_id'])): ?>
                                <div class="small muted">RFQ: <?= e($item['source_supplier_name'] ?: 'supplier') ?> · <?= money((float) ($item['source_quote_price'] ?? 0)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= money($item['line_total']) ?></td>
                        <?php if ($isDraft): ?>
                            <td>
                                <button type="button" class="btn btn-danger btn-sm" data-open-dialog="po-remove-item-dialog" data-item-id="<?= (int) $item['id'] ?>" data-item-name="<?= e($item['product_name']) ?>">Remove</button>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <tr>
                        <td colspan="<?= $isDraft ? 7 : 6 ?>" class="empty-state">No items added yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="panel-body">
        <div class="deposit-box">
            <div class="row"><span>Items subtotal</span><strong><?= money($po['subtotal']) ?></strong></div>
            <div class="row"><span>Additional costs</span><strong><?= money($po['additional_costs']) ?></strong></div>
            <div class="row total"><span>Grand total</span><strong><?= money($po['grand_total']) ?></strong></div>
        </div>
    </div>
</div>

<?php if ($isDraft): ?>
    <dialog class="po-dialog" id="po-add-item-dialog" aria-labelledby="po-add-item-title">
        <div class="po-dialog-head">
            <div><h2 id="po-add-item-title">Add an item</h2><p>Choose a supplier product and enter quantity and agreed cost.</p></div>
            <button type="button" class="po-dialog-close" data-close-dialog aria-label="Close dialog">&times;</button>
        </div>
        <form method="post" action="<?= url('/admin/purchase_orders/add_item.php') ?>">
            <div class="po-dialog-body">
                <?= csrf_field() ?>
                <input type="hidden" name="po_id" value="<?= $po['id'] ?>">
                <div class="field-row">
                    <div class="field">
                        <label for="product_id">Product</label>
                        <select id="product_id" name="product_id" required>
                            <option value="">Select a product</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $prefillProductId === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="order_id">For a specific customer order (optional)</label>
                        <select id="order_id" name="order_id">
                            <option value="0">General restock — no specific order</option>
                            <?php foreach ($openOrders as $o): ?>
                                <option value="<?= $o['id'] ?>" <?= $prefillOrderId === (int) $o['id'] ? 'selected' : '' ?>>#<?= (int) $o['id'] ?> — <?= e($o['customer_name']) ?> — <?= e($o['product_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="quantity_ordered">Quantity</label>
                        <input type="number" id="quantity_ordered" name="quantity_ordered" min="1" value="<?= $prefillQuantity ?>" required>
                    </div>
                    <div class="field">
                        <label for="unit_cost">Unit cost (UGX)</label>
                        <input type="number" id="unit_cost" name="unit_cost" min="0" step="1000" value="<?= $prefillUnitCost !== null ? (float) $prefillUnitCost : '' ?>" required>
                        <div class="hint" id="known-price-hint"></div>
                    </div>
                </div>
            </div>
            <div class="po-dialog-footer">
                <button type="button" class="btn btn-outline" data-close-dialog>Cancel</button>
                <button type="submit" class="btn btn-accent">Add item</button>
            </div>
        </form>
    </dialog>

    <dialog class="po-dialog" id="po-header-dialog" aria-labelledby="po-header-title">
        <div class="po-dialog-head">
            <div><h2 id="po-header-title">Edit purchase order details</h2><p>Update expected delivery, additional costs, and internal notes.</p></div>
            <button type="button" class="po-dialog-close" data-close-dialog aria-label="Close dialog">&times;</button>
        </div>
        <form method="post" action="<?= url('/admin/purchase_orders/update_header.php') ?>">
            <div class="po-dialog-body">
                <?= csrf_field() ?>
                <input type="hidden" name="po_id" value="<?= $po['id'] ?>">
                <div class="field">
                    <label for="expected_delivery_date_h">Expected delivery date</label>
                    <input type="date" id="expected_delivery_date_h" name="expected_delivery_date" value="<?= e($po['expected_delivery_date']) ?>">
                </div>
                <div class="field">
                    <label for="additional_costs">Additional costs (shipping, customs, etc.)</label>
                    <input type="number" id="additional_costs" name="additional_costs" min="0" step="1000" value="<?= e($po['additional_costs']) ?>">
                </div>
                <div class="field">
                    <label for="notes_h">Notes</label>
                    <textarea id="notes_h" name="notes"><?= e($po['notes']) ?></textarea>
                </div>
            </div>
            <div class="po-dialog-footer">
                <button type="button" class="btn btn-outline" data-close-dialog>Cancel</button>
                <button type="submit" class="btn btn-accent">Save changes</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<details class="panel">
    <summary class="panel-head">
        <h3>Documents from the supplier (<?= count($documents) ?>)</h3>
    </summary>
    <div class="panel-body flush">
        <?php if ($documents): ?>
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>File</th>
                        <th>Size</th>
                        <th>Uploaded by</th>
                        <th>Uploaded</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $document): ?>
                        <tr>
                            <td><span class="badge badge-neutral"><?= e(po_document_type_label($document['document_type'])) ?></span></td>
                            <td><?= e($document['original_name']) ?></td>
                            <td><?= po_document_size_label((int) $document['file_size']) ?></td>
                            <td><?= e($document['uploaded_by_name']) ?></td>
                            <td><?= display_date($document['created_at']) ?></td>
                            <td><a href="<?= url('/po-document.php?id=' . (int) $document['id']) ?>" class="btn btn-outline btn-sm">Download</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="panel-body">
                <p class="muted">The supplier has not uploaded any documents for this order.</p>
            </div>
        <?php endif; ?>
    </div>
</details>

<!-- Invoice review is separate from PO-level supplier disbursements. -->
<details class="panel" id="po-invoice-section" <?= ($pendingSupplierInvoices > 0 || $approvedInvoiceNeedsReview) ? 'open' : '' ?>>
    <summary class="panel-head">
        <div>
            <h3>Supplier Invoices & Proformas (<?= count($supplierInvoices) ?><?= $pendingSupplierInvoices > 0 ? ', ' . $pendingSupplierInvoices . ' pending review' : '' ?><?= $approvedInvoiceNeedsReview ? ', PO/GRN review needed' : '' ?>)</h3>
            <p class="muted mb-0">Review invoice paperwork here; record actual disbursements in the payment ledger.</p>
            <p class="muted mb-0">The match check compares the invoice total with the PO total and verifies full accepted quantities in GRNs; it is not an item-by-item invoice match.</p>
        </div>
    </summary>
    <div class="panel-body flush">
        <?php if ($supplierInvoices): ?>
            <table>
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Type</th>
                        <th>Invoice Date</th>
                        <th>Due Date</th>
                        <th>Total Amount</th>
                        <th>PO / GRN Check</th>
                        <th>Status</th>
                        <th>Document</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplierInvoices as $inv): ?>
                        <tr>
                            <td><strong><?= e($inv['invoice_number']) ?></strong></td>
                            <td><span class="badge badge-neutral"><?= e(ucfirst(str_replace('_', ' ', $inv['invoice_type']))) ?></span></td>
                            <td><?= display_date($inv['invoice_date'], 'd M Y') ?></td>
                            <td><?= $inv['due_date'] ? display_date($inv['due_date'], 'd M Y') : '—' ?></td>
                            <td><strong><?= money($inv['total_amount']) ?></strong></td>
                            <td><span class="badge badge-<?= e($inv['match_summary']['class']) ?>"><?= e($inv['match_summary']['label']) ?></span></td>
                            <td><span class="badge badge-<?= supplier_invoice_status_badge_class($inv['status']) ?>"><?= e(supplier_invoice_status_label($inv['status'])) ?></span></td>
                            <td>
                                <?php if ($inv['document_id']): ?>
                                    <a href="<?= url('/po-document.php?id=' . (int) $inv['document_id']) ?>" class="btn btn-outline btn-sm">Download</a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($inv['status'] === 'pending'): ?>
                                    <button type="button" class="btn btn-outline btn-sm" data-open-dialog="invoice-review-<?= (int) $inv['id'] ?>">Review invoice</button>
                                <?php elseif ($inv['status'] === 'approved'): ?>
                                    <span class="muted">Disbursements are tracked at PO level.</span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="panel-body">
                <p class="muted">No supplier invoices have been logged yet.</p>
            </div>
        <?php endif; ?>
    </div>
</details>

<?php foreach ($supplierInvoices as $inv): ?>
    <?php if ($inv['status'] === 'pending'): ?>
        <dialog class="po-dialog" id="invoice-review-<?= (int) $inv['id'] ?>" aria-labelledby="invoice-review-title-<?= (int) $inv['id'] ?>">
            <div class="po-dialog-head">
                <div>
                    <h2 id="invoice-review-title-<?= (int) $inv['id'] ?>">Review invoice <?= e($inv['invoice_number']) ?></h2>
                    <p><?= e(ucfirst(str_replace('_', ' ', $inv['invoice_type']))) ?> · <?= money($inv['total_amount']) ?></p>
                </div>
                <button type="button" class="po-dialog-close" data-close-dialog aria-label="Close dialog">&times;</button>
            </div>
            <form method="post" action="<?= url('/admin/purchase_orders/review_invoice.php') ?>">
                <div class="po-dialog-body">
                    <?= csrf_field() ?>
                    <input type="hidden" name="po_id" value="<?= $po['id'] ?>">
                    <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                    <div class="field">
                        <label for="review_notes_<?= (int) $inv['id'] ?>">Review note</label>
                        <textarea id="review_notes_<?= (int) $inv['id'] ?>" name="review_notes" maxlength="500" placeholder="Add a note for the supplier or audit trail (optional)"></textarea>
                    </div>
                    <span class="badge badge-<?= e($inv['match_summary']['class']) ?>"><?= e($inv['match_summary']['label']) ?></span>
                </div>
                <div class="po-dialog-footer">
                    <button type="button" class="btn btn-outline" data-close-dialog>Cancel</button>
                    <button type="submit" name="action" value="reject" class="btn btn-danger">Reject invoice</button>
                    <button type="submit" name="action" value="approve" class="btn btn-accent">Approve invoice</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>
<?php endforeach; ?>

<!-- Supplier Payment Ledger & Disbursements (Option 2) -->
<details class="panel">
    <summary class="panel-head">
        <div>
            <h3>Supplier Payment Ledger (<?= count($supplierPayments) ?>)</h3>
            <p class="muted mb-0">PO-level disbursements, tracked separately from invoice review.</p>
        </div>
    </summary>
    <div class="panel-body flush">
        <?php if ($supplierPayments): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Method</th>
                        <th>Reference #</th>
                        <th>Amount</th>
                        <th>Recorded By</th>
                        <th>Proof</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplierPayments as $pmt): ?>
                        <tr>
                            <td><?= display_date($pmt['payment_date'], 'd M Y') ?></td>
                            <td><span class="badge badge-neutral"><?= e(ucfirst($pmt['payment_type'])) ?></span></td>
                            <td><?= e(ucfirst(str_replace('_', ' ', $pmt['payment_method']))) ?></td>
                            <td><code><?= e($pmt['reference_number'] ?: '—') ?></code></td>
                            <td><strong class="po-payment-amount"><?= money($pmt['amount']) ?></strong></td>
                            <td><?= e($pmt['recorded_by_name']) ?></td>
                            <td>
                                <?php if ($pmt['proof_document_id']): ?>
                                    <a href="<?= url('/po-document.php?id=' . (int) $pmt['proof_document_id']) ?>" class="btn btn-outline btn-sm">Proof</a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($pmt['notes'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="panel-body">
                <p class="muted">No payments have been recorded for this purchase order yet.</p>
            </div>
        <?php endif; ?>
    </div>

    </details>

<?php
$remainingBalance = max(0, (float) $po['grand_total'] - (float) ($po['amount_paid'] ?? 0));
?>
<?php if ($canRecordSupplierPayment && ($po['payment_status'] ?? 'unpaid') !== 'paid'): ?>
    <dialog class="po-dialog" id="po-payment-dialog" aria-labelledby="po-payment-title">
        <div class="po-dialog-head">
            <div><h2 id="po-payment-title">Record Supplier Disbursement</h2><p>PO-level disbursements are tracked separately from invoice review.</p></div>
            <button type="button" class="po-dialog-close" data-close-dialog aria-label="Close dialog">&times;</button>
        </div>
        <form method="post" action="<?= url('/admin/purchase_orders/record_payment.php') ?>" enctype="multipart/form-data">
            <div class="po-dialog-body">
                <?php if ($approvedInvoiceNeedsReview): ?>
                    <div class="alert-warning po-reconciliation-alert" role="status">
                        An approved supplier invoice needs PO/GRN reconciliation. Advances remain available, but review the invoice and verified receipts before recording a final balance payment.
                    </div>
                <?php endif; ?>
                <?= csrf_field() ?>
                <input type="hidden" name="po_id" value="<?= $po['id'] ?>">

                <div class="field-row">
                    <div class="field">
                        <label for="amount">Amount (UGX)</label>
                        <input type="number" id="amount" name="amount" min="1" step="1" value="<?= $remainingBalance > 0 ? $remainingBalance : '' ?>" required>
                        <div class="hint">Remaining balance: <?= money($remainingBalance) ?></div>
                    </div>
                    <div class="field">
                        <label for="payment_date">Payment date</label>
                        <input type="date" id="payment_date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="payment_method">Payment method</label>
                        <select id="payment_method" name="payment_method" required>
                            <option value="bank_transfer">Bank Transfer (EFT/RTGS)</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="cheque">Cheque</option>
                            <option value="cash">Cash</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="payment_type">Payment stage / type</label>
                        <select id="payment_type" name="payment_type" required>
                            <option value="advance">Advance / Deposit</option>
                            <option value="milestone">Milestone Payment</option>
                            <option value="balance">Final Balance</option>
                            <option value="full">Full payment</option>
                        </select>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="reference_number">Transaction ref / Cheque #</label>
                        <input type="text" id="reference_number" name="reference_number" placeholder="e.g. TXN-984214 or CHQ-0021">
                    </div>
                    <div class="field">
                        <label for="proof_file">Payment slip / proof (optional)</label>
                        <input type="file" id="proof_file" name="proof_file" accept=".pdf,.jpg,.jpeg,.png,.webp">
                    </div>
                </div>
                <div class="field">
                    <label for="payment_notes">Notes</label>
                    <textarea id="payment_notes" name="notes" placeholder="Disbursement notes, payee details, etc."></textarea>
                </div>
            </div>
            <div class="po-dialog-footer">
                <button type="button" class="btn btn-outline" data-close-dialog>Cancel</button>
                <button type="submit" class="btn btn-accent">Record disbursement</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<div class="panel">
    <div class="panel-head">
        <div>
            <h3>Supplier delivery notices &amp; Goods Received Notes (<?= count($supplierDeliveries) ?>)</h3>
            <p class="muted mb-0">Supplier declarations do not change inventory. Only admin-verified accepted quantities are stocked.</p>
        </div>
        <a class="btn btn-outline btn-sm" href="<?= url('/admin/purchase_orders/receive.php') ?>">Scan delivery code</a>
    </div>
    <div class="panel-body flush">
        <?php if ($supplierDeliveries): ?>
            <table>
                <thead>
                    <tr>
                        <th>Delivery code</th>
                        <th>Submitted</th>
                        <th>Declared</th>
                        <th>Verified received</th>
                        <th>Accepted / rejected</th>
                        <th>Inspection results</th>
                        <th>Status</th>
                        <th>GRN / action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplierDeliveries as $delivery): ?>
                        <tr>
                            <td>
                                <strong><?= e($delivery['delivery_code']) ?></strong>
                                <?php if ($delivery['status'] === 'submitted'): ?>
                                    <div><?= supplier_delivery_barcode_markup($delivery['delivery_code']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= display_date($delivery['submitted_at'], 'd M Y H:i') ?><br><span class="muted"><?= e($delivery['submitted_by_name']) ?></span></td>
                            <td><?= (int) $delivery['declared_quantity'] ?></td>
                            <td><?= $delivery['status'] === 'verified' ? (int) $delivery['received_quantity'] : '—' ?></td>
                            <td><?= $delivery['status'] === 'verified' ? (int) $delivery['accepted_quantity'] . ' / ' . (int) $delivery['rejected_quantity'] : '—' ?></td>
                            <td>
                                <?php foreach ($deliveryLinesByDelivery[(int) $delivery['id']] ?? [] as $line): ?>
                                    <div>
                                        <?= e($line['product_name']) ?>:
                                        declared <?= (int) $line['declared_quantity'] ?>
                                        <?php if ($line['sku'] || $line['barcode']): ?>
                                            · SKU <?= e($line['sku'] ?: '—') ?> / product barcode <?= e($line['barcode'] ?: '—') ?>
                                        <?php endif; ?>
                                        <?php if ($line['delivery_details']): ?> · Details: <?= e($line['delivery_details']) ?><?php endif; ?>
                                        <?php if ($line['specifications']): ?> · <?= e($line['specifications']) ?><?php endif; ?>
                                        <?php if ($delivery['status'] === 'verified'): ?>
                                            , received <?= (int) $line['received_quantity'] ?>,
                                            accepted <?= (int) $line['accepted_quantity'] ?>,
                                            rejected <?= (int) $line['rejected_quantity'] ?>
                                            <?php if ($line['discrepancy_type'] !== 'none'): ?>
                                                — <?= e(str_replace('_', ' ', (string) $line['discrepancy_type'])) ?>
                                            <?php endif; ?>
                                            <?php if ($line['inspection_note']): ?><span class="muted">(<?= e($line['inspection_note']) ?>)</span><?php endif; ?>
                                        <?php endif; ?>
                                        <?php foreach ($deliverySerialsByItem[(int) $line['delivery_item_id']] ?? [] as $serial): ?>
                                            <div class="muted">Unit <?= e($serial['serial_number']) ?><?= $delivery['status'] === 'verified' ? ' — ' . e(str_replace('_', ' ', $serial['inspection_status'])) : '' ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td><span class="badge badge-<?= $delivery['status'] === 'verified' ? 'success' : 'warning' ?>"><?= $delivery['status'] === 'verified' ? 'Verified' : 'Pending verification' ?></span></td>
                            <td>
                                <?php if ($delivery['status'] === 'submitted'): ?>
                                    <a class="btn btn-accent btn-sm" href="<?= url('/admin/purchase_orders/receive.php?code=' . rawurlencode($delivery['delivery_code'])) ?>">Verify delivery</a>
                                <?php else: ?>
                                    <strong>GRN-<?= (int) $delivery['id'] ?></strong>
                                    <div class="muted"><?= e($delivery['verified_by_name'] ?? '—') ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="panel-body"><p class="muted">No supplier delivery notices have been submitted for this purchase order.</p></div>
        <?php endif; ?>
    </div>
</div>
</div>

<?php if ($isDraft): ?>
    <dialog class="po-dialog" id="po-submit-dialog" aria-labelledby="po-submit-title">
        <div class="po-dialog-head">
            <div><h2 id="po-submit-title">Submit for approval</h2><p>Check the items, quantities, pricing, and delivery details before sending this PO for approval.</p></div>
            <button type="button" class="po-dialog-close" data-close-dialog aria-label="Close dialog">&times;</button>
        </div>
        <form method="post" action="<?= url('/admin/purchase_orders/submit.php') ?>">
            <div class="po-dialog-body"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $po['id'] ?>"></div>
            <div class="po-dialog-footer">
                <button type="button" class="btn btn-outline" data-close-dialog>Review PO</button>
                <button type="submit" class="btn btn-accent">Confirm submission</button>
            </div>
        </form>
    </dialog>
    <dialog class="po-dialog" id="po-remove-item-dialog" aria-labelledby="po-remove-item-title">
        <div class="po-dialog-head">
            <div><h2 id="po-remove-item-title">Remove item</h2><p>Remove <strong id="po-remove-item-name">this item</strong> from the draft purchase order?</p></div>
            <button type="button" class="po-dialog-close" data-close-dialog aria-label="Close dialog">&times;</button>
        </div>
        <form method="post" action="<?= url('/admin/purchase_orders/remove_item.php') ?>">
            <div class="po-dialog-body">
                <?= csrf_field() ?>
                <input type="hidden" name="item_id" id="po-remove-item-id">
                <input type="hidden" name="po_id" value="<?= (int) $po['id'] ?>">
            </div>
            <div class="po-dialog-footer">
                <button type="button" class="btn btn-outline" data-close-dialog>Keep item</button>
                <button type="submit" class="btn btn-danger">Remove item</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($po['status'] === 'submitted'): ?>
    <dialog class="po-dialog" id="po-approve-dialog" aria-labelledby="po-approve-title">
        <div class="po-dialog-head">
            <div><h2 id="po-approve-title">Approve purchase order</h2><p>Confirm that the items, prices, and terms are authorized.</p></div>
            <button type="button" class="po-dialog-close" data-close-dialog aria-label="Close dialog">&times;</button>
        </div>
        <form method="post" action="<?= url('/admin/purchase_orders/approve.php') ?>">
            <div class="po-dialog-body"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $po['id'] ?>"></div>
            <div class="po-dialog-footer">
                <button type="button" class="btn btn-outline" data-close-dialog>Review PO</button>
                <button type="submit" class="btn btn-accent">Confirm approval</button>
            </div>
        </form>
    </dialog>
    <dialog class="po-dialog" id="po-reject-dialog" aria-labelledby="po-reject-title">
        <div class="po-dialog-head">
            <div><h2 id="po-reject-title">Reject purchase order</h2><p>Explain what needs to change so the creator can revise it.</p></div>
            <button type="button" class="po-dialog-close" data-close-dialog aria-label="Close dialog">&times;</button>
        </div>
        <form method="post" action="<?= url('/admin/purchase_orders/reject.php') ?>">
            <div class="po-dialog-body">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $po['id'] ?>">
                <div class="field">
                    <label for="rejection_reason">Reason</label>
                    <textarea id="rejection_reason" name="approval_notes" placeholder="Why is this being rejected?"></textarea>
                </div>
            </div>
            <div class="po-dialog-footer">
                <button type="button" class="btn btn-outline" data-close-dialog>Go back</button>
                <button type="submit" class="btn btn-danger">Confirm rejection</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($po['status'] === 'approved'): ?>
    <dialog class="po-dialog" id="po-send-dialog" aria-labelledby="po-send-title">
        <div class="po-dialog-head">
            <div><h2 id="po-send-title">Send PO to supplier</h2><p>The supplier will be able to view this approved purchase order.</p></div>
            <button type="button" class="po-dialog-close" data-close-dialog aria-label="Close dialog">&times;</button>
        </div>
        <form method="post" action="<?= url('/admin/purchase_orders/send.php') ?>">
            <div class="po-dialog-body"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $po['id'] ?>"></div>
            <div class="po-dialog-footer">
                <button type="button" class="btn btn-outline" data-close-dialog>Not yet</button>
                <button type="submit" class="btn btn-accent">Confirm and send</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($po['status'] === 'rejected'): ?>
    <dialog class="po-dialog" id="po-revise-dialog" aria-labelledby="po-revise-title">
        <div class="po-dialog-head">
            <div><h2 id="po-revise-title">Revise purchase order</h2><p>Return this rejected PO to draft so it can be updated and resubmitted.</p></div>
            <button type="button" class="po-dialog-close" data-close-dialog aria-label="Close dialog">&times;</button>
        </div>
        <form method="post" action="<?= url('/admin/purchase_orders/revert_to_draft.php') ?>">
            <div class="po-dialog-body"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $po['id'] ?>"></div>
            <div class="po-dialog-footer">
                <button type="button" class="btn btn-outline" data-close-dialog>Keep rejected</button>
                <button type="submit" class="btn btn-accent">Return to draft</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php if (
    in_array($po['status'], ['draft', 'submitted', 'approved', 'rejected'], true)
    || (in_array($po['status'], ['sent', 'partially_received'], true) && ($po['supplier_response'] ?? 'pending') === 'declined')
): ?>
    <dialog class="po-dialog" id="po-cancel-dialog" aria-labelledby="po-cancel-title">
        <div class="po-dialog-head">
            <div><h2 id="po-cancel-title">Cancel purchase order</h2><p>This action closes the PO. Add a reason for the audit record.</p></div>
            <button type="button" class="po-dialog-close" data-close-dialog aria-label="Close dialog">&times;</button>
        </div>
        <form method="post" action="<?= url('/admin/purchase_orders/cancel.php') ?>">
            <div class="po-dialog-body">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $po['id'] ?>">
                <div class="field">
                    <label for="cancellation_reason">Reason</label>
                    <textarea id="cancellation_reason" name="cancellation_reason" placeholder="Why is this being cancelled?" required></textarea>
                </div>
            </div>
            <div class="po-dialog-footer">
                <button type="button" class="btn btn-outline" data-close-dialog>Keep PO</button>
                <button type="submit" class="btn btn-danger">Confirm cancellation</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<dialog class="po-dialog" id="po-duplicate-dialog" aria-labelledby="po-duplicate-title">
    <div class="po-dialog-head">
        <div><h2 id="po-duplicate-title">Duplicate purchase order</h2><p>Create a new draft PO with the same supplier and items.</p></div>
        <button type="button" class="po-dialog-close" data-close-dialog aria-label="Close dialog">&times;</button>
    </div>
    <form method="post" action="<?= url('/admin/purchase_orders/duplicate.php') ?>">
        <div class="po-dialog-body"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $po['id'] ?>"></div>
        <div class="po-dialog-footer">
            <button type="button" class="btn btn-outline" data-close-dialog>Cancel</button>
            <button type="submit" class="btn btn-accent">Create draft copy</button>
        </div>
    </form>
</dialog>

<?php if (
    !empty($po['supplier_proposed_delivery_date'])
    && in_array($po['status'], ['sent', 'partially_received'], true)
): ?>
    <dialog class="po-dialog" id="po-accept-date-dialog" aria-labelledby="po-accept-date-title">
        <div class="po-dialog-head">
            <div><h2 id="po-accept-date-title">Accept proposed delivery date</h2><p>Update the expected delivery date to <?= display_date($po['supplier_proposed_delivery_date'], 'd M Y') ?>?</p></div>
            <button type="button" class="po-dialog-close" data-close-dialog aria-label="Close dialog">&times;</button>
        </div>
        <form method="post" action="<?= url('/admin/purchase_orders/accept_delivery_date.php') ?>">
            <div class="po-dialog-body"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $po['id'] ?>"></div>
            <div class="po-dialog-footer">
                <button type="button" class="btn btn-outline" data-close-dialog>Keep current date</button>
                <button type="submit" class="btn btn-accent">Accept new date</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($isDraft): ?>
    <script>
        var knownPrices = <?= json_encode($knownPricesMap, JSON_UNESCAPED_SLASHES) ?>;
        var productSelect = document.getElementById('product_id');
        var prefillUnitCost = <?= $prefillUnitCost !== null ? json_encode((float) $prefillUnitCost) : 'null' ?>;
        productSelect.addEventListener('change', function() {
            var hint = document.getElementById('known-price-hint');
            var price = knownPrices[this.value];
            if (price !== undefined) {
                hint.textContent = 'This supplier\'s listed price: UGX ' + Math.round(price).toLocaleString('en-US');
                document.getElementById('unit_cost').value = price;
            } else if (prefillUnitCost !== null && this.value == <?= (int) $prefillProductId ?>) {
                document.getElementById('unit_cost').value = prefillUnitCost;
                hint.textContent = 'RFQ selected price: UGX ' + Math.round(prefillUnitCost).toLocaleString('en-US');
            } else {
                hint.textContent = '';
            }
        });
        <?php if ($prefillProductId): ?>
            productSelect.dispatchEvent(new Event('change'));
        <?php endif; ?>
    </script>
<?php endif; ?>

<script>
    document.addEventListener('click', function(event) {
        var target = event.target;
        if (!(target instanceof Element)) return;

        var opener = target.closest('[data-open-dialog]');
        if (opener) {
            var dialog = document.getElementById(opener.getAttribute('data-open-dialog'));
            if (dialog instanceof HTMLDialogElement) {
                if (dialog.id === 'po-remove-item-dialog') {
                    var itemId = dialog.querySelector('#po-remove-item-id');
                    var itemName = dialog.querySelector('#po-remove-item-name');
                    if (itemId instanceof HTMLInputElement && itemName instanceof HTMLElement) {
                        itemId.value = opener.getAttribute('data-item-id') || '';
                        itemName.textContent = opener.getAttribute('data-item-name') || 'this item';
                    }
                }
                dialog.showModal();
                var firstInput = dialog.querySelector('input:not([type="hidden"]), select, textarea');
                if (firstInput) firstInput.focus();
            }
            return;
        }

        var closer = target.closest('[data-close-dialog]');
        if (closer) {
            var parentDialog = closer.closest('dialog');
            if (parentDialog instanceof HTMLDialogElement) parentDialog.close();
        }
    });

    document.addEventListener('click', function(event) {
        if (event.target instanceof HTMLDialogElement && event.target.open) {
            var bounds = event.target.getBoundingClientRect();
            var inDialog = event.clientX >= bounds.left && event.clientX <= bounds.right
                && event.clientY >= bounds.top && event.clientY <= bounds.bottom;
            if (!inDialog) event.target.close();
        }
    });

    <?php if ($isDraft && $prefillProductId): ?>
        document.getElementById('po-add-item-dialog').showModal();
    <?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>