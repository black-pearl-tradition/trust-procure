<?php
/**
 * Cart and checkout panel for the point-of-sale terminal.
 *
 * Expects $posState from pos_cart_state(); optionally $posCustomerResults
 * (customer search matches) to render suggestions. Every control posts to
 * admin/pos/cart.php or admin/pos/checkout.php, so the till also works without
 * JavaScript; assets/js/pos.js intercepts them to refresh the panel in place.
 */

$posState = $posState ?? [
    'lines' => [], 'item_count' => 0, 'line_count' => 0, 'subtotal' => 0.0,
    'discount' => 0.0, 'total' => 0.0, 'customer' => null, 'customer_id' => 0,
    'walk_in_name' => '', 'note' => '', 'method' => 'cash', 'tendered' => 0.0, 'reference' => '',
];
$posLines = $posState['lines'];
$posCustomer = $posState['customer'];
$posCustomerResults = $posCustomerResults ?? [];

// Wallet and credit position for the selected customer (nulls for walk-ins).
$posWallet = null;
$posOwed = null;
$posCreditLimit = null;
$posTerms = null;
$posCreditOk = true;
$posCreditReason = '';
if ($posCustomer) {
    $posWallet = pos_wallet_balance($pdo, (int) $posCustomer['id']);
    $posOwed = pos_outstanding_credit($pdo, (int) $posCustomer['id']);
    $posCreditLimit = pos_credit_limit($pdo, (int) $posCustomer['id']);
    $posTerms = pos_credit_terms_days($pdo, (int) $posCustomer['id']);
}

$posMethod = $posState['method'];
$posTotal = (float) $posState['total'];
$posTendered = (float) $posState['tendered'];
$posChange = round(max(0.0, $posTendered - $posTotal), 2);
?>

<div class="panel-head">
    <div>
        <h3>Current sale</h3>
        <p class="muted mb-0"><?= (int) $posState['item_count'] ?> unit(s) across <?= (int) $posState['line_count'] ?> line(s)</p>
    </div>
    <form method="post" action="<?= url('/admin/pos/cart.php') ?>" data-confirm="Clear this sale? The items, the customer and any typed contact details are all removed.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear">
        <button type="submit" class="btn btn-outline btn-sm" <?= $posLines ? '' : 'disabled' ?>>Clear</button>
    </form>
</div>

