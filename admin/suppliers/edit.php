<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
$stmt->execute([$id]);
$supplier = $stmt->fetch();

if (!$supplier) {
    set_flash('error', 'Supplier not found.');
    redirect('/admin/suppliers/index.php');
}

$pageTitle = 'Edit Supplier';
$errors = [];
$name = $supplier['name'];
$contactPerson = $supplier['contact_person'];
$phone = $supplier['phone'];
$email = $supplier['email'];
$address = $supplier['address'];
$notes = $supplier['notes'];
$status = $supplier['status'];

$paymentTerms = $supplier['payment_terms'] ?? 'net_30';
$advancePercentage = (int) ($supplier['advance_percentage'] ?? 0);
$bankName = $supplier['bank_name'] ?? '';
$bankAccountName = $supplier['bank_account_name'] ?? '';
$bankAccountNumber = $supplier['bank_account_number'] ?? '';
$bankBranch = $supplier['bank_branch'] ?? '';
$mobileMoneyProvider = $supplier['mobile_money_provider'] ?? '';
$mobileMoneyNumber = $supplier['mobile_money_number'] ?? '';
$taxId = $supplier['tax_identification_number'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/admin/suppliers/index.php');

    $name = trim($_POST['name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $status = $_POST['status'] ?? 'active';

    $paymentTerms = $_POST['payment_terms'] ?? 'net_30';
    $advancePercentage = max(0, min(100, (int) ($_POST['advance_percentage'] ?? 0)));
    $bankName = trim($_POST['bank_name'] ?? '');
    $bankAccountName = trim($_POST['bank_account_name'] ?? '');
    $bankAccountNumber = trim($_POST['bank_account_number'] ?? '');
    $bankBranch = trim($_POST['bank_branch'] ?? '');
    $mobileMoneyProvider = trim($_POST['mobile_money_provider'] ?? '');
    $mobileMoneyNumber = trim($_POST['mobile_money_number'] ?? '');
    $taxId = trim($_POST['tax_identification_number'] ?? '');

    if ($name === '') {
        $errors[] = 'Please enter a supplier name.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=?, notes=?, status=?,
                                  payment_terms=?, advance_percentage=?, bank_name=?, bank_account_name=?,
                                  bank_account_number=?, bank_branch=?, mobile_money_provider=?,
                                  mobile_money_number=?, tax_identification_number=?
             WHERE id=?'
        );
        $stmt->execute([
            $name, $contactPerson ?: null, $phone ?: null, $email ?: null, $address ?: null, $notes ?: null, $status,
            $paymentTerms, $advancePercentage, $bankName ?: null, $bankAccountName ?: null,
            $bankAccountNumber ?: null, $bankBranch ?: null, $mobileMoneyProvider ?: null,
            $mobileMoneyNumber ?: null, $taxId ?: null, $id
        ]);
        log_activity($pdo, $_SESSION['user_id'], 'supplier_updated', "Updated supplier #$id");
        set_flash('success', 'Supplier updated.');
        redirect('/admin/suppliers/index.php');
    }
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head"><h2>Edit supplier</h2></div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <form method="post" style="max-width: 560px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $supplier['id'] ?>">
            <div class="field-row">
                <div class="field">
                    <label for="name">Supplier name</label>
                    <input type="text" id="name" name="name" value="<?= e($name) ?>" required autofocus>
                </div>
                <div class="field">
                    <label for="contact_person">Contact person</label>
                    <input type="text" id="contact_person" name="contact_person" value="<?= e($contactPerson) ?>">
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone" value="<?= e($phone) ?>">
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($email) ?>">
                </div>
            </div>
            <div class="field">
                <label for="address">Address</label>
                <input type="text" id="address" name="address" value="<?= e($address) ?>">
            </div>
            <div class="field">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes"><?= e($notes) ?></textarea>
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            <div style="margin: 24px 0 12px; padding-bottom: 6px; border-bottom: 1px solid var(--border); font-weight: 600;">
                Payment Terms & Financial Details
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="payment_terms">Payment terms</label>
                    <select id="payment_terms" name="payment_terms">
                        <option value="net_30" <?= ($paymentTerms ?? 'net_30') === 'net_30' ? 'selected' : '' ?>>Net 30 Days</option>
                        <option value="net_15" <?= ($paymentTerms ?? '') === 'net_15' ? 'selected' : '' ?>>Net 15 Days</option>
                        <option value="net_60" <?= ($paymentTerms ?? '') === 'net_60' ? 'selected' : '' ?>>Net 60 Days</option>
                        <option value="cod" <?= ($paymentTerms ?? '') === 'cod' ? 'selected' : '' ?>>Payment on Delivery (COD)</option>
                        <option value="split_advance" <?= ($paymentTerms ?? '') === 'split_advance' ? 'selected' : '' ?>>Split Advance</option>
                        <option value="prepaid" <?= ($paymentTerms ?? '') === 'prepaid' ? 'selected' : '' ?>>100% Prepayment</option>
                    </select>
                </div>
                <div class="field" id="advance_pct_wrapper">
                    <label for="advance_percentage">Advance percentage (%)</label>
                    <input type="number" id="advance_percentage" name="advance_percentage" min="0" max="100" value="<?= (int) ($advancePercentage ?? 0) ?>">
                    <div class="hint">Applies when terms are Split Advance.</div>
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="bank_name">Bank name</label>
                    <input type="text" id="bank_name" name="bank_name" value="<?= e($bankName ?? '') ?>" placeholder="e.g. Stanbic Bank Uganda">
                </div>
                <div class="field">
                    <label for="bank_account_name">Bank account name</label>
                    <input type="text" id="bank_account_name" name="bank_account_name" value="<?= e($bankAccountName ?? '') ?>" placeholder="e.g. ABC Hardware Ltd">
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="bank_account_number">Bank account number</label>
                    <input type="text" id="bank_account_number" name="bank_account_number" value="<?= e($bankAccountNumber ?? '') ?>">
                </div>
                <div class="field">
                    <label for="bank_branch">Branch / Sort code</label>
                    <input type="text" id="bank_branch" name="bank_branch" value="<?= e($bankBranch ?? '') ?>">
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="mobile_money_provider">Mobile money provider</label>
                    <input type="text" id="mobile_money_provider" name="mobile_money_provider" value="<?= e($mobileMoneyProvider ?? '') ?>" placeholder="e.g. MTN MoMo, Airtel Money">
                </div>
                <div class="field">
                    <label for="mobile_money_number">Mobile money number</label>
                    <input type="text" id="mobile_money_number" name="mobile_money_number" value="<?= e($mobileMoneyNumber ?? '') ?>" placeholder="e.g. +256 770 000000">
                </div>
            </div>
            <div class="field">
                <label for="tax_identification_number">Tax ID / TIN</label>
                <input type="text" id="tax_identification_number" name="tax_identification_number" value="<?= e($taxId ?? '') ?>" placeholder="e.g. 1000123456">
            </div>

            </div>
            <div class="table-actions">
                <button type="submit" class="btn btn-accent">Save changes</button>
                <a href="<?= url('/admin/suppliers/view.php?id=' . $supplier['id']) ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
