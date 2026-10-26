<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
$stmt->execute([$id]);
$supplier = $stmt->fetch();

if (!$supplier) {
    set_flash('error', 'Supplier not found.');
    redirect('/admin/suppliers/index.php');
}

$pageTitle = $supplier['name'];
$canSeeCost = is_admin();

// Supplier accounts are bound to this record by email, so surface the resolved
// login account (or its absence) — a mis-linked supplier is otherwise invisible.
$portalUserId = get_supplier_user_id((int) $supplier['id']);
$portalAccount = null;
if ($portalUserId !== null) {
    $portalAccountStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $portalAccountStmt->execute([$portalUserId]);
    $portalAccount = $portalAccountStmt->fetch() ?: null;
}

$purchaseOrders = [];
if ($canSeeCost) {
    $purchaseOrders = $pdo->prepare(
        'SELECT po.*, (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS item_count
         FROM purchase_orders po WHERE po.supplier_id = ? ORDER BY po.created_at DESC'
    );
    $purchaseOrders->execute([$id]);
    $purchaseOrders = $purchaseOrders->fetchAll();
}

$linkedProducts = $pdo->prepare(
    "SELECT sp.*, p.name AS product_name FROM supplier_products sp
     JOIN products p ON p.id = sp.product_id
     WHERE sp.supplier_id = ? ORDER BY p.name"
);
$linkedProducts->execute([$id]);
$linkedProducts = $linkedProducts->fetchAll();

$linkedProductIds = array_column($linkedProducts, 'product_id');
$availableProducts = $pdo->query('SELECT id, name FROM products ORDER BY name')->fetchAll();

// Keyed by product_id, used to pre-fill the link form when an admin/staff
// member re-selects an already-linked product to edit it — without this,
// re-saving with blank fields would silently wipe existing values (price
// included) since the backend treats a blank submission as "clear it".
$linkedProductsById = [];
foreach ($linkedProducts as $lp) {
    $linkedProductsById[$lp['product_id']] = [
        'warranty_covered' => (bool) $lp['warranty_covered'],
        'lead_time_days'   => $lp['lead_time_days'],
        'notes'            => $lp['notes'],
        'supplier_price'   => $canSeeCost ? $lp['supplier_price'] : null,
    ];
}

$orders = $pdo->prepare(
    "SELECT o.*, p.name AS product_name, u.full_name AS customer_name FROM orders o
     JOIN products p ON p.id = o.product_id
     JOIN users u ON u.id = o.user_id
     WHERE o.supplier_id = ? ORDER BY o.created_at DESC"
);
$orders->execute([$id]);
$orders = $orders->fetchAll();

