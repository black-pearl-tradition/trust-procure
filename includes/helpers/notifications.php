<?php

/**
 * Shared notification presentation helpers for the admin area.
 * Used by the notifications inbox page and the topbar bell dropdown so both
 * stay in sync. Keep this file free of output — functions only.
 */

/** Category of a notification: payments | orders | system. */
function admin_notification_category(array $notification): string
{
    $link = strtolower(trim((string) ($notification['link'] ?? '')));
    if (str_contains($link, 'payment')) {
        return 'payments';
    }
    if (str_contains($link, 'order')) {
        return 'orders';
    }
    $text = strtolower(($notification['title'] ?? '') . ' ' . ($notification['message'] ?? ''));
    if (str_contains($text, 'payment') || str_contains($text, 'proof') || str_contains($text, 'dues')) {
        return 'payments';
    }
    if (str_contains($text, 'order') || str_contains($text, 'request') || str_contains($text, 'procurement') || str_contains($text, 'stock') || str_contains($text, 'purchase order')) {
        return 'orders';
    }
    return 'system';
}

/** Severity tone of a notification: danger | success | info. */
function admin_notification_type(string $title): string
{
    $title = strtolower($title);
    if (str_contains($title, 'rejected') || str_contains($title, 'cancelled') || str_contains($title, 'failed')) {
        return 'danger';
    }
    if (str_contains($title, 'received') || str_contains($title, 'verified') || str_contains($title, 'approved') || str_contains($title, 'completed') || str_contains($title, 'cleared') || str_contains($title, 'submitted')) {
        return 'success';
    }
    return 'info';
}

/** [icon, category label] for a notification card/bell row. */
function admin_notification_meta(string $type, string $category): array
{
    $icons = ['danger' => 'exclamation-octagon', 'success' => 'check-circle', 'info' => 'info-circle'];
    $labels = ['payments' => 'Payment', 'orders' => 'Order', 'system' => 'System'];
    return [$icons[$type] ?? 'bell', $labels[$category] ?? 'Update'];
}
