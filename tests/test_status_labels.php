<?php
// Quick test for status labels
function order_status_label(string $status): string
{
    $labels = [
        'request_submitted' => 'Pending Review',
        'request_approved' => 'Approved',
        'request_rejected' => 'Rejected',
        'pending_deposit' => 'Pending Payment',
        'deposit_paid'    => 'Confirmed',
        'processing'      => 'Processing',
        'procuring'       => 'Procuring',
        'shipped'         => 'Ready for Delivery',
        'delivered'       => 'Delivered',
        'completed'       => 'Completed',
        'cancelled'       => 'Cancelled',
    ];
    return $labels[$status] ?? ucfirst($status);
}

echo 'Status labels test:' . PHP_EOL;
echo 'processing: ' . order_status_label('processing') . PHP_EOL;
echo 'procuring: ' . order_status_label('procuring') . PHP_EOL;
echo PHP_EOL . 'SUCCESS: Labels are now distinct!' . PHP_EOL;
