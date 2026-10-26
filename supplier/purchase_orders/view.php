<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_supplier();

$id = (int) ($_GET['id'] ?? 0);

// Get supplier info using the actual email-based supplier/user relationship.
$supplier = get_supplier_for_user((int) $_SESSION['user_id']);

if (!$supplier) {
    set_flash('error', 'Supplier profile not found.');
    redirect('/supplier/purchase_orders/index.php');
}

// Get purchase order
$stmt = $pdo->prepare(
    "SELECT po.*, s.name AS supplier_name
     FROM purchase_orders po
     JOIN suppliers s ON s.id = po.supplier_id
     WHERE po.id = ? AND po.supplier_id = ?"
);
$stmt->execute([$id, $supplier['id']]);
$po = $stmt->fetch();

if (!$po) {
    set_flash('error', 'Purchase order not found.');
    redirect('/supplier/purchase_orders/index.php');
}

// Get items
$stmt = $pdo->prepare(
    "SELECT poi.*, p.name AS product_name, p.slug AS product_slug, p.sku, p.barcode,
            p.specifications, p.track_serial_numbers
     FROM purchase_order_items poi
     JOIN products p ON p.id = poi.product_id
     WHERE poi.purchase_order_id = ?
     ORDER BY poi.id"
);
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$deliveriesStmt = $pdo->prepare(
    'SELECT d.*, COALESCE(SUM(di.declared_quantity), 0) AS declared_quantity,
            COALESCE(SUM(di.received_quantity), 0) AS received_quantity,
            COALESCE(SUM(di.accepted_quantity), 0) AS accepted_quantity,
            COALESCE(SUM(di.rejected_quantity), 0) AS rejected_quantity
     FROM supplier_deliveries d
     LEFT JOIN supplier_delivery_items di ON di.delivery_id = d.id
     WHERE d.purchase_order_id = ? AND d.supplier_id = ?
     GROUP BY d.id
     ORDER BY d.submitted_at DESC, d.id DESC'
);
$deliveriesStmt->execute([$id, (int) $supplier['id']]);
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
         SELECT id FROM supplier_deliveries
         WHERE purchase_order_id = ? AND supplier_id = ?
     )
     ORDER BY di.delivery_id DESC, p.name'
);
$deliveryLinesStmt->execute([$id, (int) $supplier['id']]);
$deliveryLinesByDelivery = [];
foreach ($deliveryLinesStmt->fetchAll() as $deliveryLine) {
    $deliveryLinesByDelivery[(int) $deliveryLine['delivery_id']][] = $deliveryLine;
}
$serialStmt = $pdo->prepare(
    'SELECT ds.delivery_item_id, ds.serial_number, ds.inspection_status
     FROM supplier_delivery_serials ds
     JOIN supplier_delivery_items di ON di.id = ds.delivery_item_id
     WHERE di.delivery_id IN (
         SELECT id FROM supplier_deliveries
         WHERE purchase_order_id = ? AND supplier_id = ?
     )
     ORDER BY ds.id'
);
$serialStmt->execute([$id, (int) $supplier['id']]);
$serialsByDeliveryItem = [];
foreach ($serialStmt->fetchAll() as $serial) {
    $serialsByDeliveryItem[(int) $serial['delivery_item_id']][] = $serial;
}
$hasPendingDelivery = (bool) array_filter(
    $supplierDeliveries,
    static fn(array $delivery): bool => $delivery['status'] === 'submitted'
);
$canSubmitDelivery = in_array($po['status'], ['sent', 'partially_received'], true)
    && ($po['supplier_response'] ?? 'pending') !== 'declined'
    && !$hasPendingDelivery;

$documents = po_documents_for_order($id);

// Invoices this supplier has already submitted for the order.
$invoicesStmt = $pdo->prepare(
    "SELECT si.*, pod.original_name AS document_name
     FROM supplier_invoices si
     LEFT JOIN purchase_order_documents pod ON pod.id = si.document_id
     WHERE si.purchase_order_id = ?
     ORDER BY si.invoice_date DESC, si.id DESC"
);
$invoicesStmt->execute([$id]);
$supplierInvoices = $invoicesStmt->fetchAll();
$paymentsStmt = $pdo->prepare(
    "SELECT sp.*, pod.original_name AS proof_name
     FROM supplier_payments sp
     LEFT JOIN purchase_order_documents pod ON pod.id = sp.proof_document_id
     WHERE sp.purchase_order_id = ?
     ORDER BY sp.payment_date DESC, sp.id DESC"
);
$paymentsStmt->execute([$id]);
$supplierPayments = $paymentsStmt->fetchAll();
$totalReceivedUnits = array_sum(array_column($items, 'quantity_received'));
$hasReceivedItems = $totalReceivedUnits > 0;
$amountPaid = (float) ($po['amount_paid'] ?? 0);
$outstandingBalance = max(0, (float) $po['grand_total'] - $amountPaid);

