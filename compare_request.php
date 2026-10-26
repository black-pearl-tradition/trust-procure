<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['compare'])) $_SESSION['compare'] = [];

$pageTitle = 'Compare & Request';
$productId = (int) ($_GET['product'] ?? 0);

// Add the product to compare if not already there
if ($productId > 0) {
    $exists = $pdo->prepare("SELECT id FROM products WHERE id = ? AND status = 'active'");
    $exists->execute([$productId]);
    if ($exists->fetch() && !in_array($productId, $_SESSION['compare'], true)) {
        if (count($_SESSION['compare']) >= COMPARE_LIMIT) {
            array_shift($_SESSION['compare']);
        }
        $_SESSION['compare'][] = $productId;
    }
}

// Handle removal
if (isset($_GET['remove'])) {
    $id = (int) $_GET['remove'];
    $_SESSION['compare'] = array_values(array_diff($_SESSION['compare'], [$id]));
}

// Get all products in compare list
$ids = get_compare_list();
$products = [];
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT p.*, c.name AS category_name FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id IN ($placeholders)"
    );
    $stmt->execute($ids);
    $byId = [];
    foreach ($stmt->fetchAll() as $row) {
        $byId[$row['id']] = $row;
    }
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $products[] = $byId[$id];
        }
    }
}

// Get all active products for adding more
$allProducts = $pdo->query(
    "SELECT p.id, p.name, p.brand, p.price FROM products p
     WHERE p.status = 'active' ORDER BY p.name"
)->fetchAll();

function spec_map(?string $specifications): array
{
    $out = [];
    if (!$specifications) return $out;
    foreach (explode(',', $specifications) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = explode(':', $line, 2);
        $label = count($parts) === 2 ? trim($parts[0]) : $line;
        $value = count($parts) === 2 ? trim($parts[1]) : '';
        $out[$label] = $value;
    }
    return $out;
}

$allSpecLabels = [];
$specsByProduct = [];
foreach ($products as $p) {
    $specsByProduct[$p['id']] = spec_map($p['specifications']);
    foreach (array_keys($specsByProduct[$p['id']]) as $label) {
        if (!in_array($label, $allSpecLabels, true)) {
            $allSpecLabels[] = $label;
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<section class="page-banner request-page-banner">
    <div class="container">
        <h1>Compare &amp; Request</h1>
        <p>Compare products side by side and request the one that best fits your needs.</p>
    </div>
</section>

<section class="section-tight compare-request-section">
    <div class="container">
        <div class="row g-4 compare-request-layout">
            <div class="col-12 col-lg-8">
                <div class="card border-0 shadow-sm rounded-4 compare-request-card">
                    <div class="card-body">
                        <div class="compare-request-card-head">
                            <div>
                                <div class="section-kicker">Comparison</div>
                                <h2 class="h4 mb-0">Side-by-side shortlist</h2>
                            </div>
                        </div>
                        <?php if (count($products) > 1): ?>
                            <div class="table-responsive">
                                <table class="compare-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <?php foreach ($products as $p): ?>
                                                <th><?= e($p['name']) ?><br><small class="fw-normal"><?= e($p['brand']) ?></small></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="compare-row-label">Price</td>
                                            <?php foreach ($products as $p): ?>
                                                <td><?= money($p['price']) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                        <tr>
                                            <td class="compare-row-label">Stock</td>
                                            <?php foreach ($products as $p): ?>
                                                <td><?= e(ucfirst(str_replace('_', ' ', $p['stock_status']))) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                        <?php foreach ($allSpecLabels as $label): ?>
                                            <tr>
                                                <td class="compare-row-label"><?= e($label) ?></td>
                                                <?php foreach ($products as $p): ?>
                                                    <td><?= e($specsByProduct[$p['id']][$label] ?? '—') ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr>
                                            <td class="compare-row-label"></td>
                                            <?php foreach ($products as $p): ?>
                                                <td><a href="<?= url('/product_details.php?id=' . $p['id'] . '#request-product') ?>" class="btn btn-accent btn-sm">Request this</a></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif (count($products) === 1): ?>
                            <div class="alert alert-info">Add at least one more product to compare.</div>
                        <?php else: ?>
                            <div class="alert alert-warning">No products in your comparison list.</div>
                        <?php endif; ?>

                        <?php if (count($products) < COMPARE_LIMIT): ?>
                            <div class="mt-4">
                                <h3 class="h6 mb-2">Add a product to compare</h3>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($allProducts as $ap): ?>
                                        <?php if (!in_array($ap['id'], $ids, true)): ?>
                                            <a href="<?= url('/compare_request.php?product=' . $ap['id']) ?>" class="btn btn-outline-secondary btn-sm"><?= e($ap['name']) ?></a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 request-form-card sticky-top" style="top: 20px;">
                    <div class="card-body">
                        <div class="compare-request-card-head">
                            <div>
                                <div class="section-kicker">Request</div>
                                <h2 class="h4 mb-0">Request a product</h2>
                            </div>
                        </div>
                        <?php if ($products): ?>
                            <form method="post" action="<?= url('/user/requests/add.php') ?>">
                                <?= csrf_field() ?>
                                <div class="mb-3">
                                    <label for="request_product" class="form-label fw-semibold">Select product</label>
                                    <select class="form-select" id="request_product" name="product_id" required>
                                        <?php foreach ($products as $p): ?>
                                            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> — <?= money($p['price']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="quantity" class="form-label fw-semibold">Quantity</label>
                                    <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1" required>
                                </div>
                                <div class="mb-3">
                                    <label for="delivery_address" class="form-label fw-semibold">Delivery address</label>
                                    <input type="text" class="form-control" id="delivery_address" name="delivery_address" placeholder="Where should this be delivered?">
                                </div>
                                <div class="mb-3">
                                    <label for="notes" class="form-label fw-semibold">Notes (optional)</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-accent w-100"><i class="bi bi-send me-2" aria-hidden="true"></i>Submit request</button>
                            </form>
                        <?php else: ?>
                            <p class="text-muted">Add products to comparison to request them.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>