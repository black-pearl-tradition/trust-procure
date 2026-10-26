<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    set_flash('error', 'Product not found.');
    redirect('/admin/products/index.php');
}

$pageTitle = 'Edit Product';
$errors = [];
$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();

$name = $product['name'];
$brand = $product['brand'];
$categoryId = (int) $product['category_id'];
$description = $product['description'];
$specifications = $product['specifications'];
$price = $product['price'];
$isHighEnd = (bool) $product['is_high_end'];
$depositPct = (int) $product['deposit_percentage'];
$status = $product['status'];
$stockStatus = $product['stock_status'];
$stockQuantity = (int) $product['stock_quantity'];
$lowStockThreshold = (int) $product['low_stock_threshold'];
$isFeatured = (bool) $product['is_featured'];
$isNewArrival = (bool) $product['is_new_arrival'];
$sku = (string) ($product['sku'] ?? '');
$barcode = (string) ($product['barcode'] ?? '');
$trackSerialNumbers = (bool) ($product['track_serial_numbers'] ?? false);
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
    $lowStockThreshold = max(0, (int) ($_POST['low_stock_threshold'] ?? 5));
    $isFeatured = isset($_POST['is_featured']);
    $isNewArrival = isset($_POST['is_new_arrival']);

    if (!in_array($stockStatus, ['in_stock', 'out_of_stock', 'made_to_order'], true)) {
        $stockStatus = 'in_stock';
    }

    if ($name === '') $errors[] = 'Please enter a product name.';
    if ($price <= 0) $errors[] = 'Please enter a valid price.';

    // Checked before the uploads so a rejected code cannot leave an orphaned
    // image on disk. The product being edited is ignored, so saving it with its
    // own code unchanged is fine.
    $skuValue = null;
    $barcodeValue = null;
    if ($codesAvailable) {
        [$skuValue, $skuError] = normalise_product_code($pdo, $sku, 'SKU', $id);
        if ($skuError !== '') $errors[] = $skuError;
        [$barcodeValue, $barcodeError] = normalise_product_code($pdo, $barcode, 'Barcode', $id);
        if ($barcodeError !== '') $errors[] = $barcodeError;
    }

    $imageName = $product['image'];
    $galleryNames = [];
    if (!$errors) {
        try {
            $uploaded = handle_upload($_FILES['image'] ?? [], 'products');
            if ($uploaded) {
                $imageName = $uploaded;
            }
            $galleryNames = handle_multi_upload($_FILES['gallery'] ?? [], 'products');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        $codeAssignments = $codesAvailable ? ', sku=?, barcode=?' : '';
        $stmt = $pdo->prepare(
            'UPDATE products SET category_id=?, name=?, brand=?, description=?, specifications=?, price=?, image=?, low_stock_threshold=?, is_high_end=?, deposit_percentage=?, stock_status=?, is_featured=?, is_new_arrival=?, status=?, track_serial_numbers=?'
            . $codeAssignments . ' WHERE id=?'
        );
        $params = [
            $categoryId ?: null,
            $name,
            $brand,
            $description,
            $specifications,
            $price,
            $imageName,
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
        $params[] = $id;
        $stmt->execute($params);

        if ($galleryNames) {
            $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM product_images WHERE product_id = ?');
            $orderStmt->execute([$id]);
            $nextOrder = (int) $orderStmt->fetchColumn() + 1;

            $galleryStmt = $pdo->prepare('INSERT INTO product_images (product_id, image, sort_order) VALUES (?, ?, ?)');
            foreach ($galleryNames as $i => $filename) {
                $galleryStmt->execute([$id, $filename, $nextOrder + $i]);
            }
        }

        log_activity($pdo, $_SESSION['user_id'], 'product_updated', "Updated product #$id");
        set_flash('success', 'Product updated.');
        redirect('/admin/products/edit.php?id=' . $id);
    }
}

$gallery = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order');
$gallery->execute([$id]);
$gallery = $gallery->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>Edit product</h2>
    </div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <form method="post" enctype="multipart/form-data" style="max-width: 640px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $product['id'] ?>">
            <?php if ($product['image']): ?>
                <div class="field">
                    <label>Current cover image</label>
                    <div class="pd-image" style="width: 140px; height: 140px;">
                        <img src="<?= url('/uploads/products/' . e($product['image'])) ?>" alt="">
                    </div>
                </div>
            <?php endif; ?>
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
                <textarea id="specifications" name="specifications"><?= e($specifications) ?></textarea>
                <div class="hint">Comma-separated. Use "Label: value" per item where useful.</div>
            </div>
            <div class="field">
                <label for="image">Replace cover image</label>
                <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp">
            </div>
            <div class="field">
                <label for="gallery">Add gallery images</label>
                <input type="file" id="gallery" name="gallery[]" accept=".jpg,.jpeg,.png,.webp" multiple>
                <div class="hint">Optional. Adds to the existing gallery below — it doesn't replace it.</div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label><input type="checkbox" name="is_high_end" <?= $isHighEnd ? 'checked' : '' ?> style="width: auto; margin-right: 8px;">High-end device (requires deposit)</label>
                </div>
                <div class="field">
                    <label for="deposit_percentage">Deposit percentage</label>
                    <input type="number" id="deposit_percentage" name="deposit_percentage" value="<?= (int) $depositPct ?>" min="1" max="100">
                </div>
                <div class="field">
                    <label>Stock quantity</label>
                    <div class="detail-item">
                        <div class="value"><?= (int) $stockQuantity ?> units</div>
                    </div>
                    <div class="hint"><a href="<?= url('/admin/inventory/adjust.php?id=' . $product['id']) ?>">Adjust inventory</a> to record a reason for any stock change.</div>
                </div>
                <div class="field">
                    <label for="low_stock_threshold">Low-stock alert at</label>
                    <input type="number" id="low_stock_threshold" name="low_stock_threshold" value="<?= (int) $lowStockThreshold ?>" min="0" step="1">
                    <div class="hint">Set to 0 to disable the alert.</div>
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
                <button type="submit" class="btn btn-accent">Save changes</button>
                <a href="<?= url('/admin/products/index.php') ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h3>Gallery images</h3>
    </div>
    <div class="panel-body">
        <?php if ($gallery): ?>
            <div class="gallery-grid">
                <?php foreach ($gallery as $g): ?>
                    <div class="gallery-thumb">
                        <img src="<?= url('/uploads/products/' . e($g['image'])) ?>" alt="">
                        <form method="post" action="<?= url('/admin/products/delete_image.php') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $g['id'] ?>">
                            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Remove this gallery image?">Remove</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="mb-0 muted">No gallery images yet. Add some above.</p>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>