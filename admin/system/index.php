<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_super_admin();

$pageTitle = 'System Data';

$softResetScope = [
    'Notifications and messages',
    'Audit logs and password reset tokens',
    'Inventory movements and purchase-order records',
    'Orders, payments, requests, and review activity',
    'Supplier links, suppliers, products, banners, and categories',
];

$fullResetScope = array_merge($softResetScope, [
    'All non-super-admin user accounts',
]);

$resetMode = $_GET['reset'] ?? '';
$resetConfirmed = ($resetMode === 'soft' || $resetMode === 'full') && ($_GET['step'] ?? '') === 'confirm';

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>Clear system data</h2>
    </div>
    <div class="panel-body">
        <div class="alert alert-warning" style="margin-bottom: 20px;">
            <strong>Soft reset:</strong> clears all live operational records while keeping the user accounts in place. This is the safer reset for a clean restart without removing the registered staff, suppliers, or customers.
        </div>

        <div class="alert alert-danger" style="margin-bottom: 18px;">
            <strong>Full reset:</strong> permanently removes product, supplier, order, payment, message, notification, and account data outside the protected super-administrator record. This is intended only for a complete rebuild or a deliberate reset of the whole platform.
        </div>

        <p>Only a Super Administrator can access this page. Use a soft reset to clear the platform data while preserving accounts; use a full reset only for a complete rebuild.</p>

        <div style="display: grid; gap: 18px; max-width: 760px;">
            <div class="panel panel-subtle" style="padding: 18px; border: 1px solid var(--border-color, #e5e7eb); border-radius: 12px; background: #fff;">
                <h3 style="margin-top: 0; margin-bottom: 12px;">What will be cleared</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px;">
                    <div>
                        <strong>Soft reset removes:</strong>
                        <ul style="margin: 10px 0 0 18px; padding: 0;">
                            <?php foreach ($softResetScope as $item): ?>
                                <li><?= e($item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div>
                        <strong>Full reset also removes:</strong>
                        <ul style="margin: 10px 0 0 18px; padding: 0;">
                            <?php foreach ($fullResetScope as $item): ?>
                                <li><?= e($item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <p style="margin: 14px 0 0; color: #7a1f1f; font-weight: 600;">Protected: the super-admin account remains in place in both modes.</p>
            </div>

            <?php if ($resetConfirmed): ?>
                <?php if ($resetMode === 'soft'): ?>
                    <form method="post" action="<?= url('/admin/system/soft_reset.php') ?>">
                        <?= csrf_field() ?>
                        <div class="alert alert-warning" style="margin-bottom: 14px;">
                            <strong>Step 2:</strong> Confirm the soft reset exactly as reviewed above.
                        </div>
                        <div class="form-group">
                            <label for="soft_confirm_text">Type <strong>SOFT RESET</strong> to confirm the softer wipe</label>
                            <input id="soft_confirm_text" name="confirm_text" type="text" required maxlength="32" placeholder="SOFT RESET" style="text-transform: uppercase;">
                        </div>
                        <div class="form-check" style="margin: 14px 0;">
                            <input class="form-check-input" type="checkbox" id="soft_acknowledge" name="acknowledge" value="1" required>
                            <label class="form-check-label" for="soft_acknowledge">
                                I understand the consequences and want to proceed with the soft reset.
                            </label>
                        </div>
                        <button type="submit" class="btn btn-warning" data-confirm="This keeps user accounts but clears transactional and catalog data. Continue?">
                            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Confirm soft reset
                        </button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= url('/admin/system/clear.php') ?>">
                        <?= csrf_field() ?>
                        <div class="alert alert-danger" style="margin-bottom: 14px;">
                            <strong>Step 2:</strong> Confirm the full reset exactly as reviewed above.
                        </div>
                        <div class="form-group">
                            <label for="confirm_text">Type <strong>DELETE SYSTEM DATA</strong> to confirm the full wipe</label>
                            <input id="confirm_text" name="confirm_text" type="text" required maxlength="32" placeholder="DELETE SYSTEM DATA" style="text-transform: uppercase;">
                        </div>
                        <div class="form-check" style="margin: 14px 0;">
                            <input class="form-check-input" type="checkbox" id="full_acknowledge" name="acknowledge" value="1" required>
                            <label class="form-check-label" for="full_acknowledge">
                                I understand the consequences and want to proceed with the full reset.
                            </label>
                        </div>
                        <button type="submit" class="btn btn-danger" data-confirm="This will permanently clear system data. Continue?">
                            <i class="bi bi-trash3 me-1" aria-hidden="true"></i>Confirm full reset
                        </button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info" style="margin-bottom: 0;">
                    <strong>Step 1:</strong> Review the reset carefully, then choose the action to continue to the final confirmation step.
                </div>

                <form method="get" action="<?= url('/admin/system/index.php') ?>" style="display: grid; gap: 16px;">
                    <input type="hidden" name="step" value="confirm">
                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                        <button type="submit" name="reset" value="soft" class="btn btn-warning">
                            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Review reset
                        </button>
                        <button type="submit" name="reset" value="full" class="btn btn-danger">
                            <i class="bi bi-trash3 me-1" aria-hidden="true"></i>Review full reset
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
