<?php

/** Write an entry to the audit log. */
function log_activity(PDO $pdo, ?int $userId, string $action, string $description = ''): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function staff_user_ids(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id
         FROM users
         WHERE role IN ('staff', 'admin', 'super_admin')
           AND status = 'active'
           AND deleted_at IS NULL"
    );

    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return array_map('intval', $ids);
}

function notify_staff(PDO $pdo, string $title, string $message, ?string $link = null): void
{
    foreach (staff_user_ids($pdo) as $userId) {
        create_notification($pdo, $userId, $title, $message, $link);
    }
}

function create_notification(PDO $pdo, int $userId, string $title, string $message, ?string $link = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $title, $message, $link]);
}

function record_order_event(PDO $pdo, int $orderId, ?int $userId, string $eventType, string $title, ?string $details = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO order_events (order_id, user_id, event_type, title, details) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$orderId, $userId, $eventType, $title, $details]);
}
