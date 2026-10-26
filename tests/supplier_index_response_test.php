<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$indexFile = __DIR__ . '/../supplier/purchase_orders/index.php';
assert_true(file_exists($indexFile), 'Supplier purchase order index should exist.');

$contents = file_get_contents($indexFile);
assert_true(is_string($contents), 'Supplier purchase order index should be readable.');
assert_true(str_contains($contents, 'supplier_response') || str_contains($contents, 'Supplier response'), 'Supplier PO index should surface the supplier response status.');

// The dashboard's "Awaiting Your Response" tile links into a pending preset.
assert_true(str_contains($contents, "(\$_GET['response'] ?? '') === 'pending' ? 'pending' : ''"), 'Supplier PO index should accept only the pending-response preset.');
assert_true(str_contains($contents, "\$responseFilter === 'pending'"), 'Supplier PO index should act on the pending-response preset.');
assert_true(str_contains($contents, 'po.supplier_response = "pending"'), 'The pending preset should filter on supplier_response.');
assert_true(str_contains($contents, "'response' => \$responseFilter === 'pending' ? 'pending' : null"), 'Pagination should preserve the pending-response preset.');
assert_true(
    str_contains($contents, '<input type="hidden" name="response" value="pending">'),
    'Submitting search/date filters from the pending preset should preserve it.'
);
assert_true(
    str_contains($contents, 'name="status" <?= $responseFilter === \'pending\' ? \'disabled\' : \'\' ?>'),
    'The status selector should not allow a contradictory status while the pending preset is active.'
);
assert_true(
    str_contains($contents, "\$statusFilter = 'sent';")
        && str_contains($contents, "\$where[] = 'po.status = ?';")
        && str_contains($contents, "\$where[] = 'po.supplier_response = \"pending\"';"),
    'The pending preset should combine the sent status with the pending-response condition.'
);
assert_true(str_contains($contents, 'SUM(poi.quantity_ordered)'), 'Supplier list should total ordered units for receipt progress.');
assert_true(
    str_contains($contents, 'SUM(poi.quantity_received)')
        && str_contains($contents, '<?= $received ?> / <?= (int) ($order[\'total_ordered\'] ?? 0) ?> units'),
    'Received progress should compare received units against ordered units.'
);
assert_true(
    str_contains($contents, "in_array(\$order['status'], ['sent', 'partially_received'], true)")
        && str_contains($contents, '<th>Your response</th>'),
    'Supplier response should be visible for sent and partially received POs.'
);

echo "Supplier PO index response tests passed.\n";
