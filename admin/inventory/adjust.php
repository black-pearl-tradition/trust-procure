<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/functions.php';
require_staff();

$id = (int) ($_GET['id'] ?? $_POST['product_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, name, stock_quantity, stock_status FROM products WHERE id = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    set_flash('error', 'Product not found.');
    redirect('/admin/inventory/index.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/admin/inventory/index.php');
    $direction = $_POST['direction'] ?? '';
    $quantity = max(0, (int) ($_POST['quantity'] ?? 0));
    $note = trim($_POST['note'] ?? '');

    if (!in_array($direction, ['add', 'remove'], true) || $quantity < 1) {
        $errors[] = 'Enter a quantity and choose whether to add or remove stock.';
    }
    if ($note === '') {
        $errors[] = 'Enter a reason for this adjustment.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT name, stock_quantity, reserved_quantity FROM products WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $current = $lock->fetch();
            $change = $direction === 'add' ? $quantity : -$quantity;
            $newQuantity = (int) $current['stock_quantity'] + $change;

            if ($newQuantity < 0) {
                throw new RuntimeException('Cannot remove more stock than is currently available.');
            }
            if ($newQuantity < (int) $current['reserved_quantity']) {
                throw new RuntimeException('Cannot reduce stock below the quantity reserved for approved orders.');
            }

            $update = $pdo->prepare(
                "UPDATE products SET stock_quantity = ?, stock_status = CASE WHEN ? > 0 THEN 'in_stock' ELSE 'out_of_stock' END WHERE id = ?"
            );
            $update->execute([$newQuantity, $newQuantity, $id]);
            notify_low_stock($pdo, $id, $newQuantity, (int) $product['low_stock_threshold']);
            record_inventory_movement($pdo, $id, $_SESSION['user_id'], 'adjustment', $change, null, null, $note);
            $pdo->commit();

            log_activity($pdo, $_SESSION['user_id'], 'inventory_adjusted', "Adjusted {$current['name']} stock by {$change}");
            set_flash('success', 'Inventory adjusted and movement recorded.');
            redirect('/admin/inventory/index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Adjust Inventory';
include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel" style="max-width: 640px;">
    <div class="panel-head">
        <h2>Adjust inventory</h2>
    </div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endforeach; ?>
        <div class="detail-grid" style="margin-bottom: 24px;">
            <div class="detail-item">
                <div class="label">Product</div>
                <div class="value"><?= e($product['name']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label">Current quantity</div>
                <div class="value"><?= (int) $product['stock_quantity'] ?> units</div>
            </div>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
            <div class="field-row">
                <div class="field">
                    <label for="direction">Adjustment</label>
                    <select id="direction" name="direction" required>
                        <option value="add">Add stock</option>
                        <option value="remove">Remove stock</option>
                    </select>
                </div>
                <div class="field">
                    <label for="quantity">Quantity</label>
                    <input type="number" id="quantity" name="quantity" min="1" step="1" required>
                </div>
            </div>
            <div class="field">
                <label for="note">Reason</label>
                <textarea id="note" name="note" required placeholder="e.g. damaged unit removed, stock count correction"></textarea>
            </div>
            <div class="table-actions">
                <button type="submit" class="btn btn-accent">Save adjustment</button>
                <a href="<?= url('/admin/inventory/index.php') ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>