$pageTitle = $po['po_number'] . ' — Purchase Order';

include __DIR__ . '/../../includes/supplier_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2><?= e($po['po_number']) ?></h2>
        <span class="badge badge-<?= po_status_badge_class($po['status']) ?>"><?= e(po_status_label($po['status'])) ?></span>
    </div>
    <div class="panel-body">
        <div class="detail-grid">
            <div class="detail-item">
                <div class="label">Supplier</div>
                <div class="value"><?= e($po['supplier_name']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Expected Delivery</div>
                <div class="value"><?= $po['expected_delivery_date'] ? e($po['expected_delivery_date']) : 'Not set' ?></div>
            </div>
            <?php if (!empty($po['supplier_proposed_delivery_date'])): ?>
                <div class="detail-item">
                    <div class="label">You proposed</div>
                    <div class="value"><?= display_date($po['supplier_proposed_delivery_date'], 'd M Y') ?></div>
                </div>
            <?php endif; ?>
            <div class="detail-item">
                <div class="label">Notes</div>
                <div class="value"><?= $po['notes'] ? e($po['notes']) : '—' ?></div>
            </div>
        </div>
    </div>
</div>

<div class="panel" style="margin-top: 20px;">
    <div class="panel-head">
        <h3>Order Items</h3>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity Ordered</th>
                    <th>Unit Cost (UGX)</th>
                    <th>Line Total (UGX)</th>
                    <th>Acceptance status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <a href="<?= url('/products.php?slug=' . $item['product_slug']) ?>">
                                <?= e($item['product_name']) ?>
                            </a>
                        </td>
                        <td><?= (int) $item['quantity_ordered'] ?></td>
                        <td><?= money($item['unit_cost']) ?></td>
                        <td><strong><?= money($item['line_total']) ?></strong></td>
                        <td>
                            <?php if ($item['quantity_received'] >= $item['quantity_ordered']): ?>
                                <span class="badge badge-success">Received</span>
                            <?php elseif ($item['quantity_received'] > 0): ?>
                                <span class="badge badge-warning">Partial</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel" style="margin-top: 20px;">
    <div class="panel-head">
        <div>
            <h3>Delivery notices &amp; receipt verification</h3>
            <p class="muted mb-0">Declare what you are dispatching. Only buyer-accepted quantities become stock.</p>
        </div>
    </div>
    <div class="panel-body flush">
        <?php if ($supplierDeliveries): ?>
            <table>
                <thead>
                    <tr>
                        <th>Delivery code</th>
                        <th>Submitted</th>
                        <th>Expected arrival</th>
                        <th>Declared</th>
                        <th>Accepted / rejected</th>
                        <th>Verification details</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplierDeliveries as $delivery): ?>
                        <tr>
                            <td>
                                <strong><?= e($delivery['delivery_code']) ?></strong>
                                <?php if ($delivery['tracking_reference']): ?><div class="muted"><?= e($delivery['tracking_reference']) ?></div><?php endif; ?>
                                <?php if ($delivery['status'] === 'submitted'): ?>
                                    <div><?= supplier_delivery_barcode_markup($delivery['delivery_code']) ?></div>
                                    <a class="btn btn-outline btn-sm" href="<?= url('/supplier/purchase_orders/delivery_label.php?id=' . (int) $delivery['id']) ?>">Print delivery code</a>
                                <?php else: ?>
                                    <div class="muted">GRN-<?= (int) $delivery['id'] ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= display_date($delivery['submitted_at'], 'd M Y H:i') ?></td>
                            <td><?= $delivery['expected_delivery_date'] ? display_date($delivery['expected_delivery_date'], 'd M Y') : '—' ?></td>
                            <td><?= (int) $delivery['declared_quantity'] ?></td>
                            <td><?= $delivery['status'] === 'verified' ? (int) $delivery['accepted_quantity'] . ' / ' . (int) $delivery['rejected_quantity'] : 'Pending' ?></td>
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
                                        <?php foreach ($serialsByDeliveryItem[(int) $line['delivery_item_id']] ?? [] as $serial): ?>
                                            <div class="muted">Unit <?= e($serial['serial_number']) ?><?= $delivery['status'] === 'verified' ? ' — ' . e(str_replace('_', ' ', $serial['inspection_status'])) : '' ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td><span class="badge badge-<?= $delivery['status'] === 'verified' ? 'success' : 'warning' ?>"><?= $delivery['status'] === 'verified' ? 'Verified' : 'Awaiting buyer verification' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="panel-body"><p class="muted">No delivery notices have been submitted for this PO.</p></div>
        <?php endif; ?>
    </div>
    <?php if ($canSubmitDelivery): ?>
        <div class="panel-body" style="border-top: 1px solid var(--border);">
            <h4>Submit a delivery notice</h4>
            <p class="muted">Enter quantities and delivery-specific details such as color. For serialized products, provide one serial number or unique unit barcode per physical unit, one per line. The product barcode shown is a model code unless your units each have their own unique code. The delivery code opens this manifest for receiving staff.</p>
            <form method="post" action="<?= url('/supplier/purchase_orders/submit_delivery.php') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $po['id'] ?>">
                <table>
                    <thead>
                        <tr><th>PO item</th><th>SKU / product barcode</th><th>Ordered</th><th>Accepted so far</th><th>Remaining</th><th>Dispatching now</th><th>Color / variant details</th><th>Serials / unique unit barcodes</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item):
                            $remaining = (int) $item['quantity_ordered'] - (int) $item['quantity_received'];
                            if ($remaining <= 0) continue;
                        ?>
                            <tr>
                                <td>
                                    <?= e($item['product_name']) ?>
                                    <?php if ($item['specifications']): ?><div class="muted"><?= e($item['specifications']) ?></div><?php endif; ?>
                                </td>
                                <td><?= e($item['sku'] ?: '—') ?> / <?= e($item['barcode'] ?: '—') ?></td>
                                <td><?= (int) $item['quantity_ordered'] ?></td>
                                <td><?= (int) $item['quantity_received'] ?></td>
                                <td><?= $remaining ?></td>
                                <td><input type="number" name="declared[<?= (int) $item['id'] ?>]" min="0" max="<?= $remaining ?>" value="0" style="width: 120px;"></td>
                                <td><input type="text" name="details[<?= (int) $item['id'] ?>]" maxlength="500" placeholder="e.g. Black, 256GB" style="min-width: 180px;"></td>
                                <td>
                                    <?php if ($item['track_serial_numbers']): ?>
                                        <textarea name="serials[<?= (int) $item['id'] ?>]" rows="2" maxlength="10000" placeholder="One unique serial or unit barcode per line" style="min-width: 220px;"></textarea>
                                        <div class="hint">Required: one unique identifier per unit dispatched.</div>
                                    <?php else: ?>
                                        <span class="muted">Not individually serialized</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="field-row" style="margin-top: 16px;">
                    <div class="field">
                        <label for="expected_delivery_date">Expected arrival date</label>
                        <input type="date" id="expected_delivery_date" name="expected_delivery_date">
                    </div>
                    <div class="field">
                        <label for="tracking_reference">Tracking / dispatch reference</label>
                        <input type="text" id="tracking_reference" name="tracking_reference" maxlength="100">
                    </div>
                </div>
                <div class="field">
                    <label for="delivery_note">Delivery note / packing list (optional)</label>
                    <input type="file" id="delivery_note" name="delivery_note" accept=".pdf,.jpg,.jpeg,.png,.webp">
                </div>
                <div class="field">
                    <label for="supplier_notes">Dispatch notes (optional)</label>
                    <textarea id="supplier_notes" name="supplier_notes" maxlength="500"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Submit delivery notice</button>
            </form>
        </div>
    <?php elseif ($hasPendingDelivery): ?>
        <div class="panel-body"><p class="muted mb-0">Your latest delivery notice is awaiting verification. You can submit another notice after the buyer has verified this one.</p></div>
    <?php endif; ?>
