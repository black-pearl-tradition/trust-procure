<?php

/** Human-readable label for an order status. */
function order_status_label(string $status): string
{
    // Values match the requested Purchase Order status vocabulary
    // (Pending / Confirmed / Processing / Ready for Delivery / Delivered /
    // Cancelled). The underlying enum values are unchanged from the
    // original schema to avoid breaking existing data — only the label
    // shown to people has been updated. "Completed" is an extra state we
    // already had for "balance fully settled" and isn't part of that list,
    // but is kept since removing it would lose information on real orders.
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

/** Badge colour tone for an order status. */
function order_status_badge_class(string $status): string
{
    $classes = [
        'request_submitted' => 'warning',
        'request_approved'  => 'info',
        'request_rejected'  => 'danger',
        'pending_deposit'   => 'info',
        'deposit_paid'      => 'info',
        'processing'        => 'info',
        'procuring'         => 'info',
        'shipped'           => 'info',
        'delivered'         => 'success',
        'completed'         => 'success',
        'cancelled'         => 'danger',
    ];
    return $classes[$status] ?? 'neutral';
}

/** Human-readable label for a request status. */
function request_status_label(string $status): string
{
    $labels = [
        'pending'   => 'Pending Review',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
        'converted' => 'Converted to Order',
        'cancelled' => 'Cancelled',
    ];
    return $labels[$status] ?? ucfirst($status);
}

/** Human-readable label for a payment status. */
function payment_status_label(string $status): string
{
    $labels = [
        'pending'  => 'Pending Verification',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
    ];
    return $labels[$status] ?? ucfirst($status);
}

/** Human-readable label for the kind of payment taken. */
function payment_type_label(string $type): string
{
    $labels = [
        'deposit' => 'Deposit',
        'balance' => 'Balance payment',
        'pos'     => 'Till sale',
    ];
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

/** Human-readable label for a purchase order status. */
function po_status_label(string $status): string
{
    $labels = [
        'draft'               => 'Draft',
        'submitted'           => 'Submitted for Approval',
        'approved'            => 'Approved',
        'rejected'            => 'Rejected',
        'sent'                => 'Sent to Supplier',
        'partially_received'  => 'Partially Received',
        'received'            => 'Received',
        'cancelled'           => 'Cancelled',
    ];
    return $labels[$status] ?? ucfirst($status);
}

/** Badge colour tone for a purchase order status. */
function po_status_badge_class(string $status): string
{
    $classes = [
        'draft'              => 'neutral',
        'submitted'          => 'warning',
        'approved'           => 'info',
        'rejected'           => 'danger',
        'sent'               => 'info',
        'partially_received' => 'warning',
        'received'           => 'success',
        'cancelled'          => 'danger',
    ];
    return $classes[$status] ?? 'neutral';
}

/** Human-readable label for the supplier response status on a sent purchase order. */
function supplier_response_label(string $status): string
{
    $labels = [
        'pending' => 'Awaiting Supplier Response',
        'acknowledged' => 'Acknowledged',
        'declined' => 'Declined',
    ];
    return $labels[$status] ?? ucfirst($status);
}

/** Badge colour tone for the supplier response status. */
function supplier_response_badge_class(string $status): string
{
    $classes = [
        'pending' => 'warning',
        'acknowledged' => 'success',
        'declined' => 'danger',
    ];
    return $classes[$status] ?? 'neutral';
}

/** Human-readable label for supplier payment terms */
function supplier_payment_terms_label(string $terms, int $advancePct = 0): string
{
    $labels = [
        'cod'           => 'Payment on Delivery (COD)',
        'net_15'        => 'Net 15 Days',
        'net_30'        => 'Net 30 Days',
        'net_60'        => 'Net 60 Days',
        'split_advance' => $advancePct > 0 ? "Split ($advancePct% Advance, " . (100 - $advancePct) . "% on Delivery)" : 'Split Advance',
        'prepaid'       => '100% Prepayment',
    ];
    return $labels[$terms] ?? ucfirst($terms);
}

/** Human-readable label for purchase order payment status */
function po_payment_status_label(string $status): string
{
    $labels = [
        'unpaid'         => 'Unpaid',
        'partially_paid' => 'Partially Paid',
        'paid'           => 'Fully Paid',
    ];
    return $labels[$status] ?? ucfirst($status);
}

/** Badge class for PO payment status */
function po_payment_status_badge_class(string $status): string
{
    $classes = [
        'unpaid'         => 'danger',
        'partially_paid' => 'warning',
        'paid'           => 'success',
    ];
    return $classes[$status] ?? 'neutral';
}

/** Human-readable label for supplier invoice status */
function supplier_invoice_status_label(string $status): string
{
    $labels = [
        'pending'  => 'Under Review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'settled'  => 'Settled (legacy)',
    ];
    return $labels[$status] ?? ucfirst($status);
}

/** Badge class for supplier invoice status */
function supplier_invoice_status_badge_class(string $status): string
{
    $classes = [
        'pending'  => 'warning',
        'approved' => 'info',
        'rejected' => 'danger',
        'settled'  => 'success',
    ];
    return $classes[$status] ?? 'neutral';
}
