<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$viewFile = __DIR__ . '/../admin/purchase_orders/view.php';
assert_true(file_exists($viewFile), 'Admin PO detail page should exist.');

$viewContents = file_get_contents($viewFile);
assert_true(is_string($viewContents), 'Admin PO detail page should be readable.');
assert_true(str_contains($viewContents, 'Supplier response') || str_contains($viewContents, 'supplier_response'), 'Admin PO detail page should display supplier response details.');

echo "Admin PO supplier response tests passed.\n";
