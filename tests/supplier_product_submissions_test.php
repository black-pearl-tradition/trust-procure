<?php

function submission_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

$root = __DIR__ . '/..';
$supplierForm = (string) file_get_contents($root . '/supplier/products/submit.php');
$supplierProducts = (string) file_get_contents($root . '/supplier/products/index.php');
$supplierDashboard = (string) file_get_contents($root . '/supplier/dashboard.php');
$adminReview = (string) file_get_contents($root . '/admin/supplier_submissions/index.php');
$supplierLayout = (string) file_get_contents($root . '/includes/supplier_layout_start.php');
$adminLayout = (string) file_get_contents($root . '/includes/admin_layout_start.php');
$schema = (string) file_get_contents($root . '/config/schema.sql');
$migrations = (string) file_get_contents($root . '/config/migrations.sql');

submission_assert(str_contains($supplierForm, 'require_supplier();'), 'Product submissions must be supplier-only.');
submission_assert(str_contains($supplierForm, 'verify_csrf('), 'Product submission must verify CSRF.');
submission_assert(str_contains($supplierForm, 'supplier_price'), 'Supplier submission must capture a quoted price.');
submission_assert(str_contains($supplierForm, 'INSERT INTO supplier_product_submissions'), 'Supplier form must save a review submission rather than publish a product directly.');
submission_assert(!str_contains($supplierForm, 'INSERT INTO products'), 'Suppliers must not publish directly to the catalog.');
submission_assert(str_contains($supplierProducts, 'supplier_product_submissions'), 'Supplier product page must show submission status.');
submission_assert(str_contains($supplierDashboard, 'pendingProductSubmissions'), 'Supplier dashboard must show review counts.');
submission_assert(str_contains($supplierLayout, '/supplier/products/submit.php'), 'Supplier navigation must expose the product submission form.');
submission_assert(str_contains($adminReview, 'require_admin();'), 'Supplier quote review must be admin-only.');
submission_assert(str_contains($adminReview, 'beginTransaction()') && str_contains($adminReview, 'FOR UPDATE'), 'Approval must lock and process a pending submission transactionally.');
submission_assert(str_contains($adminReview, 'supplier_price') && str_contains($adminReview, 'publicPrice'), 'Approval must keep supplier quote separate from public catalog price.');
submission_assert(str_contains($adminLayout, '/admin/supplier_submissions/index.php'), 'Admin navigation must expose the review queue.');
submission_assert(str_contains($schema, 'CREATE TABLE supplier_product_submissions'), 'Fresh schema must include supplier product submissions.');
submission_assert(str_contains($migrations, 'CREATE TABLE IF NOT EXISTS supplier_product_submissions'), 'Existing installations must be able to migrate supplier product submissions.');

echo "Supplier product submission tests passed.\n";
