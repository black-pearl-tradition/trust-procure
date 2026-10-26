<?php

function assert_customer_receipt(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$receiptFile = __DIR__ . '/../user/orders/receipt.php';
$receiptContents = (string) file_get_contents($receiptFile);

assert_customer_receipt(
    str_contains($receiptContents, 'SELECT status FROM orders WHERE id = ? AND user_id = ? FOR UPDATE'),
    'Receipt access should lock the owned order while classifying original and duplicate copies.'
);
assert_customer_receipt(
    str_contains($receiptContents, "'customer_receipt_issued'")
        && str_contains($receiptContents, "'customer_receipt_copy'")
        && str_contains($receiptContents, "'Original receipt'")
        && str_contains($receiptContents, "'Duplicate receipt copy #'"),
    'The first receipt access should be distinguished from numbered duplicate copies.'
);
assert_customer_receipt(
    str_contains($receiptContents, 'INSERT INTO order_events')
        && str_contains($receiptContents, 'receiptIssuedAt'),
    'Each receipt access should be recorded with its issuance timestamp.'
);
assert_customer_receipt(
    str_contains($receiptContents, 'Later accesses are marked as duplicate copies.')
        && str_contains($receiptContents, 'cannot prevent printing a saved copy again.'),
    'The receipt should clarify duplicate handling and the limit of print tracking.'
);

echo "Customer receipt tests passed.\n";
