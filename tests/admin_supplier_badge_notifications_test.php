<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$contactFile = __DIR__ . '/../contact.php';
$paymentSubmitFile = __DIR__ . '/../user/payments/add.php';
$userMessagesFile = __DIR__ . '/../user/messages/index.php';
$adminLayoutFile = __DIR__ . '/../includes/admin_layout_start.php';
$supplierLayoutFile = __DIR__ . '/../includes/supplier_layout_start.php';
$userLayoutFile = __DIR__ . '/../includes/user_layout_start.php';

assert_true(file_exists($contactFile), 'Contact form page should exist.');
assert_true(file_exists($paymentSubmitFile), 'Payment submission page should exist.');
assert_true(file_exists($userMessagesFile), 'User messages page should exist.');
assert_true(file_exists($adminLayoutFile), 'Admin layout should exist.');
assert_true(file_exists($supplierLayoutFile), 'Supplier layout should exist.');
assert_true(file_exists($userLayoutFile), 'User layout should exist.');

$contactContents = (string) file_get_contents($contactFile);
$paymentContents = (string) file_get_contents($paymentSubmitFile);
$userMessagesContents = (string) file_get_contents($userMessagesFile);
$adminLayoutContents = (string) file_get_contents($adminLayoutFile);
$supplierLayoutContents = (string) file_get_contents($supplierLayoutFile);
$userLayoutContents = (string) file_get_contents($userLayoutFile);

assert_true(str_contains($contactContents, 'create_notification('), 'Submitting a contact form should notify staff/admin in the portal.');
assert_true(str_contains($paymentContents, 'create_notification('), 'Submitting a payment should notify staff/admin in the portal.');
assert_true(str_contains($userMessagesContents, 'SET is_read = 1'), 'Opening the user messages page should mark support replies as read.');
assert_true(str_contains($adminLayoutContents, 'pendingPayments'), 'Admin layout should count pending payments for a badge.');
assert_true(str_contains($adminLayoutContents, "Payments' && \$pendingPayments > 0"), 'Admin layout should show a payment badge when pending payments are waiting.');
assert_true(str_contains($supplierLayoutContents, 'pendingPOs'), 'Supplier layout should count new purchase orders for a badge.');
assert_true(str_contains($supplierLayoutContents, "Purchase Orders' && \$pendingPOs > 0"), 'Supplier layout should show a purchase-order badge when new orders are waiting.');
assert_true(str_contains($userLayoutContents, "Messages' && \$unreadReplies > 0"), 'User navigation should show a badge for unread support replies.');

echo "Admin/supplier notification badge regression tests passed.\n";
