<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$afterId = filter_input(INPUT_GET, 'after', FILTER_VALIDATE_INT);
if ($afterId === false || $afterId === null || $afterId < 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid notification cursor.']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, title, message, link, is_read
     FROM notifications
     WHERE user_id = ? AND id > ?
     ORDER BY id ASC
     LIMIT 20'
);
$stmt->execute([$_SESSION['user_id'], $afterId]);
$rows = $stmt->fetchAll();

$items = [];
foreach ($rows as $row) {
    $type = admin_notification_type((string) $row['title']);
    $items[] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'message' => (string) $row['message'],
        'link' => $row['link'] === null ? null : (string) $row['link'],
        'is_read' => (int) $row['is_read'] === 1,
        'type' => $type,
    ];
}

echo json_encode([
    'success' => true,
    'next_cursor' => $rows ? (int) end($rows)['id'] : $afterId,
    'items' => $items,
]);
