<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

header('Content-Type: application/json; charset=utf-8');

// Lightweight feed for the topbar bell dropdown: latest unread previews.
// Same helpers as the inbox page so classification stays in sync.
$stmt = $pdo->prepare('SELECT id, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
$stmt->execute([$_SESSION['user_id']]);
$rows = $stmt->fetchAll();

$unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadStmt->execute([$_SESSION['user_id']]);

$items = [];
foreach (array_slice($rows, 0, 7) as $row) {
    $category = admin_notification_category($row);
    $type = admin_notification_type($row['title'] ?? '');
    [$icon] = admin_notification_meta($type, $category);
    $items[] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'message' => (string) $row['message'],
        'category' => $category,
        'type' => $type,
        'icon' => $icon,
        'is_read' => (int) $row['is_read'] === 1,
        'created_at' => (string) $row['created_at'],
    ];
}

echo json_encode([
    'success' => true,
    'unread' => (int) $unreadStmt->fetchColumn(),
    'csrf' => csrf_token(),
    'items' => $items,
]);