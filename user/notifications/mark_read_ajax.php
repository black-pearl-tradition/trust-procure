<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if ($csrf === '' || ($_SESSION['csrf_token'] ?? '') === '' || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'The security token is invalid.']);
    exit;
}

$pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$_SESSION['user_id']]);
echo json_encode(['success' => true, 'unread' => 0]);