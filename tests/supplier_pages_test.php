<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$profileFile = __DIR__ . '/../supplier/profile/index.php';
$productsFile = __DIR__ . '/../supplier/products/index.php';
$layoutFile = __DIR__ . '/../includes/supplier_layout_start.php';

assert_true(file_exists($profileFile), 'Supplier profile page should exist.');
assert_true(file_exists($productsFile), 'Supplier products page should exist.');
assert_true(file_exists($layoutFile), 'Supplier layout should exist.');

$profileContents = (string) file_get_contents($profileFile);
$productsContents = (string) file_get_contents($productsFile);
$layoutContents = (string) file_get_contents($layoutFile);

// Both pages are reachable by suppliers, guarded only by the role check.
assert_true(str_contains($profileContents, 'require_supplier();'), 'Supplier profile page should require the supplier role.');
assert_true(str_contains($productsContents, 'require_supplier();'), 'Supplier products page should require the supplier role.');
assert_true(!str_contains($profileContents, 'is_supplier()'), 'Supplier profile page should not redirect suppliers away.');
assert_true(!str_contains($productsContents, 'is_supplier()'), 'Supplier products page should not redirect suppliers away.');

// The profile editor may only touch the current supplier's own row, and must
// leave the administrator-controlled fields (email, status) alone.
assert_true(str_contains($profileContents, 'verify_csrf('), 'Supplier profile page should verify the CSRF token.');
assert_true(str_contains($profileContents, 'WHERE id = ?'), 'Supplier profile page should scope the update to one supplier row.');
assert_true(str_contains($profileContents, "execute([\$name, \$contactPerson, \$phone, \$address, \$notes, \$supplier['id']])"), 'Supplier profile page should bind the supplier id last.');
assert_true(!str_contains($profileContents, 'status = ?'), 'Supplier profile page should not let suppliers change their own status.');
assert_true(!str_contains($profileContents, 'name="status"'), 'Supplier profile page should not post a status field.');

// The catalogue page stays read-only.
foreach (['UPDATE ', 'INSERT INTO', 'DELETE FROM'] as $statement) {
    assert_true(!str_contains($productsContents, $statement), 'Supplier products page should stay read-only (no ' . trim($statement) . ').');
}
assert_true(str_contains($productsContents, 'supplier_products'), 'Supplier products page should list the products linked to the supplier.');

// The portal navigation exposes both pages again.
assert_true(str_contains($layoutContents, '/supplier/profile/index.php'), 'Supplier navigation should link to the profile page.');
assert_true(str_contains($layoutContents, '/supplier/products/index.php'), 'Supplier navigation should link to the products page.');

echo "Supplier portal page tests passed.\n";
