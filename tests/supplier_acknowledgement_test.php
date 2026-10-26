<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$viewFile = __DIR__ . '/../supplier/purchase_orders/view.php';
$ackFile = __DIR__ . '/../supplier/purchase_orders/acknowledge.php';
$declineFile = __DIR__ . '/../supplier/purchase_orders/decline.php';

assert_true(file_exists($ackFile), 'Supplier acknowledgement action should exist.');
assert_true(file_exists($declineFile), 'Supplier rejection action should exist.');
assert_true(file_exists($viewFile), 'Supplier purchase order detail page should exist.');

$viewContents = file_get_contents($viewFile);
assert_true(is_string($viewContents), 'Supplier purchase order view file should be readable.');
assert_true(str_contains($viewContents, 'Acknowledge') || str_contains($viewContents, 'Ack'), 'Supplier PO view should show a way to acknowledge a sent PO.');
assert_true(str_contains($viewContents, 'Decline') || str_contains($viewContents, 'Reject'), 'Supplier PO view should show a way to decline a sent PO.');

echo "Supplier PO acknowledgement flow tests passed.\n";
