<?php

session_start();

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$indexFile = __DIR__ . '/../admin/system/index.php';
$clearFile = __DIR__ . '/../admin/system/clear.php';
$softResetFile = __DIR__ . '/../admin/system/soft_reset.php';

assert_true(file_exists($indexFile), 'Super-admin system clear page should exist.');
assert_true(file_exists($clearFile), 'Super-admin clear action should exist.');
assert_true(file_exists($softResetFile), 'Super-admin soft reset should exist.');

$indexContents = (string) file_get_contents($indexFile);
$clearContents = (string) file_get_contents($clearFile);
$softContents = (string) file_get_contents($softResetFile);

assert_true(str_contains($indexContents, 'require_super_admin();'), 'System clear page should require the super-admin role.');
assert_true(str_contains($indexContents, 'Clear system data'), 'System clear page should be labeled clearly.');
assert_true(str_contains($indexContents, 'Soft reset'), 'System page should offer a soft reset option.');
assert_true(str_contains($indexContents, 'What will be cleared'), 'Reset page should explicitly list what will be cleared.');
assert_true(str_contains($indexContents, 'Review reset'), 'Reset page should use a two-step review before execution.');
assert_true(str_contains($indexContents, 'I understand the consequences'), 'Reset page should require an acknowledgement checkbox before execution.');
assert_true(str_contains($clearContents, 'verify_csrf'), 'Clear action should use CSRF protection.');
assert_true(str_contains($clearContents, 'DELETE FROM'), 'Clear action should execute destructive database cleanup.');
assert_true(str_contains($softContents, 'verify_csrf'), 'Soft reset should use CSRF protection.');
assert_true(str_contains($softContents, 'DELETE FROM products'), 'Soft reset should clear catalogue data while keeping user records.');

echo "Super admin clear data tests passed.\n";
