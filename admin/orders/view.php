<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/functions.php';
require_staff();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT o.*, u.full_name, u.email, u.phone, p.name AS product_name, p.image
     FROM orders o
     JOIN users u ON u.id = o.user_id
     JOIN products p ON p.id = o.product_id
     WHERE o.id = ?"
);
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    set_flash('error', 'Order not found.');
    redirect('/admin/orders/index.php?tab=orders');
}

$orderItemsStmt = $pdo->prepare(
    'SELECT oi.*, p.name AS product_name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id'
);
$orderItemsStmt->execute([$id]);
$orderItems = $orderItemsStmt->fetchAll();

$mainFlow = ['request_approved', 'pending_deposit', 'deposit_paid', 'processing', 'procuring', 'shipped', 'delivered', 'completed'];
// Random access: admin/staff can pick any status from the dropdown,
// regardless of the current status. Request-stage orders
// (request_submitted / request_rejected) are still handled via the
// Approve / Reject buttons above, so they keep their locked list.
if (in_array($order['status'], ['request_submitted', 'request_rejected'], true)) {
    $statuses = [$order['status']];
} else {
    $statuses = array_values(array_unique(array_merge($mainFlow, ['cancelled'])));
}
$canSeeCost = is_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajaxRequest = ((($_POST['ajax'] ?? '') === '1') || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'));
    verify_csrf('/admin/orders/index.php');
    $newStatus = $_POST['status'] ?? '';
    $deliveryAddress = trim($_POST['delivery_address'] ?? '');
    $cancellationReason = trim($_POST['cancellation_reason'] ?? '');
    $supplierId = $_POST['supplier_id'] !== '' ? (int) ($_POST['supplier_id'] ?? 0) : null;

    if (in_array($newStatus, $statuses, true)) {
        // Random access: any status may be picked from the dropdown, so the
        // old forward-only guards (verified deposit / fully paid) are
        // intentionally not enforced here. Stock moves below keep inventory
        // consistent whichever direction the admin/staff moves the order.
        $statusChanged = $newStatus !== $order['status'];
        $fulfillmentStatuses = ['shipped', 'delivered', 'completed'];
        $wasFulfilled = in_array($order['status'], $fulfillmentStatuses, true);
        $willBeFulfilled = in_array($newStatus, $fulfillmentStatuses, true);
        $wasCancelled = $order['status'] === 'cancelled';
        $willCancel = $newStatus === 'cancelled';

        $stockEventStmt = $pdo->prepare("SELECT COUNT(*) FROM order_events WHERE order_id = ? AND event_type = 'stock_deducted'");
        $stockEventStmt->execute([$id]);
        $wasStockDeducted = (int) $stockEventStmt->fetchColumn() > 0;

        $needsStockDeduction = $willBeFulfilled && !$wasStockDeducted;
        // Moving back out of fulfillment (or cancelling after fulfillment)
        // puts the previously deducted units back into stock so a mistaken
        // forward move can be corrected from the same dropdown.
        $needsStockRestore = $wasStockDeducted && !$willBeFulfilled;

        $stockByProduct = [];
        if ($needsStockDeduction || $needsStockRestore) {
            if ($orderItems) {
                foreach ($orderItems as $item) {
                    $productId = (int) $item['product_id'];
                    $stockByProduct[$productId] = ($stockByProduct[$productId] ?? 0) + (int) $item['quantity'];
                }
            } else {
                $stockByProduct[(int) $order['product_id']] = (int) $order['quantity'];
            }
        }

        $pdo->beginTransaction();
        try {
            if ($willCancel && !$wasCancelled) {
                release_order_stock($pdo, $id);
            } elseif ($wasCancelled && !$willCancel) {
                // Re-activate a cancelled order: re-hold the units for it.
                try {
                    reserve_order_stock($pdo, $id);
                } catch (RuntimeException $reserveError) {
                    throw new RuntimeException('Could not re-activate this order: ' . $reserveError->getMessage());
                }
            }

            if ($needsStockDeduction) {
                $stockStmt = $pdo->prepare('SELECT name, stock_quantity, reserved_quantity, stock_status FROM products WHERE id = ? FOR UPDATE');
                $updateStockStmt = $pdo->prepare(
                    "UPDATE products
                     SET stock_quantity = stock_quantity - ?,
                         reserved_quantity = GREATEST(reserved_quantity - ?, 0),
                         stock_status = CASE WHEN stock_quantity <= 0 THEN 'out_of_stock' ELSE 'in_stock' END
                     WHERE id = ?"
                );

                foreach ($stockByProduct as $productId => $quantity) {
                    $stockStmt->execute([$productId]);
                    $stockProduct = $stockStmt->fetch();
                    if ($stockProduct && $stockProduct['stock_status'] === 'made_to_order') {
                        continue;
                    }
                    if (!$stockProduct || (int) $stockProduct['stock_quantity'] < $quantity) {
                        $productName = $stockProduct['name'] ?? 'the requested product';
                        throw new RuntimeException('Insufficient stock for ' . $productName . '. Receive or add more stock before fulfilling this order.');
                    }
                    $updateStockStmt->execute([$quantity, $quantity, $productId]);
                    $stockStateStmt = $pdo->prepare('SELECT stock_quantity, low_stock_threshold FROM products WHERE id = ?');
                    $stockStateStmt->execute([(int) $productId]);
                    $stockState = $stockStateStmt->fetch();
                    notify_low_stock($pdo, (int) $productId, (int) $stockState['stock_quantity'], (int) $stockState['low_stock_threshold']);
                    record_inventory_movement(
                        $pdo,
                        (int) $productId,
                        $_SESSION['user_id'],
                        'shipped',
                        -$quantity,
                        null,
                        $id,
                        'Deducted when order #' . $id . ' moved to ' . order_status_label($newStatus)
                    );
                }

                record_order_event(
                    $pdo,
                    $id,
                    $_SESSION['user_id'],
                    'stock_deducted',
                    'Stock deducted for fulfillment',
                    'Inventory was reduced when the order moved to ' . order_status_label($newStatus) . '.'
                );
            } elseif ($needsStockRestore) {
                // Undo a previous fulfillment deduction (e.g. shipped ->
                // processing). Reservation is re-applied so the units stay
                // held for this order while it is active again.
                $restoreStmt = $pdo->prepare('SELECT stock_status FROM products WHERE id = ? FOR UPDATE');
                $restoreUpdateStmt = $pdo->prepare(
                    "UPDATE products
                     SET stock_quantity = stock_quantity + ?,
                         reserved_quantity = reserved_quantity + ?,
                         stock_status = CASE WHEN stock_quantity <= 0 THEN 'out_of_stock' ELSE 'in_stock' END
                     WHERE id = ?"
                );
                foreach ($stockByProduct as $productId => $quantity) {
                    $restoreStmt->execute([$productId]);
                    $restoreProduct = $restoreStmt->fetch();
                    if ($restoreProduct && $restoreProduct['stock_status'] === 'made_to_order') {
                        continue;
                    }
                    if (!$restoreProduct) {
                        continue;
                    }
                    $restoreUpdateStmt->execute([$quantity, $quantity, $productId]);
                    record_inventory_movement(
                        $pdo,
                        (int) $productId,
                        $_SESSION['user_id'],
                        'return',
                        $quantity,
                        null,
                        $id,
                        'Restored when order #' . $id . ' moved back to ' . order_status_label($newStatus)
                    );
                }

                record_order_event(
                    $pdo,
                    $id,
                    $_SESSION['user_id'],
                    'stock_restored',
                    'Stock restored',
                    'Inventory was restored when the order moved back to ' . order_status_label($newStatus) . '.'
                );
            }

            // Supplier cost is admin/super_admin only — a staff submission never
            // changes it, whatever the form actually posted.
            if ($canSeeCost) {
                $costInput = $_POST['supplier_cost'] ?? '';
                $supplierCost = $costInput !== '' ? (float) $costInput : null;
                $pdo->prepare('UPDATE orders SET status = ?, delivery_address = ?, cancellation_reason = ?, supplier_id = ?, supplier_cost = ? WHERE id = ?')
                    ->execute([
                        $newStatus,
                        $deliveryAddress,
                        $newStatus === 'cancelled' ? ($cancellationReason ?: null) : null,
                        $supplierId,
                        $supplierCost,
                        $id,
                    ]);
            } else {
                $pdo->prepare('UPDATE orders SET status = ?, delivery_address = ?, cancellation_reason = ?, supplier_id = ? WHERE id = ?')
                    ->execute([
                        $newStatus,
                        $deliveryAddress,
                        $newStatus === 'cancelled' ? ($cancellationReason ?: null) : null,
                        $supplierId,
                        $id,
                    ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', $e->getMessage());
            if ($ajaxRequest) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            redirect('/admin/orders/view.php?id=' . $id);
        }

        log_activity($pdo, $_SESSION['user_id'], 'order_status_updated', "Order #$id set to $newStatus");
        record_order_event($pdo, $id, $_SESSION['user_id'], 'status_' . $newStatus, 'Order status updated', 'Order is now ' . order_status_label($newStatus) . '.');
        create_notification($pdo, (int) $order['user_id'], 'Order status updated', "Your order #{$order['id']} is now " . order_status_label($newStatus) . '.', '/user/orders/view.php?id=' . $id);

        if ($statusChanged) {
            $body = "Hello {$order['full_name']},\n\n"
                . "Your order #{$order['id']} for {$order['product_name']} is now: " . order_status_label($newStatus) . ".\n"
                . ($newStatus === 'cancelled' && $cancellationReason ? "Reason: {$cancellationReason}\n\n" : "\n")
                . "Log in to your account for full order details.";
            send_email($pdo, $order['email'], "Order #{$order['id']} update — " . order_status_label($newStatus), $body);
        }

        set_flash('success', 'Order updated.');
        if ($ajaxRequest) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Order updated.']);
            exit;
        }
        redirect('/admin/orders/view.php?id=' . $id);
    }
}

$pageTitle = 'Order #' . $order['id'];

$payments = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC');
$payments->execute([$id]);
$payments = $payments->fetchAll();

// Suppliers known to carry this product, plus whichever supplier is
// already assigned (even if inactive or no longer linked to the product)
// so the current selection is never silently dropped from the list.
$suppliers = $pdo->prepare(
    "SELECT DISTINCT s.* FROM suppliers s
     LEFT JOIN supplier_products sp ON sp.supplier_id = s.id AND sp.product_id = ?
     WHERE (sp.id IS NOT NULL AND s.status = 'active') OR s.id = ?
     ORDER BY s.name"
);
$suppliers->execute([$order['product_id'], $order['supplier_id']]);
$suppliers = $suppliers->fetchAll();

if ($order['supplier_id']) {
    $stmt = $pdo->prepare('SELECT name FROM suppliers WHERE id = ?');
    $stmt->execute([$order['supplier_id']]);
    $assignedSupplierName = $stmt->fetchColumn() ?: null;
} else {
    $assignedSupplierName = null;
}

// Purchase orders (to a supplier) that include a line item for this
// customer order, if any — admin-only, since POs are cost documents.
$linkedPOs = [];
if ($canSeeCost) {
    $linkedPOs = $pdo->prepare(
        "SELECT po.id, po.po_number, po.status, poi.quantity_ordered, poi.quantity_received, poi.unit_cost
         FROM purchase_order_items poi
         JOIN purchase_orders po ON po.id = poi.purchase_order_id
         WHERE poi.order_id = ?
         ORDER BY po.created_at DESC"
    );
    $linkedPOs->execute([$order['id']]);
    $linkedPOs = $linkedPOs->fetchAll();
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="account-shell">
    <div class="account-summary">
        <div class="account-summary-main">
            <div class="account-summary-meta">Order operations</div>
            <div>
                <div class="section-kicker">Procurement flow</div>
                <h2>Order #<?= (int) $order['id'] ?></h2>
                <p><?= e($order['product_name']) ?> · <?= (int) $order['quantity'] ?> unit<?= (int) $order['quantity'] === 1 ? '' : 's' ?></p>
            </div>
        </div>
        <span class="badge badge-<?= order_status_badge_class($order['status']) ?>"><?= e(order_status_label($order['status'])) ?></span>
    </div>

    <div class="request-stat-grid">
        <div class="request-stat-card">
            <div class="label">Order total</div>
            <div class="value"><?= money($order['total_amount']) ?></div>
        </div>
        <div class="request-stat-card is-alert">
            <div class="label">Deposit</div>
            <div class="value"><?= money($order['deposit_amount']) ?></div>
        </div>
        <div class="request-stat-card is-success">
            <div class="label">Balance</div>
            <div class="value"><?= money($order['balance_amount']) ?></div>
        </div>
        <div class="request-stat-card is-danger">
            <div class="label">Supplier</div>
            <div class="value"><?= $assignedSupplierName ? e($assignedSupplierName) : 'Unassigned' ?></div>
        </div>
    </div>

    <div class="detail-progress-strip" aria-label="Order fulfillment workflow">
        <span class="detail-progress-item active"><strong>01</strong> Approved</span>
        <span class="detail-progress-item"><strong>02</strong> Paid</span>
        <span class="detail-progress-item"><strong>03</strong> Procure</span>
        <span class="detail-progress-item"><strong>04</strong> Deliver</span>
    </div>

<div class="panel">
    <div class="panel-head">
        <h2>Order summary</h2>
        <span class="badge badge-<?= order_status_badge_class($order['status']) ?>"><?= e(order_status_label($order['status'])) ?></span>
    </div>
    <div class="panel-body">
        <div class="detail-grid">
            <div class="detail-item">
                <div class="label">Customer</div>
                <div class="value"><?= e($order['full_name']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Contact</div>
                <div class="value"><?= e($order['email']) ?><?= $order['phone'] ? ' · ' . e($order['phone']) : '' ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Delivery address</div>
                <div class="value"><?= e($order['delivery_address'] ?: '—') ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Customer notes</div>
                <div class="value"><?= e($order['customer_notes'] ?: '—') ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Quantity</div>
                <div class="value"><?= (int) $order['quantity'] ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Unit price</div>
                <div class="value"><?= money($order['unit_price']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Total amount</div>
                <div class="value"><?= money($order['total_amount']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Deposit / balance</div>
                <div class="value"><?= money($order['deposit_amount']) ?> / <?= money($order['balance_amount']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Supplier</div>
                <div class="value"><?= $assignedSupplierName ? e($assignedSupplierName) : '<span class="muted">Not assigned</span>' ?></div>
            </div>
            <?php if ($canSeeCost): ?>
                <div class="detail-item">
                    <div class="label">Supplier cost</div>
                    <div class="value"><?= $order['supplier_cost'] !== null ? money($order['supplier_cost']) : '<span class="muted">Not set</span>' ?></div>
                </div>
            <?php endif; ?>
            <div class="detail-item">
                <div class="label">Created</div>
                <div class="value"><?= display_date($order['created_at']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Last updated</div>
                <div class="value"><?= display_date($order['updated_at']) ?></div>
            </div>
        </div>
        <?php if ($order['status'] === 'cancelled' && $order['cancellation_reason']): ?>
            <hr class="divider">
            <div class="detail-item">
                <div class="label">Cancellation reason</div>
                <div class="value"><?= e($order['cancellation_reason']) ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($orderItems): ?>
    <div class="panel">
        <div class="panel-head">
            <h3><i class="bi bi-bag me-2" aria-hidden="true"></i>Order items</h3>
        </div>
        <div class="panel-body flush">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderItems as $item): ?>
                            <tr>
                                <td><?= e($item['product_name']) ?></td>
                                <td><?= (int) $item['quantity'] ?></td>
                                <td><?= money($item['unit_price']) ?></td>
                                <td><?= money($item['total_amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($canSeeCost): ?>
    <div class="panel">
        <div class="panel-head">
            <h3>Sourced via purchase orders</h3>
            <a href="<?= url('/admin/purchase_orders/add.php?order_id=' . $order['id'] . ($order['supplier_id'] ? '&supplier_id=' . $order['supplier_id'] : '')) ?>" class="btn btn-outline btn-sm">Create purchase order</a>
        </div>
        <div class="panel-body flush">
            <table>
                <thead>
                    <tr>
                        <th>PO number</th>
                        <th>Status</th>
                        <th>Ordered</th>
                        <th>Received</th>
                        <th>Unit cost</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($linkedPOs as $lp): ?>
                        <tr>
                            <td><?= e($lp['po_number']) ?></td>
                            <td><span class="badge badge-<?= po_status_badge_class($lp['status']) ?>"><?= e(po_status_label($lp['status'])) ?></span></td>
                            <td><?= (int) $lp['quantity_ordered'] ?></td>
                            <td><?= (int) $lp['quantity_received'] ?></td>
                            <td><?= money($lp['unit_cost']) ?></td>
                            <td><a href="<?= url('/admin/purchase_orders/view.php?id=' . $lp['id']) ?>" class="btn btn-outline btn-sm">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$linkedPOs): ?>
                        <tr>
                            <td colspan="6" class="empty-state">No purchase order covers this line yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head">
        <h3>Update order</h3>
    </div>
    <div class="panel-body">
        <?php if ($order['status'] === 'request_submitted'): ?>
            <p class="muted" style="max-width: 480px;">This order request hasn't been reviewed yet. Approve it to move the customer into the payment stage, or reject it.</p>
            <div class="table-actions" style="gap: 10px;">
                <button type="button" class="btn btn-accent js-review-request" data-action="approve">Approve</button>
                <button type="button" class="btn btn-danger js-review-request" data-action="reject">Reject</button>
            </div>
        <?php else: ?>
            <form method="post" style="max-width: 480px;" id="order-status-form" data-ajax-submit="true">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $order['id'] ?>">
                <div class="field">
                    <label for="status">Order status</label>
                    <select id="status" name="status">
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= e(order_status_label($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="supplier_id">Supplier fulfilling this order</label>
                    <select id="supplier_id" name="supplier_id">
                        <option value="">Not assigned</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= (int) $order['supplier_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$suppliers): ?>
                        <div class="hint">No suppliers are linked to this product yet — <a href="<?= url('/admin/suppliers/index.php') ?>">manage suppliers</a>.</div>
                    <?php endif; ?>
                </div>
                <?php if ($canSeeCost): ?>
                    <div class="field">
                        <label for="supplier_cost">Supplier cost (UGX)</label>
                        <input type="number" id="supplier_cost" name="supplier_cost" min="0" step="1000" value="<?= $order['supplier_cost'] !== null ? e($order['supplier_cost']) : '' ?>">
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label for="delivery_address">Delivery address</label>
                    <textarea id="delivery_address" name="delivery_address"><?= e($order['delivery_address']) ?></textarea>
                </div>
                <div class="field" id="cancellation-reason-field" style="<?= $order['status'] === 'cancelled' ? '' : 'display:none;' ?>">
                    <label for="cancellation_reason">Cancellation reason</label>
                    <textarea id="cancellation_reason" name="cancellation_reason" placeholder="Let the customer know why this order was cancelled"><?= e($order['cancellation_reason']) ?></textarea>
                </div>
                <div class="hint" style="margin-bottom: 14px;">The customer is emailed automatically whenever you change the status.</div>
                <button type="submit" class="btn btn-accent">Save changes</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h3>Payments on this order</h3>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= ucfirst(e($p['payment_type'])) ?></td>
                        <td><?= money($p['amount']) ?></td>
                        <td><?= e($p['method'] ?: '—') ?></td>
                        <td><span class="badge badge-<?= $p['status'] === 'verified' ? 'success' : ($p['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= e(payment_status_label($p['status'])) ?></span></td>
                        <td><?= display_date($p['created_at']) ?></td>
                        <td><a href="<?= url('/admin/payments/view.php?id=' . $p['id']) ?>" class="btn btn-outline btn-sm">Review</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$payments): ?>
                    <tr>
                        <td colspan="6" class="empty-state">No payments submitted for this order yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    var statusSelect = document.getElementById('status');
    if (statusSelect) {
        statusSelect.addEventListener('change', function() {
            document.getElementById('cancellation-reason-field').style.display = this.value === 'cancelled' ? '' : 'none';
        });
    }

    document.querySelectorAll('.js-review-request').forEach(function(button) {
        button.addEventListener('click', async function() {
            var reason = '';
            if (button.dataset.action === 'reject') {
                reason = window.prompt('Optional rejection reason:', '');
                if (reason === null) return;
            } else if (!window.confirm('Approve this order request?')) {
                return;
            }

            button.disabled = true;
            var body = new URLSearchParams({
                order_id: <?= (int) $order['id'] ?>,
                action: button.dataset.action,
                reason: reason
            });
            try {
                var response = await fetch('<?= url('/ajax/order-request.php') ?>', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': '<?= e(csrf_token()) ?>'
                    },
                    body: body
                });
                var result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'The request could not be updated.');
                window.location.reload();
            } catch (error) {
                window.alert(error.message);
                button.disabled = false;
            }
        });
    });
</script>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>