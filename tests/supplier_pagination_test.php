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

// The page parameter must be sanitised before it reaches the OFFSET binding:
// a page below 1 would bind a negative OFFSET, which MySQL rejects outright.
assert_true(
    str_contains($contents, '$page = max(1, (int) ($_GET[\'page\'] ?? 1));'),
    'Supplier PO index should clamp the page parameter to 1 or greater.'
);
assert_true(
    str_contains($contents, 'if ($page > $totalPages) {'),
    'Supplier PO index should clamp a page beyond the last page.'
);
assert_true(
    str_contains($contents, '$offset = ($page - 1) * $perPage;'),
    'Supplier PO index should derive the offset from the clamped page.'
);

// The invariant the clamp protects, evaluated with the same formula the page
// uses: the bound OFFSET must never be negative, and any request past the last
// page must resolve to that last page instead of an empty table.
$perPage = 15;

foreach ([0, -5, 1] as $requested) {
    $page = max(1, (int) $requested);
    $offset = ($page - 1) * $perPage;
    assert_true($offset >= 0, 'Requested page ' . $requested . ' should never produce a negative offset.');
    assert_true($page === 1, 'Requested page ' . $requested . ' should resolve to page 1.');
}

$totalPages = 2;
$page = 99;
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}
assert_true($page === 2, 'A page past the end should resolve to the last page.');
assert_true($offset === 15, 'The clamped page should offset by exactly one page of results.');

echo "Supplier PO pagination tests passed.\n";
