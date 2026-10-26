<?php
/**
 * Cart mutations for the point-of-sale terminal.
 *
 * Every change comes back as either a redirect (plain form post, no JS) or a
 * JSON payload carrying the freshly rendered cart panel (the till fetches it),
 * so both paths run exactly the same server-side logic.
 */
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$isJson = ($_GET['format'] ?? '') === 'json' || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if ($csrf === '' || ($_SESSION['csrf_token'] ?? '') === '' || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'The security token is invalid. Refresh the till and try again.']);
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$error = '';

// Contact details ride along with *any* cart action, not just their own. The
// fields live inside the panel that every cart change re-renders, so an operator
// who types a name and then taps "+1" would otherwise lose it silently.
$contactPosted =
    array_key_exists('contact_name', $_POST)
    || array_key_exists('contact_phone', $_POST)
    || array_key_exists('contact_email', $_POST);

if ($contactPosted) {
    // Validated one field at a time: a mistyped email must not cost the operator
    // the name and phone they got right.
    $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address does not look right — it was not saved.';
    } else {
        pos_cart_set('contact_email', mb_substr($contactEmail, 0, 150));
    }
    pos_cart_set('contact_name', mb_substr(trim((string) ($_POST['contact_name'] ?? '')), 0, 120));
    pos_cart_set('contact_phone', mb_substr(trim((string) ($_POST['contact_phone'] ?? '')), 0, 30));
}

try {
    switch ($action) {
        case 'add':
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId <= 0 && trim((string) ($_POST['code'] ?? '')) !== '') {
                // A barcode scan posts the raw code; resolve it here so the
                // scanner works with the plain form (no JavaScript needed).
                $found = pos_find_product($pdo, (string) $_POST['code']);
                if ($found) {
                    $productId = (int) $found['id'];
                }
            }
            $added = pos_cart_add($pdo, $productId, (int) ($_POST['quantity'] ?? 1));
            if ($added[0] === null) {
                $error = 'No product matches that code.';
            } elseif ($added[1] < 1) {
                $error = 'No stock of ' . $added[0]['name'] . ' is available.';
            }
            break;

        case 'update':
            pos_cart_update($pdo, (int) ($_POST['product_id'] ?? 0), (int) ($_POST['quantity'] ?? 0));
            break;

        case 'remove':
            pos_cart_remove((int) ($_POST['product_id'] ?? 0));
            break;

        case 'clear':
            pos_cart_clear();
            break;

        case 'set_discount':
            pos_cart_set('discount', max(0.0, (float) ($_POST['discount'] ?? 0)));
            break;

        case 'set_method':
            $method = (string) ($_POST['method'] ?? 'cash');
            if (array_key_exists($method, pos_payment_methods())) {
                pos_cart_set('method', $method);
            }
            break;

        case 'set_note':
            pos_cart_set('note', mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500));
            break;

        case 'set_tendered':
            pos_cart_set('tendered', max(0.0, (float) ($_POST['tendered'] ?? 0)));
            break;

        case 'set_reference':
            pos_cart_set('reference', mb_substr(trim((string) ($_POST['reference'] ?? '')), 0, 100));
            break;

        case 'set_contact':
            // Already applied above from the posted fields; kept as an action so
            // the form's Save button has something to post.
            break;

        case 'set_customer':
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            if ($customerId > 0) {
                $stmt = $pdo->prepare("SELECT id, full_name, email, phone FROM users WHERE id = ? AND role = 'user' AND status = 'active' AND deleted_at IS NULL");
                $stmt->execute([$customerId]);
                $chosen = $stmt->fetch();
                if (!$chosen) {
                    $error = 'That customer could not be found.';
                    break;
                }
                pos_cart_set('customer_id', $customerId);
                // Pre-fill from the account so staff can see the details without
                // retyping them; they stay editable for this sale only. Anything
                // the form posted wins, so picking a customer never discards
                // corrections already typed in.
                if (trim((string) ($_POST['contact_name'] ?? '')) === '') {
                    pos_cart_set('contact_name', (string) $chosen['full_name']);
                }
                if (trim((string) ($_POST['contact_phone'] ?? '')) === '') {
                    pos_cart_set('contact_phone', (string) ($chosen['phone'] ?? ''));
                }
                if (trim((string) ($_POST['contact_email'] ?? '')) === '') {
                    pos_cart_set('contact_email', (string) ($chosen['email'] ?? ''));
                }
            } else {
                pos_cart_set('customer_id', 0);
            }
            break;

        default:
            $error = 'Unknown cart action.';
            break;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$state = pos_cart_state($pdo);

// Rendering the panel is done by the same partial the terminal uses, so the
// HTML the client swaps in can never drift from what a full page load shows.
$posState = $state;
$posPanelHtml = '';
ob_start();
include __DIR__ . '/../../includes/partials/pos_cart.php';
$posPanelHtml = ob_get_clean();

if ($error !== '' && !$isJson) {
    set_flash('error', $error);
} elseif ($error === '' && !$isJson) {
    set_flash('success', 'Cart updated.');
}

echo json_encode([
    'success' => $error === '',
    'message' => $error !== '' ? $error : 'Cart updated.',
    'count' => $state['item_count'],
    'total' => round($state['total'], 2),
    'cart_html' => $posPanelHtml,
]);
