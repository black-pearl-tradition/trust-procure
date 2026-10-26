<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$indexFile = __DIR__ . '/../supplier/notifications/index.php';
$readFile = __DIR__ . '/../supplier/notifications/read.php';
$clearFile = __DIR__ . '/../supplier/notifications/clear.php';
$layoutFile = __DIR__ . '/../includes/supplier_layout_start.php';
$sendFile = __DIR__ . '/../admin/purchase_orders/send.php';

assert_true(file_exists($indexFile), 'Supplier notifications page should exist.');
assert_true(file_exists($readFile), 'Supplier notification read action should exist.');
assert_true(file_exists($clearFile), 'Supplier notification clear action should exist.');

$indexContents = (string) file_get_contents($indexFile);
$readContents = (string) file_get_contents($readFile);
$clearContents = (string) file_get_contents($clearFile);
$layoutContents = (string) file_get_contents($layoutFile);
$sendContents = (string) file_get_contents($sendFile);

// Supplier portal notification pages must remain reachable by supplier
// accounts rather than redirecting them away.
assert_true(str_contains($indexContents, 'require_supplier();'), 'Supplier notifications page should require the supplier role.');
assert_true(str_contains($readContents, 'require_supplier();'), 'Supplier notification read action should require the supplier role.');
assert_true(str_contains($clearContents, 'require_supplier();'), 'Supplier notification clear action should require the supplier role.');
assert_true(!str_contains($indexContents, 'is_supplier()'), 'Supplier notifications page should not redirect suppliers away.');
assert_true(!str_contains($readContents, 'is_supplier()'), 'Supplier notification read action should not redirect suppliers away.');
assert_true(!str_contains($clearContents, 'is_supplier()'), 'Supplier notification clear action should not redirect suppliers away.');

// State-changing actions must be CSRF-protected and scoped to the current user.
assert_true(str_contains($readContents, 'verify_csrf('), 'Supplier notification read action should verify the CSRF token.');
assert_true(str_contains($clearContents, 'verify_csrf('), 'Supplier notification clear action should verify the CSRF token.');
assert_true(str_contains($readContents, 'user_id = ?'), 'Supplier notification read action should scope the notification to its owner.');
assert_true(str_contains($clearContents, 'WHERE user_id = ?'), 'Supplier notification clear action should only clear its owner\'s notifications.');

assert_true(str_contains($layoutContents, '/supplier/notifications/index.php'), 'Supplier navigation should link to the notifications page.');
assert_true(str_contains($sendContents, 'notify_supplier('), 'Sending a purchase order should notify the supplier in the portal.');

assert_true(function_exists('notify_supplier'), 'Missing supplier notification helper: notify_supplier');
assert_true(function_exists('get_supplier_user_id'), 'Missing supplier account lookup helper: get_supplier_user_id');

// Behavioural check of the account lookup: a missing binding must fail closed
// (null) instead of letting a notification land on the wrong account.
final class SupplierLookupStub
{
    /** @param array<int, mixed> $queue */
    public function __construct(private array $queue)
    {
    }

    public function prepare(string $query): self
    {
        return $this;
    }

    /** @param array<int, mixed> $params */
    public function execute(array $params = []): bool
    {
        return true;
    }

    public function fetchColumn(): mixed
    {
        return array_shift($this->queue);
    }
}

$pdo = new SupplierLookupStub([false]);
assert_true(get_supplier_user_id(7) === null, 'A supplier with no linked portal account should resolve to null.');

$pdo = new SupplierLookupStub(['42']);
assert_true(get_supplier_user_id(7) === 42, 'A linked supplier account should resolve to its integer user id.');

// With no portal account, notify_supplier() must return before it reaches
// create_notification() — otherwise the stub above would raise a TypeError.
$pdo = new SupplierLookupStub([false]);
$threw = false;
try {
    notify_supplier(7, 'Purchase order PO-1', 'Please review this order.');
} catch (Throwable $e) {
    $threw = true;
}
assert_true(!$threw, 'notify_supplier should be a no-op when no portal account is linked.');

echo "Supplier notification tests passed.\n";
