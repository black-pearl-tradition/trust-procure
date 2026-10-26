<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT pay.*, o.id AS order_id, o.status AS order_status, p.name AS product_name FROM payments pay
     JOIN orders o ON o.id = pay.order_id
     JOIN products p ON p.id = o.product_id
     WHERE pay.id = ? AND pay.user_id = ? AND pay.status = 'rejected'"
);
$stmt->execute([$id, $_SESSION['user_id']]);
$payment = $stmt->fetch();

if (!$payment) {
    set_flash('error', 'Only a rejected payment can be resubmitted.');
    redirect('/user/payments/index.php');
}

$pageTitle = 'Re-upload proof — Payment #' . $payment['id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/user/payments/view.php?id=' . $id);
    $method = trim($_POST['method'] ?? '');
    $referenceNote = trim($_POST['reference_note'] ?? '');

    if ($method === '') $errors[] = 'Please select a payment method.';

    $newProof = null;
    try {
        $newProof = handle_upload($_FILES['proof'] ?? [], 'payments');
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
    if ($newProof === null) $errors[] = 'Please choose a clear JPG, PNG, or WebP image of your payment confirmation.';

    if (!$errors) {
        $oldProof = $payment['proof_image'];
        try {
            $pdo->prepare(
                "UPDATE payments
                 SET method = ?, reference_note = ?, proof_image = ?,
                     status = 'pending', rejection_reason = NULL,
                     verified_by = NULL, verified_at = NULL
                 WHERE id = ? AND user_id = ? AND status = 'rejected'"
            )->execute([$method, $referenceNote ?: null, $newProof, $id, $_SESSION['user_id']]);
        } catch (Throwable $e) {
            $newPath = resolve_payment_proof_path($newProof);
            if ($newPath !== null) @unlink($newPath);
            $errors[] = 'Your proof could not be resubmitted. Please try again.';
        }

        if (!$errors) {
            if ($oldProof) {
                $oldPath = resolve_payment_proof_path($oldProof);
                if ($oldPath !== null) @unlink($oldPath);
            }
            log_activity($pdo, $_SESSION['user_id'], 'payment_resubmitted', "Payment #$id proof re-uploaded");
            record_order_event($pdo, (int) $payment['order_id'], $_SESSION['user_id'], 'payment_resubmitted', 'Payment proof re-uploaded', 'The customer uploaded a clearer proof of payment for review.');
            create_notification($pdo, (int) $payment['user_id'], 'Proof re-uploaded', "Your clearer proof for payment #{$id} (order #{$payment['order_id']}) was received and is awaiting review.", '/user/payments/view.php?id=' . $id);
            $staffStmt = $pdo->query("SELECT id FROM users WHERE role IN ('staff', 'admin', 'super_admin') AND status = 'active' AND deleted_at IS NULL");
            foreach ($staffStmt->fetchAll(PDO::FETCH_COLUMN) as $staffId) {
                create_notification($pdo, (int) $staffId, 'Corrected payment proof submitted', "Payment #{$id} (order #{$payment['order_id']}) has a new proof awaiting verification.", '/admin/payments/view.php?id=' . $id);
            }
            set_flash('success', 'Your clearer proof was uploaded and is awaiting review.');
            redirect('/user/payments/view.php?id=' . $id);
        }
    }
}

include __DIR__ . '/../../includes/user_layout_start.php';
?>

<div class="account-shell">
    <div class="account-summary">
        <div class="account-summary-main">
            <div class="account-summary-meta">Correct your payment</div>
            <div>
                <div class="section-kicker">Re-upload proof</div>
                <h2>Payment #<?= (int) $payment['id'] ?></h2>
                <p><?= e($payment['product_name']) ?> · <?= ucfirst(e($payment['payment_type'])) ?> · <?= money($payment['amount']) ?></p>
            </div>
        </div>
        <span class="badge badge-danger">Rejected</span>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>Why was it rejected?</h2>
        </div>
        <div class="panel-body">
            <div class="alert alert-error" style="margin-top: 0;">
                <strong>Admin reason:</strong> <?= e($payment['rejection_reason'] ?: 'The proof was unclear or incorrect.') ?>
            </div>
            <p class="muted">Upload a clearer photo showing the amount, date, and reference. Your payment stays on the same record — the admin will review the new proof.</p>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endforeach; ?>

            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $payment['id'] ?>">
                <div class="field">
                    <label for="method">Payment method</label>
                    <select id="method" name="method" required>
                        <option value="">Select a method</option>
                        <?php foreach (['Mobile Money', 'Bank Transfer', 'Cash'] as $option): ?>
                            <option value="<?= e($option) ?>" <?= ($payment['method'] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="reference_note">Reference note</label>
                    <input type="text" id="reference_note" name="reference_note" value="<?= e($payment['reference_note'] ?? '') ?>" placeholder="Transaction ID or reference">
                </div>
                <div class="field">
                    <label for="proof">New proof of payment</label>
                    <input type="file" id="proof" name="proof" accept=".jpg,.jpeg,.png,.webp" required>
                    <div class="hint">JPG, PNG, or WebP, 5 MB or smaller. Make sure amount and reference are readable.</div>
                </div>
                <div class="table-actions">
                    <button type="submit" class="btn btn-accent">Submit clearer proof</button>
                    <a href="<?= url('/user/payments/view.php?id=' . $payment['id']) ?>" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/user_layout_end.php'; ?>

