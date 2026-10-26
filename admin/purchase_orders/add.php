<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'New Purchase Order';
$errors = [];

$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name")->fetchAll();

// Optional deep-link from an order's page: preselect the supplier and
// remember which order/product prompted this PO so view.php can offer
// to add that line straight away.
$prefillSupplierId = (int) ($_GET['supplier_id'] ?? 0);
$prefillOrderId = (int) ($_GET['order_id'] ?? 0);
$prefillProductId = (int) ($_GET['product_id'] ?? 0);
$prefillQuotePrice = isset($_GET['quote_price']) && $_GET['quote_price'] !== '' ? (float) $_GET['quote_price'] : null;
$prefillSourceSupplierProductId = (int) ($_GET['source_supplier_product_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/admin/purchase_orders/index.php');

    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    $expectedDate = trim($_POST['expected_delivery_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $prefillOrderId = (int) ($_POST['prefill_order_id'] ?? 0);
    $prefillProductId = (int) ($_POST['prefill_product_id'] ?? 0);
    $prefillQuotePrice = isset($_POST['prefill_quote_price']) && $_POST['prefill_quote_price'] !== '' ? (float) $_POST['prefill_quote_price'] : null;
    $prefillSourceSupplierProductId = (int) ($_POST['prefill_source_supplier_product_id'] ?? 0);

    if (!$supplierId) {
        $errors[] = 'Please choose a supplier.';
    }

    if (!$errors) {
        $poNumber = generate_po_number($pdo);

        if ($prefillProductId > 0 && $prefillQuotePrice !== null && $prefillQuotePrice > 0) {
            $quoteNote = 'RFQ quote selected for product #' . $prefillProductId . ' at UGX ' . number_format($prefillQuotePrice, 2, '.', ',');
            $notes = trim($notes !== '' ? $notes . ' | ' . $quoteNote : $quoteNote);
        }
        if ($prefillSourceSupplierProductId > 0 && $prefillProductId > 0) {
            $quoteSourceCheck = $pdo->prepare('SELECT id FROM supplier_products WHERE id = ? AND supplier_id = ? AND product_id = ?');
            $quoteSourceCheck->execute([$prefillSourceSupplierProductId, $supplierId, $prefillProductId]);
            if (!$quoteSourceCheck->fetch()) {
                $prefillSourceSupplierProductId = 0;
            }
        }

        // Fetch supplier's default payment terms to set default payment_due_date
        $supStmt = $pdo->prepare('SELECT payment_terms FROM suppliers WHERE id = ?');
        $supStmt->execute([$supplierId]);
        $supplierTerms = $supStmt->fetchColumn() ?: 'net_30';
        $paymentDueDate = calculate_po_payment_due_date($supplierTerms, $expectedDate ?: null);

        $stmt = $pdo->prepare(
            'INSERT INTO purchase_orders (po_number, supplier_id, expected_delivery_date, notes, created_by, payment_due_date)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$poNumber, $supplierId, $expectedDate ?: null, $notes ?: null, $_SESSION['user_id'], $paymentDueDate]);
        $poId = (int) $pdo->lastInsertId();

        log_activity($pdo, $_SESSION['user_id'], 'po_created', "Created purchase order $poNumber");
        set_flash('success', "Purchase order $poNumber created. Add items to it below.");

        $redirect = '/admin/purchase_orders/view.php?id=' . $poId;
        if ($prefillOrderId) {
            $redirect .= '&order_id=' . $prefillOrderId;
        }
        if ($prefillProductId > 0) {
            $redirect .= '&product_id=' . $prefillProductId;
        }
        if ($prefillQuotePrice !== null && $prefillQuotePrice > 0) {
            $redirect .= '&quote_price=' . rawurlencode((string) $prefillQuotePrice);
        }
        if ($prefillSourceSupplierProductId > 0) {
            $redirect .= '&source_supplier_product_id=' . (int) $prefillSourceSupplierProductId;
        }
        redirect($redirect);
    }
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head"><h2>New purchase order</h2></div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <form method="post" style="max-width: 480px;">
            <?= csrf_field() ?>
            <input type="hidden" name="prefill_order_id" value="<?= $prefillOrderId ?>">
            <input type="hidden" name="prefill_product_id" value="<?= $prefillProductId ?>">
            <input type="hidden" name="prefill_quote_price" value="<?= $prefillQuotePrice !== null ? e((string) $prefillQuotePrice) : '' ?>">
            <input type="hidden" name="prefill_source_supplier_product_id" value="<?= $prefillSourceSupplierProductId ?>">
            <div class="field">
                <label for="supplier_id">Supplier</label>
                <select id="supplier_id" name="supplier_id" required>
                    <option value="">Select a supplier</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $prefillSupplierId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$suppliers): ?>
                    <div class="hint">No active suppliers yet — <a href="<?= url('/admin/suppliers/add.php') ?>">add one first</a>.</div>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="expected_delivery_date">Expected delivery date</label>
                <input type="date" id="expected_delivery_date" name="expected_delivery_date">
            </div>
            <div class="field">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" placeholder="e.g. internal reference, special instructions"></textarea>
            </div>
            <div class="table-actions">
                <button type="submit" class="btn btn-accent">Create draft</button>
                <a href="<?= url('/admin/purchase_orders/index.php') ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