</div>

<div class="panel" style="margin-top: 20px;">
    <div class="panel-head">
        <h3>Order Summary</h3>
    </div>
    <div class="panel-body">
        <div class="detail-grid">
            <div class="detail-item">
                <div class="label">Subtotal</div>
                <div class="value"><?= money($po['subtotal']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Additional Costs</div>
                <div class="value"><?= money($po['additional_costs']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label" style="font-size: 1.1rem;">Grand Total</div>
                <div class="value" style="font-size: 1.25rem; font-weight: 600;"><?= money($po['grand_total']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Payment status</div>
                <div class="value">
                    <span class="badge badge-<?= po_payment_status_badge_class($po['payment_status'] ?? 'unpaid') ?>">
                        <?= e(po_payment_status_label($po['payment_status'] ?? 'unpaid')) ?>
                    </span>
                </div>
            </div>
            <div class="detail-item">
                <div class="label">Paid / outstanding</div>
                <div class="value"><?= money($amountPaid) ?> paid · <?= money($outstandingBalance) ?> outstanding</div>
            </div>
            <?php if (!empty($po['payment_due_date'])): ?>
                <div class="detail-item">
                    <div class="label">Payment due date</div>
                    <div class="value"><?= display_date($po['payment_due_date'], 'd M Y') ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="panel" style="margin-top: 20px;">
    <div class="panel-head">
        <div>
            <h3>Payments from buyer</h3>
            <p class="muted mb-0">PO-level disbursements. Invoice approval is tracked separately.</p>
        </div>
        <span class="badge badge-<?= po_payment_status_badge_class($po['payment_status'] ?? 'unpaid') ?>">
            <?= e(po_payment_status_label($po['payment_status'] ?? 'unpaid')) ?>
        </span>
    </div>
    <div class="panel-body flush">
        <?php if ($supplierPayments): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Stage</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Amount</th>
                        <th>Proof</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplierPayments as $payment): ?>
                        <tr>
                            <td><?= display_date($payment['payment_date'], 'd M Y') ?></td>
                            <td><?= e(ucfirst($payment['payment_type'])) ?></td>
                            <td><?= e(ucfirst(str_replace('_', ' ', $payment['payment_method']))) ?></td>
                            <td><?= e($payment['reference_number'] ?: '—') ?></td>
                            <td><strong><?= money($payment['amount']) ?></strong></td>
                            <td>
                                <?php if ($payment['proof_document_id']): ?>
                                    <a href="<?= url('/po-document.php?id=' . (int) $payment['proof_document_id']) ?>" class="btn btn-outline btn-sm">View proof</a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $payment['notes'] ? e($payment['notes']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="panel-body">
                <p class="muted">No payments have been recorded against this purchase order yet.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="panel" style="margin-top: 20px;">
    <div class="panel-head">
        <h3>Documents</h3>
    </div>
    <div class="panel-body flush">
        <?php if ($documents): ?>
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>File</th>
                        <th>Size</th>
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
                            <td><?= display_date($document['created_at']) ?></td>
                            <td><a href="<?= url('/po-document.php?id=' . (int) $document['id']) ?>" class="btn btn-outline btn-sm">Download</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="panel-body">
                <p class="muted">No documents yet. Upload a quote, invoice or delivery note for this order.</p>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($po['status'] !== 'cancelled'): ?>
        <div class="panel-body">
            <form method="post" action="<?= url('/supplier/purchase_orders/upload_document.php') ?>" enctype="multipart/form-data"
                style="display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; max-width: 720px;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $po['id'] ?>">
                <div class="field">
                    <label for="document_type">Document type</label>
                    <select id="document_type" name="document_type">
                        <?php foreach (po_document_type_labels() as $documentTypeValue => $documentTypeLabel): ?>
                            <option value="<?= e($documentTypeValue) ?>"><?= e($documentTypeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="document">File (PDF, JPG, PNG or WEBP — max 10MB)</label>
                    <input type="file" id="document" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
                </div>
                <button type="submit" class="btn btn-primary">Upload document</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php
// Invoicing opens once the buyer has approved the order and closes with it.
$canSubmitInvoice = in_array($po['status'], ['approved', 'sent', 'partially_received', 'received'], true);
?>
<div class="panel" style="margin-top: 20px;">
    <div class="panel-head">
        <h3>Invoices</h3>
        <span class="muted">Your billing paperwork for this order</span>
    </div>
    <div class="panel-body flush">
        <?php if ($supplierInvoices): ?>
            <table>
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Due</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>File</th>
                        <th>Review note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplierInvoices as $invoice): ?>
                        <tr>
                            <td><strong><?= e($invoice['invoice_number']) ?></strong></td>
                            <td><span class="badge badge-neutral"><?= e(ucfirst(str_replace('_', ' ', $invoice['invoice_type']))) ?></span></td>
                            <td><?= display_date($invoice['invoice_date'], 'd M Y') ?></td>
                            <td><?= $invoice['due_date'] ? display_date($invoice['due_date'], 'd M Y') : '—' ?></td>
                            <td><strong><?= money($invoice['total_amount']) ?></strong></td>
                            <td><span class="badge badge-<?= supplier_invoice_status_badge_class($invoice['status']) ?>"><?= e(supplier_invoice_status_label($invoice['status'])) ?></span></td>
                            <td>
                                <?php if ($invoice['document_id']): ?>
                                    <a href="<?= url('/po-document.php?id=' . (int) $invoice['document_id']) ?>" class="btn btn-outline btn-sm">Download</a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $invoice['notes'] ? e($invoice['notes']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="panel-body">
                <p class="muted">You have not submitted an invoice for this order yet.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($canSubmitInvoice): ?>
        <div class="panel-body" style="border-top: 1px solid var(--border);">
            <h4 style="margin-bottom: 4px; font-size: 0.95rem;">Submit an invoice</h4>
            <p class="muted">Upload your proforma or tax invoice for review. Payments are recorded separately against the purchase order.</p>
            <form method="post" action="<?= url('/supplier/purchase_orders/upload_invoice.php') ?>" enctype="multipart/form-data" style="max-width: 720px;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $po['id'] ?>">

                <div class="field-row">
                    <div class="field">
                        <label for="invoice_number">Invoice number</label>
                        <input type="text" id="invoice_number" name="invoice_number" maxlength="100" required
                            placeholder="e.g. INV-2026-014">
                    </div>
                    <div class="field">
                        <label for="invoice_type">Invoice type</label>
                        <select id="invoice_type" name="invoice_type">
                            <option value="tax_invoice">Tax invoice</option>
                            <option value="proforma">Proforma</option>
                            <option value="credit_note">Credit note</option>
                        </select>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="invoice_date">Invoice date</label>
                        <input type="date" id="invoice_date" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="field">
                        <label for="due_date">Payment due date (optional)</label>
                        <input type="date" id="due_date" name="due_date" value="<?= e($po['payment_due_date'] ?? '') ?>">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="total_amount">Invoice total (UGX)</label>
                        <input type="number" id="total_amount" name="total_amount" min="0" step="1000" required
                            value="<?= (float) $po['grand_total'] > 0 ? (float) $po['grand_total'] : '' ?>">
                        <div class="hint">Order total on this purchase order: <?= money($po['grand_total']) ?></div>
                    </div>
                    <div class="field">
                        <label for="document">Invoice file (PDF, JPG, PNG or WEBP — max 10MB)</label>
                        <input type="file" id="document" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp">
                    </div>
                </div>

                <div class="field">
                    <label for="invoice_notes">Notes (optional)</label>
                    <textarea id="invoice_notes" name="notes" maxlength="500"
                        placeholder="e.g. VAT inclusive, bank charges excluded"></textarea>
                </div>

                <button type="submit" class="btn btn-primary">Submit invoice</button>
            </form>
        </div>
    <?php elseif (in_array($po['status'], ['draft', 'submitted'], true)): ?>
        <div class="panel-body">
            <p class="muted">Invoicing opens once the buyer approves this order.</p>
        </div>
    <?php endif; ?>
</div>


<?php if (in_array($po['status'], ['sent', 'partially_received'], true)): ?>
    <div class="panel" style="margin-top: 20px;">
        <div class="panel-head">
            <h3>Your response</h3>
            <span class="badge badge-<?= supplier_response_badge_class($po['supplier_response'] ?? 'pending') ?>">
                <?= e(supplier_response_label($po['supplier_response'] ?? 'pending')) ?>
            </span>
        </div>
        <div class="panel-body">
            <?php if (($po['supplier_response'] ?? 'pending') === 'pending'): ?>
                <p class="mb-3">Please confirm whether you can fulfil this order, and state the delivery date you can commit to.</p>
            <?php else: ?>
                <p class="mb-3">
                    You responded on <?= display_date($po['supplier_response_at']) ?>.
                    You can change or withdraw that response until all ordered items have been received.
                </p>
                <?php if (!empty($po['supplier_response_notes'])): ?>
                    <p style="margin-bottom: 16px;"><?= nl2br(e($po['supplier_response_notes'])) ?></p>
                <?php endif; ?>
                <details style="margin-bottom: 16px;">
                    <summary class="btn btn-outline btn-sm" style="cursor: pointer;">Change response</summary>
            <?php endif; ?>

            <div style="display: flex; gap: 24px; flex-wrap: wrap;">
                <form method="post" action="<?= url('/supplier/purchase_orders/acknowledge.php') ?>" style="min-width: 280px; flex: 1;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $po['id'] ?>">
                    <div class="field">
                        <label for="supplier_proposed_delivery_date">Delivery date you can commit to</label>
                        <input type="date" id="supplier_proposed_delivery_date" name="supplier_proposed_delivery_date"
                            value="<?= e($po['supplier_proposed_delivery_date'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="acknowledge_notes">Note (optional)</label>
                        <textarea id="acknowledge_notes" name="supplier_response_notes" rows="3" maxlength="500"
                            placeholder="e.g. in stock, dispatch on Monday"><?= e(($po['supplier_response'] ?? '') === 'acknowledged' ? ($po['supplier_response_notes'] ?? '') : '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-accent">
                        <?= ($po['supplier_response'] ?? 'pending') === 'acknowledged' ? 'Update acknowledgement' : 'Acknowledge' ?>
                    </button>
                </form>

                <?php if (!$hasReceivedItems): ?>
                    <form method="post" action="<?= url('/supplier/purchase_orders/decline.php') ?>" style="min-width: 280px; flex: 1;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $po['id'] ?>">
                        <div class="field">
                            <label for="decline_notes">Reason for declining</label>
                            <textarea id="decline_notes" name="supplier_response_notes" rows="3" maxlength="500" required
                                placeholder="Tell the buyer why you cannot fulfil this order"><?= e(($po['supplier_response'] ?? '') === 'declined' ? ($po['supplier_response_notes'] ?? '') : '') ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-danger">
                            <?= ($po['supplier_response'] ?? '') === 'declined' ? 'Update decline' : 'Decline' ?>
                        </button>
                    </form>
                <?php else: ?>
                    <p class="muted" style="align-self: center;">Declining is unavailable after a partial delivery.</p>
                <?php endif; ?>
            </div>

            <?php if (($po['supplier_response'] ?? 'pending') !== 'pending'): ?>
                </details>
            <?php endif; ?>

            <?php if (($po['supplier_response'] ?? 'pending') !== 'pending'): ?>
                <form method="post" action="<?= url('/supplier/purchase_orders/withdraw.php') ?>" style="margin-top: 16px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $po['id'] ?>">
                    <button type="submit" class="btn btn-outline" data-confirm="Withdraw your response and start again?">Withdraw response</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!in_array($po['status'], ['sent', 'partially_received'], true) && ($po['supplier_response'] ?? 'pending') !== 'pending'): ?>
    <div class="panel" style="margin-top: 20px;">
        <div class="panel-head">
            <h3>Your response</h3>
            <span class="badge badge-<?= supplier_response_badge_class($po['supplier_response']) ?>"><?= e(supplier_response_label($po['supplier_response'])) ?></span>
        </div>
        <div class="panel-body">
            <p class="muted">
                Recorded <?= display_date($po['supplier_response_at']) ?><?= $po['status'] === 'received' ? ' — this order has been received.' : '.' ?>
            </p>
            <?php if (!empty($po['supplier_response_notes'])): ?>
                <p style="margin-bottom: 0;"><?= nl2br(e($po['supplier_response_notes'])) ?></p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div style="margin-top: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
    <a href="<?= url('/supplier/purchase_orders/index.php') ?>" class="btn btn-outline">
        <i class="bi bi-arrow-left me-1"></i>Back to Purchase Orders
    </a>
    <a href="<?= url('/supplier/purchase_orders/print.php?id=' . $po['id']) ?>" class="btn btn-primary" target="_blank" rel="noopener">
        <i class="bi bi-printer me-1"></i>Print / Save PDF
    </a>
</div>

<?php include __DIR__ . '/../../includes/supplier_layout_end.php'; ?>