<?php
require_once __DIR__ . '/includes/auth_check.php';
require_login();

$paymentId = (int) ($_GET['id'] ?? 0);
if ($paymentId <= 0) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare('SELECT user_id, proof_image FROM payments WHERE id = ?');
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment || !$payment['proof_image']) {
    http_response_code(404);
    exit;
}

if ((int) $payment['user_id'] !== (int) $_SESSION['user_id'] && !is_staff_or_above()) {
    http_response_code(404);
    exit;
}

$filename = basename((string) ($payment['proof_image'] ?? ''));
$filePath = $filename !== '' ? resolve_payment_proof_path($filename) : null;

if ($filePath === null) {
    error_log('payment-proof: file not found for payment #' . $paymentId . ' (stored name: ' . ($payment['proof_image'] ?? 'none') . ')');
    http_response_code(404);
    exit;
}

$mimeTypes = payment_proof_mime_types();
$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
if (!isset($mimeTypes[$extension])) {
    error_log('payment-proof: unsupported extension "' . $extension . '" for payment #' . $paymentId);
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . (string) filesize($filePath));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
