<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_supplier();

$pageTitle = 'My Profile';

// Resolve the supplier record for the current user without assuming a
// users.supplier_id column exists; the actual schema links them by email.
$supplier = get_supplier_for_user((int) $_SESSION['user_id']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/supplier/profile/index.php');

    $name = trim($_POST['name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Lengths match the suppliers table so an over-long value cannot blow the
    // UPDATE up on a strict-mode MySQL server.
    if ($name === '') {
        $errors[] = 'Company name is required.';
    }
    if (mb_strlen($name) > 150) {
        $errors[] = 'Company name must be 150 characters or fewer.';
    }
    if (mb_strlen($contactPerson) > 120) {
        $errors[] = 'Contact person must be 120 characters or fewer.';
    }
    if (mb_strlen($phone) > 30) {
        $errors[] = 'Phone number must be 30 characters or fewer.';
    }
    if (mb_strlen($address) > 255) {
        $errors[] = 'Address must be 255 characters or fewer.';
    }
    if (mb_strlen($notes) > 500) {
        $errors[] = 'Notes must be 500 characters or fewer.';
    }

    // Status stays under administrator control: purchase order creation only
    // lists suppliers with status 'active' (admin/purchase_orders/add.php), so a
    // supplier deactivating itself would silently vanish from sourcing.
    if (!$errors && $supplier) {
        $stmt = $pdo->prepare(
            'UPDATE suppliers SET name = ?, contact_person = ?, phone = ?, address = ?, notes = ? WHERE id = ?'
        );
        $stmt->execute([$name, $contactPerson, $phone, $address, $notes, $supplier['id']]);
        log_activity($pdo, $_SESSION['user_id'], 'supplier_profile_updated', 'Updated supplier profile');
        set_flash('success', 'Profile updated successfully.');
        redirect('/supplier/profile/index.php');
    }
}

include __DIR__ . '/../../includes/supplier_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>My Profile</h2>
    </div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <?php if (!$supplier): ?>
            <div class="alert alert-warning">No supplier profile found. Please contact the admin to create one.</div>
        <?php else: ?>
            <div class="detail-grid" style="margin-bottom: 20px;">
                <div class="detail-item">
                    <div class="label">Company email</div>
                    <div class="value">
                        <?= e($supplier['email'] ?: '—') ?>
                        <div class="muted" style="font-size: 0.85rem;">Your portal account is linked to this address — an administrator has to change it.</div>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="label">Status</div>
                    <div class="value">
                        <span class="badge badge-<?= $supplier['status'] === 'active' ? 'success' : 'neutral' ?>"><?= ucfirst(e($supplier['status'])) ?></span>
                        <div class="muted" style="font-size: 0.85rem;">Managed by an administrator.</div>
                    </div>
                </div>
            </div>
            <form method="post" style="max-width: 600px;">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="name">Company Name *</label>
                    <input type="text" id="name" name="name" maxlength="150" value="<?= e($supplier['name']) ?>" required>
                </div>
                <div class="field">
                    <label for="contact_person">Contact Person</label>
                    <input type="text" id="contact_person" name="contact_person" maxlength="120" value="<?= e($supplier['contact_person']) ?>">
                </div>
                <div class="field">
                    <label for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone" maxlength="30" value="<?= e($supplier['phone']) ?>">
                </div>
                <div class="field">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" rows="2" maxlength="255"><?= e($supplier['address']) ?></textarea>
                </div>
                <div class="field">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="500" placeholder="Additional notes about your company..."><?= e($supplier['notes']) ?></textarea>
                </div>
                <div class="table-actions">
                    <button type="submit" class="btn btn-accent">Save Changes</button>
                    <a href="<?= url('/supplier/dashboard.php') ?>" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/supplier_layout_end.php'; ?>