if ($canSeeCost) {
    $totalSpend = array_sum(array_column($orders, 'supplier_cost'));
    $totalRevenue = array_sum(array_column($orders, 'total_amount'));
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2><?= e($supplier['name']) ?></h2>
        <div class="table-actions">
            <span class="badge badge-<?= $supplier['status'] === 'active' ? 'success' : 'neutral' ?>"><?= ucfirst(e($supplier['status'])) ?></span>
            <?php $perfStatus = $supplier['performance_status'] ?? 'watchlist'; ?>
            <?php if ($perfStatus === 'preferred'): ?>
                <span class="badge badge-success">Preferred</span>
            <?php elseif ($perfStatus === 'needs_review'): ?>
                <span class="badge badge-danger">Needs review</span>
            <?php else: ?>
                <span class="badge badge-warning">Watchlist</span>
            <?php endif; ?>
            <a href="<?= url('/admin/suppliers/edit.php?id=' . $supplier['id']) ?>" class="btn btn-outline btn-sm">Edit</a>
        </div>
    </div>
    <div class="panel-body">
        <div class="detail-grid">
            <div class="detail-item"><div class="label">Contact person</div><div class="value"><?= e($supplier['contact_person'] ?: '—') ?></div></div>
            <div class="detail-item"><div class="label">Phone</div><div class="value"><?= e($supplier['phone'] ?: '—') ?></div></div>
            <div class="detail-item"><div class="label">Email</div><div class="value"><?= e($supplier['email'] ?: '—') ?></div></div>
            <div class="detail-item"><div class="label">Address</div><div class="value"><?= e($supplier['address'] ?: '—') ?></div></div>
            <div class="detail-item">
                <div class="label">Portal account</div>
                <div class="value">
                    <?php if ($portalAccount): ?>
                        Linked to login account <?= e($portalAccount['email']) ?>
                    <?php else: ?>
                        <span class="muted">Not linked — no active supplier user account uses this email address</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <hr class="divider">
        <h4 style="margin-bottom: 12px; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted);">Payment Terms & Financial Details</h4>
        <div class="detail-grid">
            <div class="detail-item">
                <div class="label">Payment terms</div>
                <div class="value">
                    <span class="badge badge-info"><?= e(supplier_payment_terms_label($supplier['payment_terms'] ?? 'net_30', (int) ($supplier['advance_percentage'] ?? 0))) ?></span>
                </div>
            </div>
            <?php if (($supplier['payment_terms'] ?? '') === 'split_advance'): ?>
                <div class="detail-item">
                    <div class="label">Advance requirement</div>
                    <div class="value"><?= (int) $supplier['advance_percentage'] ?>% advance / <?= (100 - (int) $supplier['advance_percentage']) ?>% balance</div>
                </div>
            <?php endif; ?>
            <div class="detail-item">
                <div class="label">Bank details</div>
                <div class="value">
                    <?php if ($supplier['bank_name'] || $supplier['bank_account_number']): ?>
                        <strong><?= e($supplier['bank_name'] ?: 'Bank') ?></strong>
                        <div><?= e($supplier['bank_account_name'] ?: '') ?></div>
                        <div>Acc: <code><?= e($supplier['bank_account_number'] ?: '—') ?></code> <?= $supplier['bank_branch'] ? '(' . e($supplier['bank_branch']) . ')' : '' ?></div>
                    <?php else: ?>
                        <span class="muted">No bank details recorded</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="detail-item">
                <div class="label">Mobile money</div>
                <div class="value">
                    <?php if ($supplier['mobile_money_number']): ?>
                        <strong><?= e($supplier['mobile_money_provider'] ?: 'Mobile Money') ?></strong>
                        <div><code><?= e($supplier['mobile_money_number']) ?></code></div>
                    <?php else: ?>
                        <span class="muted">No mobile money details</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="detail-item">
                <div class="label">TIN / Tax ID</div>
                <div class="value"><code><?= e($supplier['tax_identification_number'] ?: '—') ?></code></div>
            </div>
        </div>

        <?php if ($supplier['notes']): ?>
        <hr class="divider">
        <div class="detail-item"><div class="label">Notes</div><div class="value"><?= nl2br(e($supplier['notes'])) ?></div></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canSeeCost): ?>