<div class="panel-body">
    <div class="field">
        <label>Customer</label>
        <?php
        // The details below are snapshotted onto the sale and printed on the
        // receipt, so they stay editable even when an account is attached: a
        // customer may give a different number, or buy for someone else.
        $posContactName = (string) $posState['contact_name'];
        $posContactPhone = (string) $posState['contact_phone'];
        $posContactEmail = (string) $posState['contact_email'];
        $posContactSummary = $posContactPhone !== ''
            ? $posContactPhone
            : ($posContactEmail !== '' ? $posContactEmail : 'No contact details captured');
        ?>
        <?php if ($posCustomer): ?>
            <div class="pos-customer-chip">
                <div>
                    <strong><?= e($posCustomer['full_name']) ?></strong>
                    <div class="muted" style="font-size: 0.8rem;">Account attached — <?= e($posContactSummary) ?></div>
                </div>
                <form method="post" action="<?= url('/admin/pos/cart.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_customer">
                    <input type="hidden" name="customer_id" value="0">
                    <button type="submit" class="btn btn-outline btn-sm">Change</button>
                </form>
            </div>
        <?php else: ?>
            <div class="pos-customer-chip">
                <div>
                    <strong><?= $posContactName !== '' ? e($posContactName) : 'Walk-in customer' ?></strong>
                    <div class="muted" style="font-size: 0.8rem;">
                        <?= $posContactName !== '' ? e($posContactSummary) : 'No account attached to this sale' ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= url('/admin/pos/cart.php') ?>" class="pos-contact-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_contact">
            <div class="field">
                <label for="posContactName">Name on the receipt</label>
                <input type="text" id="posContactName" name="contact_name" maxlength="120" value="<?= e($posContactName) ?>" placeholder="Walk-in or the person being served">
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="posContactPhone">Phone</label>
                    <input type="text" id="posContactPhone" name="contact_phone" maxlength="30" value="<?= e($posContactPhone) ?>" placeholder="07…" autocomplete="off">
                </div>
                <div class="field">
                    <label for="posContactEmail">Email <span class="muted">(optional)</span></label>
                    <input type="email" id="posContactEmail" name="contact_email" maxlength="150" value="<?= e($posContactEmail) ?>" placeholder="For warranty or offers" autocomplete="off">
                </div>
            </div>
            <div class="pos-contact-actions">
                <button type="submit" class="btn btn-outline btn-sm" id="posContactSave">
                    <i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Save details
                </button>
                <span class="muted" style="font-size: 0.78rem;">Printed on the receipt.</span>
            </div>
        </form>

        <?php if ($posCustomer): ?>

            <div class="pos-customer-stats">
                <div class="pos-stat">
                    <span class="label">Wallet</span>
                    <span class="value"><?= $posWallet !== null ? money($posWallet) : '—' ?></span>
                </div>
                <div class="pos-stat">
                    <span class="label">Owing</span>
                    <span class="value"><?= money((float) $posOwed) ?></span>
                </div>
                <div class="pos-stat">
                    <span class="label">Terms</span>
                    <span class="value"><?= (int) $posTerms ?> days<?= $posCreditLimit !== null ? ' / limit ' . money($posCreditLimit) : '' ?></span>
                </div>
            </div>
            <?php if (pos_wallets_ready($pdo)): ?>
                <?php /* History is open to any staff member; only an administrator
                        can add credit, so the label never promises more than the
                        reader can actually do. */ ?>
                <a href="<?= url('/admin/pos/wallets.php?user_id=' . (int) $posCustomer['id']) ?>" class="btn btn-outline btn-sm" style="margin-top: 8px;">
                    <i class="bi bi-wallet2 me-1"></i>Wallet history<?= is_admin() ? ' &amp; top up' : '' ?>
                </a>
            <?php endif; ?>
        <?php else: ?>
            <form method="get" action="<?= url('/admin/pos/index.php') ?>" class="pos-inline-form" style="margin-top: 8px;">
                <input type="text" id="posCustomerSearch" name="cust_q"
                    value="<?= e($_GET['cust_q'] ?? '') ?>" placeholder="Name, phone or email" autocomplete="off">
                <button type="submit" class="btn btn-outline btn-sm">Find</button>
            </form>

            <?php if ($posCustomerResults): ?>
                <ul class="pos-customer-results">
                    <?php foreach ($posCustomerResults as $result): ?>
                        <li>
                            <span>
                                <strong><?= e($result['full_name']) ?></strong>
                                <span class="muted"><?= e($result['email'] ?: $result['phone'] ?: '') ?></span>
                            </span>
                            <form method="post" action="<?= url('/admin/pos/cart.php') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_customer">
                                <input type="hidden" name="customer_id" value="<?= (int) $result['id'] ?>">
                                <button type="submit" class="btn btn-accent btn-sm">Use</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif (trim((string) ($_GET['cust_q'] ?? '')) !== ''): ?>
                <p class="muted" style="margin-top: 8px; font-size: 0.85rem;">No registered customer matched — this stays a walk-in sale.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="panel-body">
    <?php if ($posLines): ?>
        <div class="pos-lines">
            <?php foreach ($posLines as $line): ?>
                <div class="pos-line">
                    <div class="pos-line-main">
                        <strong><?= e($line['name']) ?></strong>
                        <span class="muted" style="font-size: 0.8rem;">
                            <?= $line['sku'] ? 'SKU ' . e($line['sku']) : '' ?>
                            <?= $line['made_to_order'] ? 'Made to order' : 'In stock: ' . (int) $line['available'] ?>
                        </span>
                    </div>
                    <div class="pos-line-price"><?= money($line['unit_price']) ?></div>
                    <div class="pos-line-qty">
                        <form method="post" action="<?= url('/admin/pos/cart.php') ?>" class="pos-qty-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="product_id" value="<?= (int) $line['product_id'] ?>">
                            <input type="hidden" name="quantity" value="<?= (int) max(1, $line['quantity'] - 1) ?>">
                            <button type="submit" class="btn btn-outline btn-sm" aria-label="Reduce quantity">−</button>
                        </form>
                        <span class="pos-qty-value"><?= (int) $line['quantity'] ?></span>
                        <form method="post" action="<?= url('/admin/pos/cart.php') ?>" class="pos-qty-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="product_id" value="<?= (int) $line['product_id'] ?>">
                            <input type="hidden" name="quantity" value="<?= (int) $line['quantity'] + 1 ?>">
                            <button type="submit" class="btn btn-outline btn-sm" aria-label="Increase quantity">+</button>
                        </form>
                    </div>
                    <div class="pos-line-total"><?= money($line['line_total']) ?></div>
                    <form method="post" action="<?= url('/admin/pos/cart.php') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="product_id" value="<?= (int) $line['product_id'] ?>">
                        <button type="submit" class="btn btn-outline btn-sm" aria-label="Remove line">×</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state" style="padding: 24px 12px;">
            <i class="bi bi-cart3" style="font-size: 1.8rem;"></i>
            <p>Scan a barcode or tap a product to start the sale.</p>
        </div>
    <?php endif; ?>
