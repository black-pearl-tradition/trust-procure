<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

// config/database.php turns emulated prepares off, so PDO refuses any statement
// that mixes ? placeholders with :named ones (SQLSTATE[HY093] "mixed named and
// positional parameters"). Paginated lists must therefore bind positionally all
// the way through, including LIMIT/OFFSET.
$files = [];
foreach (['admin', 'supplier', 'user'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

assert_true(count($files) > 50, 'Expected to scan the whole portal, found ' . count($files) . ' files.');

$offenders = [];
foreach ($files as $file) {
    $contents = (string) file_get_contents($file);

    // Strip comments, so a note explaining this very trap cannot trip the check.
    $code = (string) preg_replace('~/\*.*?\*/~s', '', $contents);
    $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

    $usesPositional = str_contains($code, ' = ?') || str_contains($code, 'LIKE ?');

    if ($usesPositional && (str_contains($code, ':limit') || str_contains($code, ':offset'))) {
        $offenders[] = str_replace('\\', '/', substr($file, strlen(realpath(__DIR__ . '/../')) + 1));
    }
}

assert_true($offenders === [], 'These pages mix ? placeholders with named paging parameters: ' . implode(', ', $offenders));

// The supplier purchase order list always binds its supplier id positionally, so
// it must bind the page size the same way.
$supplierList = (string) file_get_contents(__DIR__ . '/../supplier/purchase_orders/index.php');
assert_true(str_contains($supplierList, 'LIMIT ? OFFSET ?'), 'The supplier PO list should bind its page size positionally.');
assert_true(str_contains($supplierList, 'count($params) + 1'), 'The supplier PO list should bind the page size after its filters.');

echo "Pagination binding tests passed.\n";
