<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Restock Low Inventory';
$prefillProductId = (int) ($_GET['product_id'] ?? 0);
$prefillSupplierId = (int) ($_GET['supplier_id'] ?? 0);

// 1. Fetch all low stock or out of stock products
$lowStockStmt = $pdo->query(
    "SELECT p.id, p.name, p.brand, p.image, p.stock_quantity, p.reserved_quantity, p.low_stock_threshold,
            GREATEST(0, p.stock_quantity - p.reserved_quantity) AS available_stock,
            c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.status = 'active'
       AND p.stock_status <> 'made_to_order'
       AND (p.stock_quantity - p.reserved_quantity) <= p.low_stock_threshold
     ORDER BY available_stock ASC, p.name ASC"
);
$lowStockProducts = $lowStockStmt->fetchAll();
if ($prefillProductId > 0) {
    $lowStockProducts = array_values(array_filter($lowStockProducts, static fn (array $product): bool => (int) $product['id'] === $prefillProductId));
}

// 2. Fetch supplier mapping for these products
$productIds = array_column($lowStockProducts, 'id');
$supplierMapping = [];

if ($productIds) {
    $inClause = implode(',', array_map('intval', $productIds));
    $spStmt = $pdo->query(
        "SELECT sp.id AS supplier_product_id, sp.product_id, sp.supplier_id, sp.supplier_price, sp.lead_time_days, s.name AS supplier_name, s.status AS supplier_status, s.performance_status
         FROM supplier_products sp
         JOIN suppliers s ON s.id = sp.supplier_id
         WHERE sp.product_id IN ($inClause) AND s.status = 'active'
         ORDER BY CASE s.performance_status WHEN 'preferred' THEN 0 WHEN 'watchlist' THEN 1 ELSE 2 END ASC,
                  sp.supplier_price ASC,
                  sp.lead_time_days ASC,
                  s.name ASC"
    );
    while ($row = $spStmt->fetch()) {
        $supplierMapping[$row['product_id']][] = $row;
    }
}
// 3. Handle batch PO generation
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/admin/purchase_orders/low_stock.php');

    $selectedItems = $_POST['items'] ?? [];

    if (empty($selectedItems) || !is_array($selectedItems)) {
        $errors[] = 'Please select at least one low-stock product to reorder.';
    } else {
        $groupedBySupplier = [];
        foreach ($selectedItems as $productId => $itemData) {
            if (empty($itemData['selected'])) {
                continue;
            }
            $supplierId = (int) ($itemData['supplier_id'] ?? 0);
            $supplierProductId = (int) ($itemData['supplier_product_id'] ?? 0);
            $qty = (int) ($itemData['qty'] ?? 0);
            $cost = (float) ($itemData['cost'] ?? 0);

            if ($supplierId <= 0) {
                $errors[] = "Please select a valid supplier for product #$productId.";
                continue;
            }
            if ($supplierProductId > 0) {
                $sourceCheck = $pdo->prepare(
                    'SELECT id FROM supplier_products WHERE id = ? AND supplier_id = ? AND product_id = ?'
                );
                $sourceCheck->execute([$supplierProductId, $supplierId, $productId]);
                if (!$sourceCheck->fetch()) {
                    $supplierProductId = 0;
                }
            }
            if ($qty <= 0) {
                $errors[] = "Quantity for product #$productId must be at least 1.";
                continue;
            }
            if ($cost < 0) {
                $errors[] = "Unit cost for product #$productId cannot be negative.";
                continue;
            }

            $groupedBySupplier[$supplierId][] = [
                'product_id'              => (int) $productId,
                'source_supplier_product_id' => $supplierProductId > 0 ? $supplierProductId : null,
                'quantity'                => $qty,
                'cost'                    => $cost,
            ];
        }

        if (!$errors && empty($groupedBySupplier)) {
            $errors[] = 'Please check the box next to at least one product you wish to restock.';
        }

        if (!$errors) {
            $pdo->beginTransaction();
            try {
                $createdPoCount = 0;
                $firstPoId = null;

                foreach ($groupedBySupplier as $supId => $items) {
                    $poNumber = generate_po_number($pdo);
                    $stmt = $pdo->prepare(
                        "INSERT INTO purchase_orders (po_number, supplier_id, notes, created_by)
                         VALUES (?, ?, 'Auto-generated restock for low stock inventory', ?)"
                    );
                    $stmt->execute([$poNumber, $supId, $_SESSION['user_id']]);
                    $poId = (int) $pdo->lastInsertId();
                    if ($firstPoId === null) {
                        $firstPoId = $poId;
                    }

                    $insertItem = $pdo->prepare(
                        "INSERT INTO purchase_order_items (purchase_order_id, product_id, source_supplier_product_id, quantity_ordered, unit_cost, line_total)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );

                    foreach ($items as $it) {
                        $lineTotal = $it['quantity'] * $it['cost'];
                        $insertItem->execute([
                            $poId,
                            $it['product_id'],
                            $it['source_supplier_product_id'] ?: null,
                            $it['quantity'],
                            $it['cost'],
                            $lineTotal,
                        ]);
                    }

                    recalculate_po_totals($pdo, $poId);
                    log_activity($pdo, $_SESSION['user_id'], 'po_created', "Auto-generated restock PO $poNumber with " . count($items) . " item(s)");
                    $createdPoCount++;
                }

                $pdo->commit();

                if ($createdPoCount === 1 && $firstPoId) {
                    set_flash('success', "Draft purchase order created successfully from low-stock items.");
                    redirect('/admin/purchase_orders/view.php?id=' . $firstPoId);
                } else {
                    set_flash('success', "$createdPoCount draft purchase orders created successfully, grouped by supplier.");
                    redirect('/admin/purchase_orders/index.php');
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Failed to generate purchase orders: ' . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Restock Low Inventory</h2>
            <p class="muted mb-0">Review items at or below their low-stock threshold and generate draft purchase orders with 1 click.</p>
        </div>
        <div class="table-actions">
            <a href="<?= url('/admin/purchase_orders/index.php') ?>" class="btn btn-outline btn-sm">All purchase orders</a>
            <a href="<?= url('/admin/inventory/index.php') ?>" class="btn btn-outline btn-sm">Inventory view</a>
        </div>
    </div>

    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <?php if (!$lowStockProducts): ?>
            <div class="empty-state" style="padding: 40px 20px;">
                <i class="bi bi-check-circle text-success" style="font-size: 2.5rem; display:block; margin-bottom: 12px;"></i>
                <h3>All stock levels are healthy!</h3>
                <p class="muted">No products are currently at or below their low-stock threshold.</p>
                <a href="<?= url('/admin/inventory/index.php') ?>" class="btn btn-outline btn-sm" style="margin-top: 10px;">Return to Inventory</a>
            </div>
        <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>

                <div class="d-flex justify-content-between align-items-center mb-3" style="flex-wrap: wrap; gap: 10px;">
                    <div>
                        <strong>Found <?= count($lowStockProducts) ?> product(s) needing replenishment.</strong>
                        <span class="muted">Items ordered from the same supplier will be bundled into a single PO.</span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-outline btn-sm" id="toggleAllBtn">Select all</button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 40px;"></th>
                                <th>Product</th>
                                <th>Current Stock</th>
                                <th>Threshold</th>
                                <th style="width: 220px;">Supplier</th>
                                <th style="width: 110px;">Order Qty</th>
                                <th style="width: 150px;">Est. Unit Cost</th>
                            </tr>
                        </thead>
                        <tbody>

                            <?php foreach ($lowStockProducts as $p):
                                $pid = (int) $p['id'];
                                $suppliers = $supplierMapping[$pid] ?? [];
                                $defaultSupplier = null;
                                if ($prefillSupplierId > 0) {
                                    foreach ($suppliers as $supplier) {
                                        if ((int) $supplier['supplier_id'] === $prefillSupplierId) {
                                            $defaultSupplier = $supplier;
                                            break;
                                        }
                                    }
                                }
                                if ($defaultSupplier === null && $suppliers) {
                                    $defaultSupplier = $suppliers[0];
                                }
                                $defCost = $defaultSupplier ? (float) $defaultSupplier['supplier_price'] : 0;
                                $suggestedQty = max(5, ((int) $p['low_stock_threshold'] * 2) - (int) $p['available_stock']);
                                $checkedState = $defaultSupplier ? 'checked' : '';
                                if ($prefillProductId > 0 && $defaultSupplier && !isset($_POST['items'])) {
                                    $checkedState = 'checked';
                                }
                            ?>
                                <tr>
                                    <td>
                                        <input type="checkbox"
                                               name="items[<?= $pid ?>][selected]"
                                               value="1"
                                               class="item-checkbox"
                                               <?= $checkedState ?>>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($p['image']): ?>
                                                <img src="<?= url('/uploads/products/' . e($p['image'])) ?>" alt="" style="width: 40px; height: 40px; object-fit: cover; border-radius: 8px;">
                                            <?php endif; ?>
                                            <div>
                                                <strong><?= e($p['name']) ?></strong>
                                                <?php if ($p['brand']): ?>
                                                    <small class="muted d-block"><?= e($p['brand']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ((int) $p['available_stock'] <= 0): ?>
                                            <span class="badge badge-danger">0 available</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning"><?= (int) $p['available_stock'] ?> available</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int) $p['low_stock_threshold'] ?> units</td>
                                    <td>
                                        <?php if ($suppliers): ?>
                                            <select name="items[<?= $pid ?>][supplier_id]" class="supplier-selector" data-pid="<?= $pid ?>" style="width: 100%;">
                                                <?php foreach ($suppliers as $s): ?>
                                                    <option value="<?= (int) $s['supplier_id'] ?>"
                                                            data-cost="<?= (float) $s['supplier_price'] ?>"
                                                            data-supplier-product-id="<?= (int) $s['supplier_product_id'] ?>"
                                                            <?= ($defaultSupplier && (int) $s['supplier_id'] === (int) $defaultSupplier['supplier_id']) ? 'selected' : '' ?>>
                                                        <?= e($s['supplier_name']) ?> <?= $s['supplier_price'] ? ' — ' . money($s['supplier_price']) : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="hidden"
                                                   name="items[<?= $pid ?>][supplier_product_id]"
                                                   id="supplier-product-<?= $pid ?>"
                                                   value="<?= (int) ($defaultSupplier['supplier_product_id'] ?? 0) ?>">
                                        <?php else: ?>
                                            <span class="text-danger" style="font-size: 0.85rem;">
                                                <i class="bi bi-exclamation-triangle"></i> No linked supplier
                                            </span>
                                            <div style="font-size: 0.78rem;">
                                                <a href="<?= url('/admin/suppliers/index.php') ?>" target="_blank">Link supplier</a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="number"
                                               name="items[<?= $pid ?>][qty]"
                                               value="<?= (int) $suggestedQty ?>"
                                               min="1"
                                               style="width: 90px;"
                                               <?= $defaultSupplier ? '' : 'disabled' ?>>
                                    </td>
                                    <td>
                                        <input type="number"
                                               name="items[<?= $pid ?>][cost]"
                                               id="cost-<?= $pid ?>"
                                               value="<?= (float) $defCost ?>"
                                               min="0"
                                               step="1000"
                                               placeholder="0"
                                               style="width: 130px;"
                                               <?= $defaultSupplier ? '' : 'disabled' ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="panel-body flush" style="padding-top: 20px;">
                    <button type="submit" class="btn btn-accent">
                        <i class="bi bi-file-earmark-plus me-1"></i> Generate Draft Purchase Orders
                    </button>
                    <a href="<?= url('/admin/purchase_orders/index.php') ?>" class="btn btn-outline ms-2">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggleBtn = document.getElementById('toggleAllBtn');
    if (toggleBtn) {
        var allChecked = true;
        toggleBtn.addEventListener('click', function() {
            var checkboxes = document.querySelectorAll('.item-checkbox');
            allChecked = !allChecked;
            checkboxes.forEach(function(cb) {
                cb.checked = allChecked;
            });
            toggleBtn.textContent = allChecked ? 'Deselect all' : 'Select all';
        });
    }

    document.querySelectorAll('.supplier-selector').forEach(function(sel) {
        sel.addEventListener('change', function() {
            var pid = this.dataset.pid;
            var opt = this.options[this.selectedIndex];
            var cost = opt.dataset.cost;
            var supplierProductId = opt.dataset.supplierProductId;
            var costInput = document.getElementById('cost-' + pid);
            var supplierProductInput = document.getElementById('supplier-product-' + pid);
            if (costInput && cost !== undefined && cost !== '') {
                costInput.value = cost;
            }
            if (supplierProductInput) {
                supplierProductInput.value = supplierProductId !== undefined ? supplierProductId : '';
            }
        });
    });
});
</script>
<?php
include __DIR__ . '/../../includes/admin_layout_end.php';

