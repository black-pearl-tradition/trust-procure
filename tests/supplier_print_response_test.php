<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$printFile = __DIR__ . '/../supplier/purchase_orders/print.php';
assert_true(file_exists($printFile), 'Supplier print page should exist.');

$contents = file_get_contents($printFile);
assert_true(is_string($contents), 'Supplier print page should be readable.');
assert_true(str_contains($contents, 'Supplier response') || str_contains($contents, 'supplier_response'), 'Supplier print document should include the supplier response status.');

echo "Supplier print response tests passed.\n";
