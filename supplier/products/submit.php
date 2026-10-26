<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_supplier();

$pageTitle = 'Submit a Product';
$supplier = get_supplier_for_user((int) $_SESSION['user_id']);
$errors = [];
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$name = trim($_POST['name'] ?? '');
$brand = trim($_POST['brand'] ?? '');
$categoryId = (int) ($_POST['category_id'] ?? 0);
$description = trim($_POST['description'] ?? '');
$specifications = trim($_POST['specifications'] ?? '');
$supplierPrice = trim($_POST['supplier_price'] ?? '');
$warrantyCovered = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? isset($_POST['warranty_covered'])
    : true;
$leadTimeDays = trim($_POST['lead_time_days'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('/supplier/products/submit.php');

    if (!$supplier || $supplier['status'] !== 'active') {
        $errors[] = 'An active supplier profile is required to submit products.';
    }
    if ($name === '') $errors[] = 'Please enter a product name.';
    if (mb_strlen($name) > 150) $errors[] = 'Product name must be 150 characters or fewer.';
    if ($brand !== '' && mb_strlen($brand) > 80) $errors[] = 'Brand must be 80 characters or fewer.';

    $priceValue = preg_match('/^\d+(?:\.\d{1,2})?$/', $supplierPrice) === 1
        ? (float) $supplierPrice
        : false;
    if ($priceValue === false || $priceValue <= 0 || $priceValue > 999999999999.99) {
        $errors[] = 'Enter a supplier price greater than zero.';
    }

    if ($categoryId > 0) {
        $categoryCheck = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ?');
        $categoryCheck->execute([$categoryId]);
        if ((int) $categoryCheck->fetchColumn() === 0) $errors[] = 'Choose a valid category.';
    }

    $leadTimeValue = null;
    if ($leadTimeDays !== '') {
        $leadTimeValue = filter_var($leadTimeDays, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($leadTimeValue === false) $errors[] = 'Lead time must be a non-negative whole number of days.';
    }
    if (mb_strlen($notes) > 255) $errors[] = 'Additional notes must be 255 characters or fewer.';

    $imageName = null;
    if (!$errors) {
        try {
            $imageName = handle_upload($_FILES['image'] ?? [], 'products');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            "INSERT INTO supplier_product_submissions
                (supplier_id, submitted_by, category_id, name, brand, description, specifications, image,
                 supplier_price, warranty_covered, lead_time_days, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $supplier['id'],
            $_SESSION['user_id'],
            $categoryId ?: null,
            $name,
            $brand !== '' ? $brand : null,
            $description !== '' ? $description : null,
            $specifications !== '' ? $specifications : null,
            $imageName,
            $priceValue,
            $warrantyCovered ? 1 : 0,
            $leadTimeValue,
            $notes !== '' ? $notes : null,
        ]);

        $adminUsers = $pdo->query(
            "SELECT id FROM users
             WHERE role IN ('admin', 'super_admin')
               AND status = 'active'
               AND deleted_at IS NULL"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($adminUsers as $adminUserId) {
            create_notification(
                $pdo,
                (int) $adminUserId,
                'Supplier product submitted',
                "{$supplier['name']} submitted {$name} for catalog review.",
                '/admin/supplier_submissions/index.php'
            );
        }
        set_flash('success', 'Product submitted for admin review. Your supplier price will remain private.');
        redirect('/supplier/products/index.php');
    }
}

include __DIR__ . '/../../includes/supplier_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Submit a product</h2>
            <p class="muted mb-0">An administrator will review the product before it is added to the public catalog.</p>
        </div>
        <a href="<?= url('/supplier/products/index.php') ?>" class="btn btn-outline btn-sm">My products</a>
    </div>
    <div class="panel-body">
        <?php if (!$supplier || $supplier['status'] !== 'active'): ?>
            <div class="alert alert-warning">An active supplier profile is required to submit products. Please contact an administrator.</div>
        <?php else: ?>
            <?php foreach ($errors as $error): ?>
                <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
            <?php endforeach; ?>
            <form method="post" enctype="multipart/form-data" class="supplier-product-form">
                <?= csrf_field() ?>
                <div>
                    <label for="name">Product name <span class="text-danger">*</span></label>
                    <input type="text" id="name" name="name" maxlength="150" value="<?= e($name) ?>" required>
                </div>
                <div>
                    <label for="brand">Brand</label>
                    <input type="text" id="brand" name="brand" maxlength="80" value="<?= e($brand) ?>">
                </div>
                <div>
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id">
                        <option value="0">Choose a category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="supplier_price">Your quoted price (UGX) <span class="text-danger">*</span></label>
                    <input type="number" id="supplier_price" name="supplier_price" min="0.01" step="0.01" value="<?= e($supplierPrice) ?>" required>
                    <small class="muted">Private to administrators. Admins set the customer-facing catalog price.</small>
                </div>
                <div>
                    <label for="lead_time_days">Lead time (days)</label>
                    <input type="number" id="lead_time_days" name="lead_time_days" min="0" step="1" value="<?= e($leadTimeDays) ?>">
                </div>
                <div>
                    <label for="image">Product image</label>
                    <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp">
                </div>
                <div class="form-grid-full">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="4"><?= e($description) ?></textarea>
                </div>
                <div class="form-grid-full">
                    <label for="specifications">Specifications</label>
                    <textarea id="specifications" name="specifications" rows="4"><?= e($specifications) ?></textarea>
                </div>
                <div class="form-grid-full">
                    <label for="notes">Additional notes for the admin</label>
                    <textarea id="notes" name="notes" maxlength="255" rows="3"><?= e($notes) ?></textarea>
                </div>
                <div class="form-grid-full">
                    <label class="d-flex align-items-center gap-2">
                        <input type="checkbox" name="warranty_covered" value="1" <?= $warrantyCovered ? 'checked' : '' ?>>
                        Warranty is included
                    </label>
                </div>
                <div class="form-grid-full table-actions">
                    <button type="submit" class="btn btn-accent">Submit for review</button>
                    <a href="<?= url('/supplier/products/index.php') ?>" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/supplier_layout_end.php'; ?>
