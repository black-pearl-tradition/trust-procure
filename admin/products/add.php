<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Add Product';
$errors = [];
$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();

$name = $brand = $description = $specifications = '';
$categoryId = $price = 0;
$isHighEnd = false;
$depositPct = 70;
$status = 'active';
$stockStatus = 'in_stock';
$stockQuantity = 0;
$lowStockThreshold = 5;
$isFeatured = false;
$isNewArrival = false;
$sku = '';
$barcode = '';
$trackSerialNumbers = false;
$codesAvailable = product_codes_available($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/admin/products/index.php');
    $sku = trim($_POST['sku'] ?? '');
    $barcode = trim($_POST['barcode'] ?? '');
    $trackSerialNumbers = isset($_POST['track_serial_numbers']);
    $name = trim($_POST['name'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $specifications = trim($_POST['specifications'] ?? '');
    $price = (float) ($_POST['price'] ?? 0);
    $isHighEnd = isset($_POST['is_high_end']);
    $depositPct = (int) ($_POST['deposit_percentage'] ?? 70);
    $status = $_POST['status'] ?? 'active';
    $stockStatus = $_POST['stock_status'] ?? 'in_stock';
    $stockQuantity = max(0, (int) ($_POST['stock_quantity'] ?? 0));
    $lowStockThreshold = max(0, (int) ($_POST['low_stock_threshold'] ?? 5));
    $isFeatured = isset($_POST['is_featured']);
    $isNewArrival = isset($_POST['is_new_arrival']);

    if (!in_array($stockStatus, ['in_stock', 'out_of_stock', 'made_to_order'], true)) {
        $stockStatus = 'in_stock';
    }

    if ($name === '') $errors[] = 'Please enter a product name.';
    if ($price <= 0) $errors[] = 'Please enter a valid price.';
    if ($isHighEnd && ($depositPct < 1 || $depositPct > 100)) $errors[] = 'Deposit percentage must be between 1 and 100.';

    // Checked before the uploads so a rejected code cannot leave an orphaned
    // image on disk.
    $skuValue = null;
    $barcodeValue = null;
    if ($codesAvailable) {
        [$skuValue, $skuError] = normalise_product_code($pdo, $sku, 'SKU');
        if ($skuError !== '') $errors[] = $skuError;
        [$barcodeValue, $barcodeError] = normalise_product_code($pdo, $barcode, 'Barcode');
        if ($barcodeError !== '') $errors[] = $barcodeError;
    }

    $imageName = null;
    $galleryNames = [];
    if (!$errors) {
        try {
            $imageName = handle_upload($_FILES['image'] ?? [], 'products');
            $galleryNames = handle_multi_upload($_FILES['gallery'] ?? [], 'products');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        $slug = slugify($name) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

        // The scanning columns only exist once config/migrations.sql has run, so
        // they are appended rather than assumed.
        $codeColumns = $codesAvailable ? ', sku, barcode' : '';
        $codePlaceholders = $codesAvailable ? ', ?, ?' : '';
        $stmt = $pdo->prepare(
            'INSERT INTO products (category_id, name, slug, brand, description, specifications, price, image, stock_quantity, low_stock_threshold, is_high_end, deposit_percentage, stock_status, is_featured, is_new_arrival, status, track_serial_numbers'
            . $codeColumns . ')
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?' . $codePlaceholders . ')'
        );
        $params = [
            $categoryId ?: null,
            $name,
            $slug,
            $brand,
            $description,
            $specifications,
            $price,
            $imageName,
            $stockQuantity,
            $lowStockThreshold,
            $isHighEnd ? 1 : 0,
            $isHighEnd ? $depositPct : 100,
            $stockStatus,
            $isFeatured ? 1 : 0,
            $isNewArrival ? 1 : 0,
            $status,
            $trackSerialNumbers ? 1 : 0,
        ];
        if ($codesAvailable) {
            $params[] = $skuValue;
            $params[] = $barcodeValue;
        }
        $stmt->execute($params);
        $newProductId = (int) $pdo->lastInsertId();

        if ($stockQuantity > 0) {
            record_inventory_movement(
                $pdo,
                $newProductId,
                $_SESSION['user_id'],
                'adjustment',
                $stockQuantity,
                null,
                null,
                'Opening stock entered when product was created'
            );
        }

        if ($galleryNames) {
            $galleryStmt = $pdo->prepare('INSERT INTO product_images (product_id, image, sort_order) VALUES (?, ?, ?)');
            foreach ($galleryNames as $i => $filename) {
                $galleryStmt->execute([$newProductId, $filename, $i]);
            }
        }

        log_activity($pdo, $_SESSION['user_id'], 'product_created', "Created product: $name");
        set_flash('success', 'Product added.');
        redirect('/admin/products/index.php');
    }
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>Add product</h2>
    </div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <form method="post" enctype="multipart/form-data" style="max-width: 640px;">
            <?= csrf_field() ?>
            <div class="field-row">
                <div class="field">
                    <label for="name">Product name</label>
                    <input type="text" id="name" name="name" value="<?= e($name) ?>" required autofocus>
                </div>
                <div class="field">
                    <label for="brand">Brand</label>
                    <input type="text" id="brand" name="brand" value="<?= e($brand) ?>">
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id">
                        <option value="0">Uncategorised</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="price">Price (UGX)</label>
                    <input type="number" id="price" name="price" value="<?= e($price) ?>" min="0" step="1000" required>
                </div>
            </div>
            <?php if ($codesAvailable): ?>
                <div class="field-row">
                    <div class="field">
                        <label for="sku">SKU</label>
                        <input type="text" id="sku" name="sku" value="<?= e($sku) ?>" maxlength="60" placeholder="e.g. MSE-001">
                        <div class="hint">Internal stock code. Optional, and must be unique.</div>
                    </div>
                    <div class="field">
                        <label for="barcode">Barcode</label>
                        <input type="text" id="barcode" name="barcode" value="<?= e($barcode) ?>" maxlength="60" placeholder="e.g. 5012345678900">
                        <div class="hint">Type or scan this at the till to add the product to a sale. Optional, and must be unique.</div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="field">
                <label><input type="checkbox" name="track_serial_numbers" value="1" <?= $trackSerialNumbers ? 'checked' : '' ?> style="width: auto; margin-right: 8px;">Track a unique serial number or unit barcode for every item</label>
                <div class="hint">Use this for products where each physical unit has its own identifier. A product/model barcode alone is not a unique unit serial.</div>
            </div>
            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description"><?= e($description) ?></textarea>
            </div>
            <div class="field">
                <label for="specifications">Specifications</label>
                <textarea id="specifications" name="specifications" placeholder="e.g. 8GB RAM, 256GB storage, 6.1&quot; display"><?= e($specifications) ?></textarea>
                <div class="hint">Comma-separated. Use "Label: value" per item where useful.</div>
            </div>
            <div class="field">
                <label for="image">Cover image</label>
                <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp">
                <div class="hint">Shown on product cards and as the main image.</div>
            </div>
            <div class="field">
                <label for="gallery">Additional gallery images</label>
                <input type="file" id="gallery" name="gallery[]" accept=".jpg,.jpeg,.png,.webp" multiple>
                <div class="hint">Optional. Select multiple files for extra angles or detail shots.</div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label><input type="checkbox" name="is_high_end" <?= $isHighEnd ? 'checked' : '' ?> style="width: auto; margin-right: 8px;">High-end device (requires deposit)</label>
                </div>
                <div class="field">
                    <label for="deposit_percentage">Deposit percentage</label>
                    <input type="number" id="deposit_percentage" name="deposit_percentage" value="<?= (int) $depositPct ?>" min="1" max="100">
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="stock_status">Availability</label>
                    <select id="stock_status" name="stock_status">
                        <option value="in_stock" <?= $stockStatus === 'in_stock' ? 'selected' : '' ?>>In stock</option>
                        <option value="made_to_order" <?= $stockStatus === 'made_to_order' ? 'selected' : '' ?>>Made to order</option>
                        <option value="out_of_stock" <?= $stockStatus === 'out_of_stock' ? 'selected' : '' ?>>Out of stock</option>
                    </select>
                </div>
                <div class="field">
                    <label for="stock_quantity">Opening stock quantity</label>
                    <input type="number" id="stock_quantity" name="stock_quantity" value="<?= (int) $stockQuantity ?>" min="0" step="1">
                    <div class="hint">Stock received later through purchase orders is added automatically.</div>
                </div>
                <div class="field">
                    <label for="low_stock_threshold">Low-stock alert at</label>
                    <input type="number" id="low_stock_threshold" name="low_stock_threshold" value="<?= (int) $lowStockThreshold ?>" min="0" step="1">
                    <div class="hint">Set to 0 to disable the alert.</div>
                </div>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active — visible on the public site</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive — hidden from customers</option>
                    </select>
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label><input type="checkbox" name="is_featured" <?= $isFeatured ? 'checked' : '' ?> style="width: auto; margin-right: 8px;">Show in Featured Products</label>
                </div>
                <div class="field">
                    <label><input type="checkbox" name="is_new_arrival" <?= $isNewArrival ? 'checked' : '' ?> style="width: auto; margin-right: 8px;">Show in New Arrivals</label>
                </div>
            </div>
            <div class="table-actions">
                <button type="submit" class="btn btn-accent">Save product</button>
                <a href="<?= url('/admin/products/index.php') ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>