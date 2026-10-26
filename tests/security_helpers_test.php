<?php

session_start();

require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

function assert_equal($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'ASSERTION FAILED: %s | expected=%s actual=%s',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_count(int $expected, array $items, string $message): void
{
    assert_equal($expected, count($items), $message);
}

$_SESSION = [];

$passwordErrors = password_policy_errors('StrongPass1');
assert_count(0, $passwordErrors, 'Strong password should pass the policy.');

$weakErrors = password_policy_errors('weak');
assert_true(count($weakErrors) >= 3, 'Weak password should fail multiple checks.');

$email = 'user@example.com';
assert_true(!login_attempts_exceeded($email), 'Fresh login should not be rate-limited.');

for ($i = 0; $i < 5; $i++) {
    record_failed_login($email);
}
assert_true(login_attempts_exceeded($email), 'After five failed attempts, login should be rate-limited.');

clear_login_attempts($email);
assert_true(!login_attempts_exceeded($email), 'Clearing attempts should reset the login lockout.');

assert_true(validate_email_address('user@example.com'), 'Valid email addresses should pass validation.');
assert_true(!validate_email_address('bad-email'), 'Invalid email addresses should fail validation.');
assert_true(!validate_email_address("user@example.com\n"), 'Email addresses with control characters should be rejected.');
assert_true(!validate_email_address("user@example.com\nBcc:evil@example.com"), 'Email addresses with embedded control characters should be rejected.');
assert_true(!validate_email_address('user@example..com'), 'Email addresses with repeated dots in the domain should be rejected.');
assert_true(!validate_email_address('user@localhost'), 'Local-only addresses should be rejected for this app.');
assert_equal('products', normalize_upload_subfolder('products'), 'Known upload folders should be accepted unchanged.');
assert_equal('products', normalize_upload_subfolder('../products'), 'Traversal attempts should be normalized to a safe folder name.');

foreach (['/includes/user_layout_start.php', '/includes/supplier_layout_start.php', '/includes/admin_layout_start.php'] as $layoutPath) {
    $contents = (string) file_get_contents(__DIR__ . '/..' . $layoutPath);
    assert_true(
        str_contains($contents, 'View site') && str_contains($contents, "url('/index.php')"),
        'Each role sidebar should expose a public-site shortcut.'
    );
}

$authFile = __DIR__ . '/../includes/auth_check.php';
$authContents = (string) file_get_contents($authFile);
assert_true(
    str_contains($authContents, 'if (is_supplier())') && str_contains($authContents, "redirect('/supplier/dashboard.php');"),
    'Customer-only gate should reject supplier accounts as well as staff/admins.'
);

$headerFile = __DIR__ . '/../includes/header.php';
$headerContents = (string) file_get_contents($headerFile);
assert_true(
    str_contains($headerContents, "require_once __DIR__ . '/auth_check.php';"),
    'Public header should load the auth helpers so role_label() is available for logged-in users.'
);

$supplierLayout = __DIR__ . '/../includes/supplier_layout_start.php';
$supplierLayoutContents = (string) file_get_contents($supplierLayout);
assert_true(
    str_contains($supplierLayoutContents, 'id="sidebarBackdrop"'),
    'Supplier layout should include the sidebar backdrop for the mobile sidebar toggle.'
);

$scriptFile = __DIR__ . '/../assets/js/script.js';
$scriptContents = (string) file_get_contents($scriptFile);
assert_true(
    str_contains($scriptContents, 'sidebar.classList.add("open")')
        && str_contains($scriptContents, 'sidebar.classList.remove("open")')
        && str_contains($scriptContents, 'sidebarBackdrop.classList.add("visible")')
        && str_contains($scriptContents, 'sidebarBackdrop.classList.remove("visible")'),
    'Shared mobile sidebar script should open and close the sidebar and backdrop together.'
);

$accountFile = __DIR__ . '/../account.php';
$accountContents = (string) file_get_contents($accountFile);
assert_true(
    str_contains($accountContents, 'if (is_supplier())')
        && str_contains($accountContents, 'is_staff_or_above()')
        && str_contains($accountContents, "/includes/supplier_layout_start.php")
        && str_contains($accountContents, "/includes/admin_layout_start.php"),
    'Account page should use the supplier layout for suppliers and the admin layout for staff/admins.'
);

echo "All security helper tests passed.\n";
