<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_payment_workflow(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$paymentHandler = (string) file_get_contents(__DIR__ . '/../admin/purchase_orders/record_payment.php');
$invoiceHandler = (string) file_get_contents(__DIR__ . '/../admin/purchase_orders/review_invoice.php');
$adminView = (string) file_get_contents(__DIR__ . '/../admin/purchase_orders/view.php');
$supplierView = (string) file_get_contents(__DIR__ . '/../supplier/purchase_orders/view.php');
$paymentHelper = (string) file_get_contents(__DIR__ . '/../includes/helpers/supplier.php');
$statusLabels = (string) file_get_contents(__DIR__ . '/../includes/helpers/status_labels.php');
$sharedStyles = (string) file_get_contents(__DIR__ . '/../assets/css/style.css');

assert_payment_workflow(
    str_contains($paymentHandler, "['approved', 'sent', 'partially_received', 'received']"),
    'Payments should only be recordable for approved purchase orders.'
);
assert_payment_workflow(
    str_contains($paymentHandler, 'FOR UPDATE')
        && str_contains($paymentHandler, 'SELECT amount FROM supplier_payments WHERE purchase_order_id = ? FOR UPDATE'),
    'The PO and its existing payment rows should be locked while validating a disbursement.'
);
assert_payment_workflow(
    str_contains($paymentHandler, '$amount > $outstanding + 0.01'),
    'A disbursement should not exceed the current outstanding PO balance.'
);
assert_payment_workflow(
    str_contains($paymentHandler, 'recalculate_po_payment_status($pdo, $poId)'),
    'Recording a disbursement should update the PO payment summary from the ledger.'
);
assert_payment_workflow(
    !str_contains($paymentHandler, 'UPDATE supplier_invoices'),
    'Recording a PO-level disbursement must not settle supplier invoices automatically.'
);

assert_payment_workflow(
    str_contains($invoiceHandler, "['approve', 'reject']"),
    'Invoice review should allow approval and rejection only.'
);
assert_payment_workflow(
    str_contains($invoiceHandler, "function_exists('mb_substr')")
        && str_contains($invoiceHandler, "preg_split('//u'"),
    'Invoice note truncation should work when mbstring is unavailable.'
);
assert_payment_workflow(
    str_contains($invoiceHandler, 'legacy settled status'),
    'Existing settled invoice records should be protected from further review.'
);
assert_payment_workflow(
    !str_contains($adminView, 'name="action" value="settle"')
        && !str_contains($adminView, '>Mark settled<'),
    'The admin invoice table should not offer a separate settle action.'
);
assert_payment_workflow(
    str_contains($adminView, 'record actual disbursements in the payment ledger')
        && str_contains($adminView, 'Record Supplier Disbursement')
        && str_contains($adminView, 'An approved supplier invoice needs PO/GRN reconciliation')
        && str_contains($adminView, 'PO / GRN Check')
        && str_contains($adminView, '<dialog class="po-dialog" id="po-payment-dialog"')
        && str_contains($adminView, 'data-open-dialog="po-payment-dialog"'),
    'The admin view should explain the PO-level workflow and present payment input in a modal.'
);
assert_payment_workflow(
    str_contains($adminView, 'Advances remain available')
        && str_contains($adminView, 'supplier_invoice_match_summary')
        && str_contains($adminView, 'po_verified_grn_coverage'),
    'Invoice/GRN mismatches should be visible for review without blocking PO-level advances.'
);
assert_payment_workflow(
    str_contains($adminView, 'id="po-add-item-dialog"')
        && str_contains($adminView, 'id="po-header-dialog"')
        && str_contains($adminView, 'id="po-cancel-dialog"')
        && str_contains($adminView, 'id="po-reject-dialog"')
        && str_contains($adminView, 'data-open-dialog="invoice-review-')
        && str_contains($adminView, 'dialog.showModal()'),
    'PO data-entry, invoice review, rejection, and cancellation forms should open in accessible dialogs.'
);
$actionPanelPosition = strpos($adminView, 'class="panel po-action-panel"');
$overviewPosition = strpos($adminView, 'class="panel po-overview"');
assert_payment_workflow(
    $actionPanelPosition !== false
        && $overviewPosition !== false
        && $actionPanelPosition < $overviewPosition
        && str_contains($adminView, 'id="po-submit-dialog"')
        && str_contains($adminView, 'id="po-approve-dialog"')
        && str_contains($adminView, 'id="po-send-dialog"')
        && str_contains($adminView, 'id="po-revise-dialog"')
        && str_contains($adminView, 'id="po-accept-date-dialog"')
        && str_contains($adminView, 'id="po-remove-item-dialog"')
        && str_contains($adminView, 'id="po-duplicate-dialog"')
        && str_contains($adminView, 'href="#po-invoice-section"')
        && str_contains($adminView, 'id="po-invoice-section"'),
    'Priority workflow actions should appear near the top and open confirmation dialogs before submitting.'
);
assert_payment_workflow(
    !preg_match('/\sstyle\s*=/i', $adminView)
        && str_contains($sharedStyles, '.po-detail-page .po-hero')
        && str_contains($sharedStyles, '.po-dialog {'),
    'PO detail presentation should use scoped shared styles instead of page-local inline styling.'
);
$invoiceDialogPosition = strpos($adminView, '<dialog class="po-dialog" id="invoice-review-');
$invoiceTableStartPosition = $invoiceDialogPosition === false
    ? false
    : strrpos(substr($adminView, 0, $invoiceDialogPosition), '<table');
$invoiceTableEndPosition = $invoiceDialogPosition === false
    ? false
    : strrpos(substr($adminView, 0, $invoiceDialogPosition), '</table>');
assert_payment_workflow(
    $invoiceDialogPosition !== false
        && $invoiceTableStartPosition !== false
        && $invoiceTableEndPosition !== false
        && $invoiceTableStartPosition < $invoiceTableEndPosition
        && $invoiceTableEndPosition < $invoiceDialogPosition,
    'Invoice review dialogs should be rendered outside the invoice table.'
);
assert_payment_workflow(
    str_contains($adminView, 'Full payment'),
    'The final payment type should not imply invoice settlement.'
);

assert_payment_workflow(
    str_contains($supplierView, 'FROM supplier_payments sp')
        && str_contains($supplierView, 'Payments from buyer'),
    'Suppliers should see their PO payment ledger.'
);
assert_payment_workflow(
    str_contains($supplierView, '$amountPaid')
        && str_contains($supplierView, '$outstandingBalance')
        && str_contains($supplierView, 'View proof'),
    'The supplier view should show paid/outstanding totals and payment proof links.'
);
assert_payment_workflow(
    str_contains($paymentHelper, 'SUM(amount)')
        && str_contains($paymentHelper, 'payment_status = ?'),
    'PO payment status should remain derived from the payment ledger.'
);
assert_payment_workflow(
    str_contains($statusLabels, "'settled'  => 'Settled (legacy)'"),
    'Legacy settled invoice statuses should be identifiable without creating new settled records.'
);

$fullCoverage = ['line_count' => 2, 'fully_accepted_lines' => 2];
$partialCoverage = ['line_count' => 2, 'fully_accepted_lines' => 1];
$invoice = ['invoice_type' => 'tax_invoice', 'total_amount' => 1250.00];
assert_payment_workflow(
    supplier_invoice_match_summary($invoice, 1250.00, $fullCoverage)['matched'],
    'A matching invoice total with full verified GRN coverage should be reported as matched.'
);
assert_payment_workflow(
    supplier_invoice_match_summary($invoice, 1251.00, $fullCoverage)['label'] === 'Invoice total differs from PO',
    'An invoice total that differs from the PO should be flagged for review.'
);
assert_payment_workflow(
    supplier_invoice_match_summary($invoice, 1250.00, $partialCoverage)['label'] === 'Awaiting full verified GRN',
    'Incomplete verified GRN coverage should be flagged even when the invoice total matches.'
);
assert_payment_workflow(
    supplier_invoice_match_summary(
        ['invoice_type' => 'credit_note', 'total_amount' => 20.00],
        1250.00,
        $fullCoverage
    )['label'] === 'Credit note — manual review',
    'Credit notes should be routed for manual reconciliation.'
);

echo "Supplier payment workflow tests passed.\n";
