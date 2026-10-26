<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/payments.php';
require_user();

$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$requestedType = $_GET['type'] ?? $_POST['type'] ?? '';
$type = in_array($requestedType, ['deposit', 'balance'], true) ? $requestedType : 'balance';

$stmt = $pdo->prepare(
    "SELECT o.*, p.name AS product_name FROM orders o JOIN products p ON p.id = o.product_id
     WHERE o.id = ? AND o.user_id = ?"
);
$stmt->execute([$orderId, $_SESSION['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    set_flash('error', 'Order not found.');
    redirect('/user/orders/index.php');
}

if (!in_array($order['status'], ['request_approved', 'pending_deposit', 'deposit_paid', 'processing', 'procuring', 'shipped', 'delivered'], true)) {
    set_flash('error', 'Payment is available only after an administrator approves your order request.');
    redirect('/user/orders/view.php?id=' . $orderId);
}

$paymentOptions = getOrderPaymentOptions($pdo, $orderId);
if ($paymentOptions['balance'] <= 0) {
    set_flash('info', 'This order has already been paid in full.');
    redirect('/user/orders/view.php?id=' . $orderId);
}
if ($order['status'] === 'request_approved' && $type === 'deposit' && !$paymentOptions['requires_deposit']) {
    set_flash('error', 'This order requires full payment.');
    redirect('/user/orders/view.php?id=' . $orderId);
}

if ($type === 'deposit' && !in_array($order['status'], ['request_approved', 'pending_deposit'], true)) {
    set_flash('error', 'A deposit is not available for this order at its current stage.');
    redirect('/user/orders/view.php?id=' . $orderId);
}

$isInitialPayment = in_array($order['status'], ['request_approved', 'pending_deposit'], true);
if ($type === 'balance' && !$isInitialPayment && $order['status'] !== 'delivered') {
    set_flash('error', 'The final balance can be paid after the order is delivered.');
    redirect('/user/orders/view.php?id=' . $orderId);
}
$pendingPaymentStmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE order_id = ? AND payment_type = ? AND status = 'pending'");
$pendingPaymentStmt->execute([$orderId, $type]);
if ((int) $pendingPaymentStmt->fetchColumn() > 0) {
    set_flash('error', 'A payment of this type is already awaiting admin verification.');
    redirect('/user/orders/view.php?id=' . $orderId);
}

$expectedBalance = $paymentOptions['balance'];
if ($type === 'balance' && $expectedBalance <= 0) {
    set_flash('error', 'This order has no outstanding balance.');
    redirect('/user/orders/view.php?id=' . $orderId);
}

$suggestedAmount = $type === 'deposit'
    ? $paymentOptions['deposit']
    : ($isInitialPayment ? $paymentOptions['total'] : $expectedBalance);
$paymentLabel = $type === 'deposit' ? 'deposit' : ($isInitialPayment ? 'full' : 'balance');
$pageTitle = 'Submit Payment';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/user/payments/index.php');
    $amount = (float) ($_POST['amount'] ?? 0);
    $method = trim($_POST['method'] ?? '');
    $referenceNote = trim($_POST['reference_note'] ?? '');

    $expectedAmount = $type === 'deposit'
        ? $paymentOptions['deposit']
        : ($isInitialPayment ? $paymentOptions['total'] : $expectedBalance);

    if ($amount !== (float) $expectedAmount) $errors[] = 'The payment amount must be ' . money($expectedAmount) . '.';
    if ($method === '') $errors[] = 'Please select a payment method.';

    $proofImage = null;
    if (!$errors) {
        try {
            $proofImage = handle_upload($_FILES['proof'] ?? [], 'payments');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        $paymentId = null;
        try {
            $pdo->beginTransaction();
            $lockOrder = $pdo->prepare('SELECT status FROM orders WHERE id = ? AND user_id = ? FOR UPDATE');
            $lockOrder->execute([$orderId, $_SESSION['user_id']]);
            $lockedOrder = $lockOrder->fetch();
            if (!$lockedOrder) {
                throw new RuntimeException('The order is no longer available for payment.');
            }

            $lockedOptions = getOrderPaymentOptions($pdo, $orderId);
            $lockedInitialPayment = in_array($lockedOrder['status'], ['request_approved', 'pending_deposit'], true);
            $lockedExpectedAmount = $type === 'deposit'
                ? $lockedOptions['deposit']
                : ($lockedInitialPayment ? $lockedOptions['total'] : $lockedOptions['balance']);
            $lockedPending = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE order_id = ? AND payment_type = ? AND status = 'pending'");
            $lockedPending->execute([$orderId, $type]);

            if (!in_array($lockedOrder['status'], ['request_approved', 'pending_deposit', 'deposit_paid', 'processing', 'procuring', 'shipped', 'delivered'], true)) {
                throw new RuntimeException('Payment is not available at this order stage.');
            }
            if ($lockedOptions['balance'] <= 0) {
                throw new RuntimeException('This order has already been paid in full.');
            }
            if ($type === 'deposit' && (!$lockedOptions['requires_deposit'] || !$lockedInitialPayment)) {
                throw new RuntimeException('A deposit is not available for this order at its current stage.');
            }
            if ($type === 'balance' && !$lockedInitialPayment && $lockedOrder['status'] !== 'delivered') {
                throw new RuntimeException('The final balance can be paid after the order is delivered.');
            }
            if ($type === 'balance' && $lockedExpectedAmount <= 0) {
                throw new RuntimeException('This order has no outstanding balance.');
            }
            if ((int) $lockedPending->fetchColumn() > 0) {
                throw new RuntimeException('A payment of this type is already awaiting admin verification.');
            }
            if ($amount !== (float) $lockedExpectedAmount) {
                throw new RuntimeException('The payment amount has changed. Refresh the page and submit the current amount.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO payments (order_id, user_id, amount, payment_type, method, reference_note, proof_image)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$orderId, $_SESSION['user_id'], $amount, $type, $method, $referenceNote, $proofImage]);
            $paymentId = (int) $pdo->lastInsertId();
            log_activity($pdo, $_SESSION['user_id'], 'payment_submitted', "Submitted $type payment for order #$orderId");
            record_order_event(
                $pdo,
                $orderId,
                $_SESSION['user_id'],
                'payment_submitted',
                ucfirst($type) . ' payment submitted',
                money($amount) . ' payment submitted and awaiting verification.'
            );
            $pdo->commit();
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($proofImage !== null) {
                $proofPath = resolve_payment_proof_path($proofImage);
                if ($proofPath !== null) {
                    @unlink($proofPath);
                }
            }
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($proofImage !== null) {
                $proofPath = resolve_payment_proof_path($proofImage);
                if ($proofPath !== null) {
                    @unlink($proofPath);
                }
            }
            error_log('Payment submission failed for order #' . $orderId . ': ' . $e->getMessage());
            $errors[] = 'Payment could not be submitted. Please try again.';
        }

        if ($paymentId !== null) {
            foreach (staff_user_ids($pdo) as $staffUserId) {
                create_notification(
                    $pdo,
                    (int) $staffUserId,
                    'Incoming payment',
                    "A new {$type} payment of " . money($amount) . " for order #{$orderId} is awaiting verification.",
                    '/admin/payments/view.php?id=' . $paymentId
                );
            }

            set_flash('success', 'Payment submitted. It will be verified shortly.');

            if ((($_POST['ajax'] ?? '') === '1') || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'redirect' => url('/user/orders/view.php?id=' . $orderId), 'message' => 'Payment submitted. It will be verified shortly.']);
                exit;
            }
            redirect('/user/orders/view.php?id=' . $orderId);
        }
    }
}

include __DIR__ . '/../../includes/user_layout_start.php';
?>

<div class="account-shell">
    <div class="account-summary">
        <div class="account-summary-main">
            <div class="account-summary-meta">Secure payment</div>
            <div>
                <div class="section-kicker">Payment step</div>
                <h2>Submit <?= e($paymentLabel) ?> payment</h2>
                <p>Complete your transaction in a secure, traceable process.</p>
            </div>
        </div>
        <span class="badge badge-neutral">Order #<?= (int) $order['id'] ?></span>
    </div>

    <div class="detail-progress-strip" aria-label="Payment workflow">
        <span class="detail-progress-item active"><strong>01</strong> Submit</span>
        <span class="detail-progress-item"><strong>02</strong> Verify</span>
        <span class="detail-progress-item"><strong>03</strong> Fulfillment</span>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>Payment details</h2>
        </div>
        <div class="panel-body">
            <div class="payment-submit-layout">
                <div class="payment-summary-stack">
                    <div class="payment-summary-card">
                        <div class="label">Order</div>
                        <h3><?= e($order['product_name']) ?></h3>
                        <p>#<?= (int) $order['id'] ?> · <?= e($paymentLabel) ?> payment</p>
                    </div>
                    <div class="payment-summary-card payment-amount-card">
                        <div class="label">Expected amount</div>
                        <div class="payment-amount"><?= money($suggestedAmount) ?></div>
                        <small>Due for verification before procurement can continue.</small>
                    </div>
                    <div class="payment-summary-card">
                        <div class="label">What happens next</div>
                        <ul class="payment-checklist">
                            <li>Submit your payment details and proof.</li>
                            <li>Our team verifies the reference and amount.</li>
                            <li>Fulfillment continues once cleared.</li>
                        </ul>
                    </div>
                </div>

                <div class="payment-form-panel">
                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-error" style="margin-top: 0; margin-bottom: 18px;"><?= e($error) ?></div>
                    <?php endforeach; ?>

                    <form method="post" enctype="multipart/form-data" class="payment-form" data-ajax-submit="true">
                        <?= csrf_field() ?>
                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                        <input type="hidden" name="type" value="<?= e($type) ?>">
                        <div class="field">
                            <label for="amount">Amount paid</label>
                            <input type="number" id="amount" name="amount" value="<?= e($suggestedAmount) ?>" min="1" step="1" required>
                        </div>
                        <div class="field">
                            <label for="method">Payment method</label>
                            <select id="method" name="method" required>
                                <option value="">Select a method</option>
                                <option value="Mobile Money">Mobile Money</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cash">Cash</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="reference_note">Reference note</label>
                            <input type="text" id="reference_note" name="reference_note" placeholder="Transaction ID or reference">
                        </div>
                        <div class="field">
                            <label for="proof">Proof of payment</label>
                            <input type="file" id="proof" name="proof" accept=".jpg,.jpeg,.png,.webp">
                            <div class="hint">Upload a screenshot or photo of your payment confirmation.</div>
                        </div>
                        <div class="table-actions">
                            <button type="submit" class="btn btn-accent">Submit payment</button>
                            <a href="<?= url('/user/orders/view.php?id=' . $order['id']) ?>" class="btn btn-outline">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/user_layout_end.php'; ?>