<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$code = trim((string) ($_GET['code'] ?? $_POST['code'] ?? ''));
$delivery = null;
$items = [];
$serialsByDeliveryItem = [];
$deliveryDocument = null;
$pageError = '';

if ($code !== '') {
    if (!preg_match('/^\d{10}$/', $code)) {
        $pageError = 'Enter a valid 10-digit delivery code.';
    } else {
        $deliveryStmt = $pdo->prepare(
            "SELECT d.*, po.po_number, po.status AS po_status, po.supplier_response,
                    s.name AS supplier_name, u.full_name AS submitted_by_name
             FROM supplier_deliveries d
             JOIN purchase_orders po ON po.id = d.purchase_order_id
             JOIN suppliers s ON s.id = d.supplier_id
             JOIN users u ON u.id = d.submitted_by
             WHERE d.delivery_code = ?
               AND d.status = 'submitted'
               AND po.status <> 'cancelled'
               AND po.supplier_response <> 'declined'"
        );
        $deliveryStmt->execute([$code]);
        $delivery = $deliveryStmt->fetch() ?: null;

        if (!$delivery) {
            $pageError = 'No delivery awaiting verification matches that code.';
        } else {
            $itemsStmt = $pdo->prepare(
                'SELECT di.*, poi.product_id, poi.quantity_ordered, poi.quantity_received AS accepted_so_far,
                        p.name AS product_name, p.sku, p.barcode, p.specifications, p.track_serial_numbers
                 FROM supplier_delivery_items di
                 JOIN purchase_order_items poi ON poi.id = di.purchase_order_item_id
                 JOIN products p ON p.id = poi.product_id
                 JOIN supplier_products sp ON sp.product_id = poi.product_id AND sp.supplier_id = ?
                 WHERE di.delivery_id = ?
                 ORDER BY poi.id'
            );
            $itemsStmt->execute([(int) $delivery['supplier_id'], (int) $delivery['id']]);
            $items = $itemsStmt->fetchAll();
            $serialStmt = $pdo->prepare(
                'SELECT ds.*
                 FROM supplier_delivery_serials ds
                 JOIN supplier_delivery_items di ON di.id = ds.delivery_item_id
                 WHERE di.delivery_id = ?
                 ORDER BY ds.id'
            );
            $serialStmt->execute([(int) $delivery['id']]);
            foreach ($serialStmt->fetchAll() as $serial) {
                $serialsByDeliveryItem[(int) $serial['delivery_item_id']][] = $serial;
            }

            $docStmt = $pdo->prepare(
                "SELECT id, original_name FROM purchase_order_documents
                 WHERE delivery_id = ? AND document_type = 'delivery_note'
                 ORDER BY id DESC LIMIT 1"
            );
            $docStmt->execute([(int) $delivery['id']]);
            $deliveryDocument = $docStmt->fetch() ?: null;

            $deliveryItemCount = $pdo->prepare('SELECT COUNT(*) FROM supplier_delivery_items WHERE delivery_id = ?');
            $deliveryItemCount->execute([(int) $delivery['id']]);
            if ((int) $deliveryItemCount->fetchColumn() !== count($items)) {
                $pageError = 'A declared product is no longer linked to this supplier. Contact an administrator before verifying this delivery.';
                $delivery = null;
                $items = [];
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $delivery !== null) {
    verify_csrf('/admin/purchase_orders/receive.php?code=' . rawurlencode($code));
    $receiptInput = $_POST['receipt'] ?? null;
    if (!is_array($receiptInput) || !$receiptInput) {
        set_flash('error', 'Enter inspection results for the declared items.');
        redirect('/admin/purchase_orders/receive.php?code=' . rawurlencode($code));
    }

    $discrepancyTypes = ['none', 'short_delivery', 'damaged', 'wrong_item', 'other'];
    $pdo->beginTransaction();
    try {
        $lockedDeliveryStmt = $pdo->prepare(
            "SELECT d.*, po.po_number, po.status AS po_status, po.supplier_response
             FROM supplier_deliveries d
             JOIN purchase_orders po ON po.id = d.purchase_order_id
             WHERE d.delivery_code = ? FOR UPDATE"
        );
        $lockedDeliveryStmt->execute([$code]);
        $lockedDelivery = $lockedDeliveryStmt->fetch();
        if (
            !$lockedDelivery
            || $lockedDelivery['status'] !== 'submitted'
            || $lockedDelivery['po_status'] === 'cancelled'
            || $lockedDelivery['supplier_response'] === 'declined'
        ) {
            throw new RuntimeException('This delivery is no longer awaiting verification.');
        }

        $lockedItemsStmt = $pdo->prepare(
            'SELECT di.*, poi.product_id, poi.quantity_ordered, poi.quantity_received AS accepted_so_far,
                    poi.order_id, p.name AS product_name, p.sku, p.barcode, p.specifications,
                    p.track_serial_numbers, p.stock_quantity, p.low_stock_threshold
             FROM supplier_delivery_items di
             JOIN purchase_order_items poi ON poi.id = di.purchase_order_item_id
             JOIN products p ON p.id = poi.product_id
             JOIN supplier_products sp ON sp.product_id = poi.product_id AND sp.supplier_id = ?
             WHERE di.delivery_id = ?
             ORDER BY poi.id FOR UPDATE'
        );
        $lockedItemsStmt->execute([(int) $lockedDelivery['supplier_id'], (int) $lockedDelivery['id']]);
        $lockedItems = $lockedItemsStmt->fetchAll();
        $itemsById = array_column($lockedItems, null, 'id');
        $lockedSerialStmt = $pdo->prepare(
            'SELECT ds.*
             FROM supplier_delivery_serials ds
             JOIN supplier_delivery_items di ON di.id = ds.delivery_item_id
             WHERE di.delivery_id = ?
             ORDER BY ds.id FOR UPDATE'
        );
        $lockedSerialStmt->execute([(int) $lockedDelivery['id']]);
        $lockedSerialsByItem = [];
        foreach ($lockedSerialStmt->fetchAll() as $serial) {
            $lockedSerialsByItem[(int) $serial['delivery_item_id']][] = $serial;
        }
        $deliveryItemCount = $pdo->prepare('SELECT COUNT(*) FROM supplier_delivery_items WHERE delivery_id = ?');
        $deliveryItemCount->execute([(int) $lockedDelivery['id']]);
        if ((int) $deliveryItemCount->fetchColumn() !== count($lockedItems)) {
            throw new RuntimeException('A declared product is no longer linked to this supplier.');
        }

        if (count($receiptInput) !== count($itemsById)) {
            throw new RuntimeException('Submit an inspection result for every item in the delivery notice.');
        }

        $hasVariance = false;
        foreach ($receiptInput as $deliveryItemId => $receipt) {
            if (!ctype_digit((string) $deliveryItemId) || !isset($itemsById[(int) $deliveryItemId]) || !is_array($receipt)) {
                throw new RuntimeException('The inspection contains an item outside this delivery notice.');
            }
            $item = $itemsById[(int) $deliveryItemId];
            $itemSerials = $lockedSerialsByItem[(int) $item['id']] ?? [];
            $trackSerials = (bool) $item['track_serial_numbers'] && count($itemSerials) > 0;
            $serialStatusInput = [];
            if ($trackSerials) {
                if (count($itemSerials) !== (int) $item['declared_quantity']) {
                    throw new RuntimeException('The serial manifest for ' . $item['product_name'] . ' is incomplete. Do not verify this delivery until it is corrected.');
                }
                $submittedSerialStatuses = $receipt['serials'] ?? null;
                if (!is_array($submittedSerialStatuses) || count($submittedSerialStatuses) !== count($itemSerials)) {
                    throw new RuntimeException('Select a verification result for every ' . $item['product_name'] . ' unit identifier.');
                }
                $received = $accepted = $rejected = 0;
                foreach ($itemSerials as $serial) {
                    $serialId = (int) $serial['id'];
                    $serialStatus = $submittedSerialStatuses[$serialId] ?? '';
                    if (!in_array($serialStatus, ['accepted', 'rejected', 'not_received'], true)) {
                        throw new RuntimeException('Choose accepted, rejected, or not received for unit ' . $serial['serial_number'] . '.');
                    }
                    $serialStatusInput[$serialId] = $serialStatus;
                    if ($serialStatus === 'accepted') {
                        $accepted++;
                        $received++;
                    } elseif ($serialStatus === 'rejected') {
                        $rejected++;
                        $received++;
                    }
                }
            } else {
                foreach (['received', 'accepted', 'rejected'] as $quantityKey) {
                    if (
                        !isset($receipt[$quantityKey])
                        || !is_scalar($receipt[$quantityKey])
                        || !preg_match('/^\d+$/', (string) $receipt[$quantityKey])
                        || (float) $receipt[$quantityKey] > 4294967295
                    ) {
                        throw new RuntimeException('Enter whole-number received, accepted, and rejected quantities.');
                    }
                }
                $received = (int) $receipt['received'];
                $accepted = (int) $receipt['accepted'];
                $rejected = (int) $receipt['rejected'];
            }
            $discrepancy = (string) ($receipt['discrepancy'] ?? '');
            $inspectionNote = mb_substr(trim((string) ($receipt['note'] ?? '')), 0, 500);
            $remaining = (int) $item['quantity_ordered'] - (int) $item['accepted_so_far'];

            if (!in_array($discrepancy, $discrepancyTypes, true)) {
                throw new RuntimeException('Choose a valid discrepancy reason.');
            }
            if ($accepted + $rejected !== $received) {
                throw new RuntimeException('For ' . $item['product_name'] . ', accepted plus rejected must equal physically received.');
            }
            if ($accepted > $remaining) {
                throw new RuntimeException('Accepted quantity for ' . $item['product_name'] . ' exceeds the remaining PO quantity of ' . $remaining . '.');
            }
            if ($discrepancy === 'none' && ($received !== (int) $item['declared_quantity'] || $rejected > 0)) {
                throw new RuntimeException('Select a discrepancy reason for the received or rejected quantity difference on ' . $item['product_name'] . '.');
            }
            if ($discrepancy === 'short_delivery' && $received >= (int) $item['declared_quantity']) {
                throw new RuntimeException('Short delivery can only be selected when fewer units arrived than were declared.');
            }
            if ($discrepancy === 'damaged' && $rejected === 0) {
                throw new RuntimeException('Enter a rejected quantity when reporting damaged goods.');
            }
            if (($discrepancy === 'other' || $discrepancy === 'wrong_item') && $inspectionNote === '') {
                throw new RuntimeException('Add an inspection note for ' . $discrepancy . ' on ' . $item['product_name'] . '.');
            }
            if ($discrepancy === 'wrong_item' && $accepted > 0) {
                throw new RuntimeException('Wrong items cannot be accepted into stock.');
            }
            if ($received !== (int) $item['declared_quantity'] || $rejected > 0 || $discrepancy !== 'none') {
                $hasVariance = true;
            }

            $pdo->prepare(
                'UPDATE supplier_delivery_items
                 SET received_quantity = ?, accepted_quantity = ?, rejected_quantity = ?,
                     discrepancy_type = ?, inspection_note = ?
                 WHERE id = ?'
            )->execute([
                $received,
                $accepted,
                $rejected,
                $discrepancy,
                $inspectionNote !== '' ? $inspectionNote : null,
                (int) $item['id'],
            ]);
            if ($trackSerials) {
                $updateSerialStmt = $pdo->prepare(
                    'UPDATE supplier_delivery_serials
                     SET inspection_status = ?
                     WHERE id = ? AND delivery_item_id = ?'
                );
                foreach ($serialStatusInput as $serialId => $serialStatus) {
                    $updateSerialStmt->execute([$serialStatus, $serialId, (int) $item['id']]);
                    if ($updateSerialStmt->rowCount() !== 1) {
                        throw new RuntimeException('A unit identifier changed while the delivery was being verified. Please retry.');
                    }
                }
            }

            if ($accepted > 0) {
                $poiUpdate = $pdo->prepare(
                    'UPDATE purchase_order_items
                     SET quantity_received = quantity_received + ?
                     WHERE id = ? AND quantity_received + ? <= quantity_ordered'
                );
                $poiUpdate->execute([$accepted, (int) $item['purchase_order_item_id'], $accepted]);
                if ($poiUpdate->rowCount() !== 1) {
                    throw new RuntimeException('Accepted quantity for ' . $item['product_name'] . ' exceeds the current PO balance.');
                }

                $pdo->prepare(
                    "UPDATE products SET stock_quantity = stock_quantity + ?, stock_status = 'in_stock' WHERE id = ?"
                )->execute([$accepted, (int) $item['product_id']]);
                $stockStmt = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = ?');
                $stockStmt->execute([(int) $item['product_id']]);
                notify_low_stock(
                    $pdo,
                    (int) $item['product_id'],
                    (int) $stockStmt->fetchColumn(),
                    (int) $item['low_stock_threshold']
                );
                record_inventory_movement(
                    $pdo,
                    (int) $item['product_id'],
                    (int) $_SESSION['user_id'],
                    'received',
                    $accepted,
                    (int) $lockedDelivery['purchase_order_id'],
                    !empty($item['order_id']) ? (int) $item['order_id'] : null,
                    'Accepted on GRN-' . (int) $lockedDelivery['id'] . ' against PO #' . $lockedDelivery['po_number']
                );

                if (!empty($item['order_id'])) {
                    $orderStmt = $pdo->prepare('SELECT user_id FROM orders WHERE id = ?');
                    $orderStmt->execute([(int) $item['order_id']]);
                    $orderUserId = $orderStmt->fetchColumn();
                    if ($orderUserId !== false) {
                        record_order_event(
                            $pdo,
                            (int) $item['order_id'],
                            (int) $_SESSION['user_id'],
                            'stock_received',
                            'Stock received from supplier',
                            $accepted . ' unit(s) of ' . $item['product_name'] . ' accepted on GRN-' . (int) $lockedDelivery['id']
                        );
                        create_notification(
                            $pdo,
                            (int) $orderUserId,
                            'Your requested product has arrived',
                            $accepted . ' unit(s) of ' . $item['product_name'] . ' have been accepted and are being prepared for fulfillment.',
                            '/user/orders/view.php?id=' . (int) $item['order_id']
                        );
                    }
                }
            }
        }

        $pdo->prepare(
            "UPDATE supplier_deliveries SET status = 'verified', verified_by = ?, verified_at = NOW() WHERE id = ?"
        )->execute([(int) $_SESSION['user_id'], (int) $lockedDelivery['id']]);

        $remainingStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = ? AND quantity_received < quantity_ordered'
        );
        $remainingStmt->execute([(int) $lockedDelivery['purchase_order_id']]);
        $stillOpen = (int) $remainingStmt->fetchColumn();
        $receivedStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(quantity_received), 0) FROM purchase_order_items WHERE purchase_order_id = ?'
        );
        $receivedStmt->execute([(int) $lockedDelivery['purchase_order_id']]);
        $receivedTotal = (int) $receivedStmt->fetchColumn();
        $poStatus = $stillOpen === 0 ? 'received' : ($receivedTotal > 0 ? 'partially_received' : $lockedDelivery['po_status']);
        $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')
            ->execute([$poStatus, (int) $lockedDelivery['purchase_order_id']]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash(
            'error',
            $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'The Goods Received Note could not be saved. Check the database migration and try again.'
        );
        redirect('/admin/purchase_orders/receive.php?code=' . rawurlencode($code));
    }

    $grnNumber = 'GRN-' . (int) $lockedDelivery['id'];
    log_activity(
        $pdo,
        (int) $_SESSION['user_id'],
        'supplier_delivery_verified',
        'Verified ' . $grnNumber . ' for purchase order #' . $lockedDelivery['po_number']
    );
    notify_supplier(
        (int) $lockedDelivery['supplier_id'],
        $hasVariance ? 'Delivery verified with discrepancies' : 'Delivery verified',
        $grnNumber . ' for purchase order ' . $lockedDelivery['po_number']
            . ($hasVariance ? ' was verified with quantity or condition discrepancies. Review the delivery record.' : ' was verified and accepted.'),
        '/supplier/purchase_orders/view.php?id=' . (int) $lockedDelivery['purchase_order_id']
    );
    set_flash(
        $hasVariance ? 'warning' : 'success',
        $grnNumber . ' recorded. Accepted quantities were added to inventory.'
    );
    redirect('/admin/purchase_orders/view.php?id=' . (int) $lockedDelivery['purchase_order_id']);
}

$pageTitle = 'Verify Delivery';
include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Verify supplier delivery</h2>
            <p class="muted mb-0">Scan or enter the 10-digit code from the supplier’s delivery notice.</p>
        </div>
    </div>
    <div class="panel-body">
        <form method="get" action="<?= url('/admin/purchase_orders/receive.php') ?>" style="max-width: 480px;">
            <div class="field">
                <label for="delivery_code">Delivery code</label>
                <input type="text" id="delivery_code" name="code" value="<?= e($code) ?>" inputmode="numeric"
                    pattern="[0-9]{10}" maxlength="10" autocomplete="off" autofocus required>
            </div>
            <button type="submit" class="btn btn-accent">Load delivery notice</button>
        </form>
        <?php if ($pageError !== ''): ?>
            <div class="alert alert-danger" style="margin-top: 16px;"><?= e($pageError) ?></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($delivery !== null): ?>
    <div class="panel">
        <div class="panel-head">
            <div>
                <h3><?= e($delivery['po_number']) ?> — <?= e($delivery['supplier_name']) ?></h3>
                <p class="muted mb-0">
                    Submitted <?= display_date($delivery['submitted_at'], 'd M Y H:i') ?>
                    by <?= e($delivery['submitted_by_name']) ?>
                    <?php if ($delivery['tracking_reference']): ?> · Ref <?= e($delivery['tracking_reference']) ?><?php endif; ?>
                </p>
            </div>
            <span class="badge badge-warning">Pending verification</span>
        </div>
        <div class="panel-body">
            <?php if ($delivery['expected_delivery_date']): ?>
                <p><strong>Expected arrival:</strong> <?= display_date($delivery['expected_delivery_date'], 'd M Y') ?></p>
            <?php endif; ?>
            <?php if ($delivery['supplier_notes']): ?>
                <p><strong>Supplier dispatch notes:</strong> <?= e($delivery['supplier_notes']) ?></p>
            <?php endif; ?>
            <?php if ($deliveryDocument): ?>
                <p><strong>Delivery note:</strong> <?= e($deliveryDocument['original_name']) ?>
                    <a class="btn btn-outline btn-sm" href="<?= url('/po-document.php?id=' . (int) $deliveryDocument['id']) ?>">View document</a>
                </p>
            <?php endif; ?>
            <p class="muted">Count physical goods. Accepted quantities enter sellable stock; rejected quantities are recorded but do not update stock. GRN: <strong>GRN-<?= (int) $delivery['id'] ?></strong></p>
            <form method="post" action="<?= url('/admin/purchase_orders/receive.php?code=' . rawurlencode($code)) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="code" value="<?= e($code) ?>">
                <?php foreach ($items as $item): ?>
                    <div class="panel" style="margin: 18px 0; border: 1px solid var(--border);">
                        <div class="panel-head">
                            <div>
                                <h4><?= e($item['product_name']) ?></h4>
                                <p class="muted mb-0">
                                    Supplier declared: <?= (int) $item['declared_quantity'] ?> ·
                                    PO remaining before this delivery: <?= max(0, (int) $item['quantity_ordered'] - (int) $item['accepted_so_far']) ?>
                                </p>
                                <?php if ($item['sku'] || $item['barcode']): ?>
                                    <p class="muted mb-0">SKU <?= e($item['sku'] ?: '—') ?> · product barcode <?= e($item['barcode'] ?: '—') ?></p>
                                <?php endif; ?>
                                <?php if ($item['specifications']): ?><p class="muted mb-0">Product details: <?= e($item['specifications']) ?></p><?php endif; ?>
                                <?php if ($item['delivery_details']): ?><p><strong>Delivery details:</strong> <?= e($item['delivery_details']) ?></p><?php endif; ?>
                            </div>
                        </div>
                        <div class="panel-body">
                            <?php $itemSerials = $serialsByDeliveryItem[(int) $item['id']] ?? []; ?>
                            <?php if ($item['track_serial_numbers'] && $itemSerials): ?>
                                <p class="muted">Inspect each unit and choose its result. Received, accepted, and rejected quantities are calculated from these selections.</p>
                                <?php foreach ($itemSerials as $serial): ?>
                                    <div class="field-row" style="align-items: end;">
                                        <div class="field">
                                            <label>Unit serial / barcode</label>
                                            <code><?= e($serial['serial_number']) ?></code>
                                        </div>
                                        <div class="field">
                                            <label for="serial_<?= (int) $serial['id'] ?>">Inspection result</label>
                                            <select id="serial_<?= (int) $serial['id'] ?>" name="receipt[<?= (int) $item['id'] ?>][serials][<?= (int) $serial['id'] ?>]" required>
                                                <option value="">Choose result</option>
                                                <option value="accepted">Accepted into stock</option>
                                                <option value="rejected">Received but rejected</option>
                                                <option value="not_received">Not physically received</option>
                                            </select>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php if ($item['track_serial_numbers']): ?>
                                    <div class="alert alert-warning">This notice predates serial tracking and contains no unit identifiers. Verify quantities using the standard count fields.</div>
                                <?php endif; ?>
                                <div class="field-row">
                                    <div class="field">
                                        <label for="received_<?= (int) $item['id'] ?>">Physically received</label>
                                        <input type="number" id="received_<?= (int) $item['id'] ?>" name="receipt[<?= (int) $item['id'] ?>][received]"
                                            min="0" value="<?= (int) $item['declared_quantity'] ?>" required>
                                    </div>
                                    <div class="field">
                                        <label for="accepted_<?= (int) $item['id'] ?>">Accepted into stock</label>
                                        <input type="number" id="accepted_<?= (int) $item['id'] ?>" name="receipt[<?= (int) $item['id'] ?>][accepted]"
                                            min="0" max="<?= max(0, (int) $item['quantity_ordered'] - (int) $item['accepted_so_far']) ?>"
                                            value="<?= min((int) $item['declared_quantity'], max(0, (int) $item['quantity_ordered'] - (int) $item['accepted_so_far'])) ?>" required>
                                    </div>
                                    <div class="field">
                                        <label for="rejected_<?= (int) $item['id'] ?>">Rejected / damaged</label>
                                        <input type="number" id="rejected_<?= (int) $item['id'] ?>" name="receipt[<?= (int) $item['id'] ?>][rejected]"
                                            min="0" value="0" required>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="field-row">
                                <div class="field">
                                    <label for="discrepancy_<?= (int) $item['id'] ?>">Discrepancy</label>
                                    <select id="discrepancy_<?= (int) $item['id'] ?>" name="receipt[<?= (int) $item['id'] ?>][discrepancy]" required>
                                        <option value="none">No discrepancy</option>
                                        <option value="short_delivery">Short delivery</option>
                                        <option value="damaged">Damaged / rejected</option>
                                        <option value="wrong_item">Wrong item</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="note_<?= (int) $item['id'] ?>">Inspection notes</label>
                                    <input type="text" id="note_<?= (int) $item['id'] ?>" name="receipt[<?= (int) $item['id'] ?>][note]" maxlength="500">
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-accent" data-confirm="Confirm this Goods Received Note? Accepted quantities will be added to inventory.">
                    Confirm GRN and update inventory
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