<div class="stat-grid">
    <div class="stat-card"><div class="label">Orders fulfilled</div><div class="value"><?= count($orders) ?></div></div>
    <div class="stat-card"><div class="label">Total spend with this supplier</div><div class="value" style="font-size: 1.3rem;"><?= money($totalSpend) ?></div></div>
    <div class="stat-card"><div class="label">Total order value</div><div class="value" style="font-size: 1.3rem;"><?= money($totalRevenue) ?></div></div>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><h3>Products supplied</h3></div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Product</th><th>Warranty</th><th>Lead time</th>
                    <?php if ($canSeeCost): ?><th>Supplier price</th><?php endif; ?>
                    <th>Notes</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($linkedProducts as $lp): ?>
                <tr>
                    <td><?= e($lp['product_name']) ?></td>
                    <td><span class="badge badge-<?= $lp['warranty_covered'] ? 'success' : 'warning' ?>"><?= $lp['warranty_covered'] ? 'Covered' : 'Not covered' ?></span></td>
                    <td><?= $lp['lead_time_days'] !== null ? (int) $lp['lead_time_days'] . ' day' . ((int) $lp['lead_time_days'] === 1 ? '' : 's') : '—' ?></td>
                    <?php if ($canSeeCost): ?>
                    <td><?= $lp['supplier_price'] !== null ? money($lp['supplier_price']) : '<span class="muted">Not set</span>' ?></td>
                    <?php endif; ?>
                    <td class="muted"><?= e($lp['notes'] ?: '—') ?></td>
                    <td>
                        <form method="post" action="<?= url('/admin/suppliers/unlink_product.php') ?>" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $lp['id'] ?>">
                            <input type="hidden" name="supplier_id" value="<?= $supplier['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Remove this product from this supplier?">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$linkedProducts): ?>
                    <tr><td colspan="<?= $canSeeCost ? 6 : 5 ?>" class="empty-state">No products linked to this supplier yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Link a product to this supplier</h3></div>
    <div class="panel-body">
        <form method="post" action="<?= url('/admin/suppliers/link_product.php') ?>" style="max-width: 560px;">
            <?= csrf_field() ?>
            <input type="hidden" name="supplier_id" value="<?= $supplier['id'] ?>">
            <div class="field">
                <label for="product_id">Product</label>
                <select id="product_id" name="product_id" required>
                    <option value="">Select a product</option>
                    <?php foreach ($availableProducts as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= e($p['name']) ?><?= in_array($p['id'], $linkedProductIds, true) ? ' (already linked — this will update it)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field-row">
                <div class="field">
                    <label><input type="checkbox" id="warranty_covered" name="warranty_covered" checked style="width: auto; margin-right: 8px;">Warranty covered by this supplier</label>
                </div>
                <div class="field">
                    <label for="lead_time_days">Typical lead time (days)</label>
                    <input type="number" id="lead_time_days" name="lead_time_days" min="0">
                </div>
            </div>
            <?php if ($canSeeCost): ?>
            <div class="field">
                <label for="supplier_price">Supplier price (UGX)</label>
                <input type="number" id="supplier_price" name="supplier_price" min="0" step="1000">
                <div class="hint">Leave blank if not yet negotiated.</div>
            </div>
            <?php else: ?>
            <div class="hint" style="margin-bottom: 16px;">Pricing for this link is set by an administrator.</div>
            <?php endif; ?>
            <div class="field">
                <label for="notes">Notes</label>
                <input type="text" id="notes" name="notes" placeholder="e.g. stock availability, minimum order quantity">
            </div>
            <button type="submit" class="btn btn-accent">Save link</button>
        </form>
    </div>
</div>

<script>
// Pre-fill the link form with a product's existing values when it's
// re-selected for editing, so saving doesn't accidentally blank them out.
var linkedProducts = <?= json_encode($linkedProductsById, JSON_UNESCAPED_SLASHES) ?>;
var productSelect = document.getElementById('product_id');
var warrantyField = document.getElementById('warranty_covered');
var leadTimeField = document.getElementById('lead_time_days');
var notesField = document.getElementById('notes');
var priceField = document.getElementById('supplier_price');

productSelect.addEventListener('change', function () {
    var existing = linkedProducts[this.value];
    if (existing) {
        warrantyField.checked = !!existing.warranty_covered;
        leadTimeField.value = existing.lead_time_days !== null ? existing.lead_time_days : '';
        notesField.value = existing.notes || '';
        if (priceField) {
            priceField.value = existing.supplier_price !== null ? existing.supplier_price : '';
        }
    } else {
        warrantyField.checked = true;
        leadTimeField.value = '';
        notesField.value = '';
        if (priceField) {
            priceField.value = '';
        }
    }
});
</script>

<div class="panel">
    <div class="panel-head"><h3>Order history</h3></div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Order</th><th>Product</th><th>Customer</th><th>Order total</th>
                    <?php if ($canSeeCost): ?><th>Supplier cost</th><?php endif; ?>
                    <th>Status</th><th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td><a href="<?= url('/admin/orders/view.php?id=' . $o['id']) ?>">#<?= (int) $o['id'] ?></a></td>
                    <td><?= e($o['product_name']) ?></td>
                    <td><?= e($o['customer_name']) ?></td>
                    <td><?= money($o['total_amount']) ?></td>
                    <?php if ($canSeeCost): ?>
                    <td><?= $o['supplier_cost'] !== null ? money($o['supplier_cost']) : '—' ?></td>
                    <?php endif; ?>
                    <td><span class="badge badge-<?= order_status_badge_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
                    <td><?= display_date($o['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?>
                    <tr><td colspan="<?= $canSeeCost ? 7 : 6 ?>" class="empty-state">No orders fulfilled by this supplier yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canSeeCost): ?>
<div class="panel">
    <div class="panel-head">
        <h3>Purchase orders</h3>
        <a href="<?= url('/admin/purchase_orders/add.php?supplier_id=' . $supplier['id']) ?>" class="btn btn-outline btn-sm">New purchase order</a>
    </div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>PO number</th><th>Items</th><th>Grand total</th><th>Status</th><th>Created</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($purchaseOrders as $po): ?>
                <tr>
                    <td><?= e($po['po_number']) ?></td>
                    <td><?= (int) $po['item_count'] ?></td>
                    <td><?= money($po['grand_total']) ?></td>
                    <td><span class="badge badge-<?= po_status_badge_class($po['status']) ?>"><?= e(po_status_label($po['status'])) ?></span></td>
                    <td><?= display_date($po['created_at']) ?></td>
                    <td><a href="<?= url('/admin/purchase_orders/view.php?id=' . $po['id']) ?>" class="btn btn-outline btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$purchaseOrders): ?>
                    <tr><td colspan="6" class="empty-state">No purchase orders placed with this supplier yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
