<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$indexFile = __DIR__ . '/../admin/purchase_orders/index.php';
assert_true(file_exists($indexFile), 'Admin purchase order index should exist.');

$contents = file_get_contents($indexFile);
assert_true(is_string($contents), 'Admin purchase order index should be readable.');
assert_true(str_contains($contents, 'Supplier Response') || str_contains($contents, 'supplier_response'), 'Admin PO index should surface the supplier response column.');

echo "Admin PO index response tests passed.\n";
