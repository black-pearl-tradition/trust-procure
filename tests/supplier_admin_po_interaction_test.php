<?php

function assert_interaction(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$root = __DIR__ . '/..';
$adminView = (string) file_get_contents($root . '/admin/purchase_orders/view.php');
$cancel = (string) file_get_contents($root . '/admin/purchase_orders/cancel.php');
$receive = (string) file_get_contents($root . '/admin/purchase_orders/receive.php');
$supplierView = (string) file_get_contents($root . '/supplier/purchase_orders/view.php');
$acknowledge = (string) file_get_contents($root . '/supplier/purchase_orders/acknowledge.php');
$decline = (string) file_get_contents($root . '/supplier/purchase_orders/decline.php');
$withdraw = (string) file_get_contents($root . '/supplier/purchase_orders/withdraw.php');
$supplierLayout = (string) file_get_contents($root . '/includes/supplier_layout_start.php');

assert_interaction(
    str_contains($adminView, "in_array(\$po['status'], ['sent', 'partially_received'], true) && (\$po['supplier_response'] ?? 'pending') === 'declined'"),
    'Admin should be offered cancellation when an open PO is declined.'
);
assert_interaction(
    str_contains($cancel, "OR (status IN ('sent', 'partially_received') AND supplier_response = 'declined')"),
    'Cancellation endpoint should allow declined open POs.'
);
assert_interaction(str_contains($cancel, 'notify_supplier('), 'Supplier should be notified when a sent PO is cancelled.');
assert_interaction(
    str_contains($receive, "\$lockedDelivery['supplier_response'] === 'declined'"),
    'Admin must not record goods against a declined PO.'
);

$openStatuses = "status IN ('sent', 'partially_received')";
assert_interaction(str_contains($acknowledge, $openStatuses), 'Supplier should be able to update acknowledgement after partial receipt.');
assert_interaction(
    str_contains($decline, 'SUM(quantity_received)')
        && str_contains($decline, 'after a partial delivery has been received'),
    'Supplier cannot decline after any quantity has been received.'
);
assert_interaction(str_contains($withdraw, $openStatuses), 'Supplier should be able to withdraw a response after partial receipt.');
assert_interaction(
    str_contains($supplierView, "in_array(\$po['status'], ['sent', 'partially_received'], true)")
        && str_contains($supplierView, 'until all ordered items have been received')
        && str_contains($supplierView, 'Declining is unavailable after a partial delivery.'),
    'Supplier response UI should remain available and accurately describe its response window.'
);
assert_interaction(
    str_contains($supplierLayout, "status = 'sent' AND supplier_response = 'pending'"),
    'Supplier purchase-order badge should count only unanswered sent POs.'
);
assert_interaction(
    str_contains($supplierView, "array_sum(array_column(\$items, 'quantity_received'))"),
    'Supplier detail should use received quantities to guard the decline action.'
);
assert_interaction(
    str_contains($adminView, '<details class="panel"')
        && str_contains($adminView, 'pending review'),
    'Admin document and payment sections should be collapsible, with pending invoices surfaced.'
);

echo "Supplier/admin PO interaction regression tests passed.\n";
