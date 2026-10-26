<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$viewFile = __DIR__ . '/../supplier/purchase_orders/view.php';
$printFile = __DIR__ . '/../supplier/purchase_orders/print.php';

assert_true(file_exists($printFile), 'Supplier purchase order print page should exist.');
assert_true(file_exists($viewFile), 'Supplier purchase order detail page should exist.');

$viewContents = file_get_contents($viewFile);
assert_true(is_string($viewContents), 'Supplier purchase order detail file should be readable.');
assert_true(str_contains($viewContents, '/supplier/purchase_orders/print.php'), 'Supplier PO view should link to the supplier print page.');

echo "Supplier purchase order print flow tests passed.\n";