</div>


<div class="panel-body">
    <div class="field-row">
        <div class="field">
            <label for="posDiscount">Discount (UGX)</label>
            <form method="post" action="<?= url('/admin/pos/cart.php') ?>" class="pos-inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_discount">
                <input type="number" id="posDiscount" name="discount" min="0" step="1000"
                    value="<?= $posState['discount'] > 0 ? (float) $posState['discount'] : '' ?>" placeholder="0">
                <button type="submit" class="btn btn-outline btn-sm">Apply</button>
            </form>
        </div>
        <div class="field">
            <label for="posNote">Sale note</label>
            <form method="post" action="<?= url('/admin/pos/cart.php') ?>" class="pos-inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_note">
                <input type="text" id="posNote" name="note" maxlength="500" value="<?= e($posState['note']) ?>" placeholder="optional">
                <button type="submit" class="btn btn-outline btn-sm">Save</button>
            </form>
        </div>
    </div>

    <div class="pos-totals">
        <div class="pos-total-row"><span>Subtotal</span><span><?= money($posState['subtotal']) ?></span></div>
        <div class="pos-total-row"><span>Discount</span><span>− <?= money($posState['discount']) ?></span></div>
        <div class="pos-total-row pos-total-grand"><span>Total due</span><span><?= money($posTotal) ?></span></div>
    </div>

    <div class="field" style="margin-bottom: 0;">
        <label>Payment method</label>
        <div class="pos-methods">
            <?php foreach (pos_available_methods($pdo) as $methodKey => $methodLabel): ?>
                <form method="post" action="<?= url('/admin/pos/cart.php') ?>" class="pos-method-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_method">
                    <input type="hidden" name="method" value="<?= $methodKey ?>">
                    <button type="submit"
                        class="pos-method <?= $posMethod === $methodKey ? 'is-active' : '' ?>"
                        aria-pressed="<?= $posMethod === $methodKey ? 'true' : 'false' ?>">
                        <?= e($methodLabel) ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</div>


<div class="panel-body" style="border-top: 1px solid var(--border);">
    <?php if ($posMethod === 'cash'): ?>
        <div class="field" style="margin-bottom: 0;">
            <label for="posTendered">Cash received (UGX)</label>
            <form method="post" action="<?= url('/admin/pos/cart.php') ?>" class="pos-inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_tendered">
                <input type="number" id="posTendered" name="tendered" min="0" step="1000"
                    value="<?= $posTendered > 0 ? $posTendered : '' ?>" placeholder="<?= (int) $posTotal ?>">
                <button type="submit" class="btn btn-outline btn-sm">Set</button>
            </form>
            <div class="hint pos-change-line">
                Change due: <strong><?= money($posChange) ?></strong>
                <?php if ($posTendered > 0 && $posTendered < $posTotal): ?>
                    <span class="badge badge-warning" style="margin-left: 6px;">Short by <?= money($posTotal - $posTendered) ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif (in_array($posMethod, ['mobile_money', 'card'], true)): ?>
        <div class="field" style="margin-bottom: 0;">
            <label for="posReference"><?= $posMethod === 'mobile_money' ? 'Mobile money reference' : 'Card reference' ?></label>
            <form method="post" action="<?= url('/admin/pos/cart.php') ?>" class="pos-inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_reference">
                <input type="text" id="posReference" name="reference" maxlength="100"
                    value="<?= e($posState['reference']) ?>" placeholder="e.g. TXN-845120">
                <button type="submit" class="btn btn-outline btn-sm">Save</button>
            </form>
        </div>
    <?php elseif ($posMethod === 'wallet'): ?>
        <?php if ($posCustomer): ?>
            <div class="pos-wallet-box <?= $posWallet !== null && $posWallet < $posTotal ? 'is-short' : '' ?>">
                Wallet balance <strong><?= money((float) $posWallet) ?></strong> against this sale of <strong><?= money($posTotal) ?></strong>.
                <?php if ($posWallet !== null && $posWallet < $posTotal): ?>
                    <div style="margin-top: 6px;">
                        <span class="badge badge-danger">Short by <?= money($posTotal - $posWallet) ?></span>
                        <?php if (is_admin()): ?>
                            <a href="<?= url('/admin/pos/wallets.php?user_id=' . (int) $posCustomer['id']) ?>" class="btn btn-outline btn-sm" style="margin-left: 6px;">Top up wallet</a>
                        <?php else: ?>
                            <?php /* Staff cannot add credit, so they get the history
                                    page rather than a button that would refuse. */ ?>
                            <a href="<?= url('/admin/pos/wallets.php?user_id=' . (int) $posCustomer['id']) ?>" class="btn btn-outline btn-sm" style="margin-left: 6px;">Wallet history</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="pos-wallet-box is-short">
                Wallet payment needs a registered customer. Find the customer first, or choose another method.
            </div>
        <?php endif; ?>
    <?php elseif ($posMethod === 'credit'): ?>
        <?php if ($posCustomer): ?>
            <?php [$posCreditOk, $posCreditReason] = pos_credit_allowed($pdo, (int) $posCustomer['id'], $posTotal); ?>
            <div class="pos-wallet-box">
                <?= (int) $posTerms ?> days' credit — due
                <strong><?= date('d M Y', strtotime('+' . (int) $posTerms . ' days')) ?></strong>.
                <div class="muted" style="font-size: 0.85rem; margin-top: 4px;">
                    Already owing <?= money((float) $posOwed) ?><?= $posCreditLimit !== null ? ' of a ' . money($posCreditLimit) . ' limit' : '' ?>.
                </div>
                <?php if (!$posCreditOk): ?>
                    <div style="margin-top: 6px;"><span class="badge badge-danger"><?= e($posCreditReason) ?></span></div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="pos-wallet-box is-short">
                Credit sales need a registered customer. Find the customer first, or choose another method.
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="panel-body" style="border-top: 1px solid var(--border);">
    <form method="post" action="<?= url('/admin/pos/checkout.php') ?>" id="posCheckoutForm">
        <?= csrf_field() ?>
        <input type="hidden" name="method" value="<?= e($posMethod) ?>">
        <button type="submit" class="btn btn-accent btn-block" <?= $posLines ? '' : 'disabled' ?>
            data-confirm="Complete this sale of <?= money($posTotal) ?> by <?= e(pos_payment_method_label($posMethod)) ?>?">
            Complete sale — <?= money($posTotal) ?>
        </button>
        <p class="muted" style="font-size: 0.78rem; margin-top: 8px; margin-bottom: 0;">
            Records the sale, takes stock out of inventory, logs the payment and opens the receipt.
        </p>
    </form>
</div>

