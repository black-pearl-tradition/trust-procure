<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['compare'])) $_SESSION['compare'] = [];

$referer = $_SERVER['HTTP_REFERER'] ?? url('/products.php');

// --- Handle add / remove / clear actions -------------------------------
if (isset($_GET['add'])) {
    $id = (int) $_GET['add'];
    $exists = $pdo->prepare("SELECT id FROM products WHERE id = ? AND status = 'active'");
    $exists->execute([$id]);

    if ($exists->fetch() && !in_array($id, $_SESSION['compare'], true)) {
        if (count($_SESSION['compare']) >= COMPARE_LIMIT) {
            set_flash('error', 'You can compare up to ' . COMPARE_LIMIT . ' products at a time. Remove one first.');
        } else {
            $_SESSION['compare'][] = $id;
            set_flash('success', 'Added to comparison.');
        }
    }
    redirect_to_referer_or_compare($referer);
}

if (isset($_GET['remove'])) {
    $id = (int) $_GET['remove'];
    $_SESSION['compare'] = array_values(array_diff($_SESSION['compare'], [$id]));
    set_flash('success', 'Removed from comparison.');
    redirect_to_referer_or_compare($referer);
}

if (isset($_GET['clear'])) {
    $_SESSION['compare'] = [];
    redirect('/compare.php');
}

// --- Render the comparison page -----------------------------------------
function redirect_to_referer_or_compare(string $referer): void
{
    // Only follow the referer if it's our own site, to avoid open-redirect issues.
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '' && str_contains($referer, $host)) {
        header('Location: ' . $referer);
        exit;
    }
    redirect('/products.php');
}

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
    // Preserve the order products were added in.
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $products[] = $byId[$id];
        }
    }
}

$pageTitle = 'Compare Products';
include __DIR__ . '/includes/header.php';

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

// Union of every spec label across compared products, so rows line up.
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
?>

<section class="page-banner compare-page-banner">
    <div class="container">
        <h1>Compare products</h1>
        <p>Line up specifications, pricing, and availability side by side before you decide.</p>
    </div>
</section>

<section class="section-tight compare-section">
    <div class="container compare-container">
        <?php if (!$products): ?>
            <div class="compare-empty">
                <div class="compare-empty-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></div>
                <div class="section-kicker">Make a confident choice</div>
                <h2>Build your comparison</h2>
                <p>Select up to <?= COMPARE_LIMIT ?> products from the catalog to compare price, availability, ratings, and specifications side by side.</p>
                <a href="<?= url('/products.php') ?>" class="btn btn-primary"><i class="bi bi-grid me-2" aria-hidden="true"></i>Browse products</a>
            </div>
        <?php else: ?>
            <div class="compare-heading">
                <div>
                    <div class="section-kicker">Side-by-side review</div>
                    <h2><?= count($products) ?> selected <?= count($products) === 1 ? 'product' : 'products' ?></h2>
                    <p>Differences are highlighted so the important trade-offs are easy to spot.</p>
                </div>
                <div class="table-actions compare-actions">
                    <a href="<?= url('/products.php') ?>" class="btn btn-outline btn-sm">Add another</a>
                    <a href="<?= url('/compare_request.php') ?>" class="btn btn-accent btn-sm">Compare &amp; request</a>
                    <a href="<?= url('/compare.php?clear=1') ?>" class="btn btn-outline btn-sm" data-confirm="Clear the entire comparison list?">Clear all</a>
                </div>
            </div>

            <div class="compare-scroll">
                <table class="compare-table">
                    <thead>
                        <tr>
                            <th class="compare-row-label"></th>
                            <?php foreach ($products as $p): ?>
                            <th>
                                <div class="pd-image" style="width: 140px; height: 140px; margin-bottom: 10px;">
                                    <?php if ($p['image']): ?>
                                        <img src="<?= url('/uploads/products/' . e($p['image'])) ?>" alt="">
                                    <?php else: ?>
                                        <span>No image</span>
                                    <?php endif; ?>
                                </div>
                                <a href="<?= url('/compare.php?remove=' . $p['id']) ?>" class="btn btn-outline btn-sm">Remove</a>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="compare-row-label">Name</td>
                            <?php foreach ($products as $p): ?>
                            <td><a href="<?= url('/product_details.php?id=' . $p['id']) ?>"><strong><?= e($p['name']) ?></strong></a></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="compare-row-label">Brand</td>
                            <?php $vals = array_column($products, 'brand'); foreach ($products as $i => $p): ?>
                            <td class="<?= count(array_unique($vals)) > 1 ? 'diff' : '' ?>"><?= e($p['brand'] ?: '—') ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="compare-row-label">Category</td>
                            <?php $vals = array_column($products, 'category_name'); foreach ($products as $p): ?>
                            <td class="<?= count(array_unique($vals)) > 1 ? 'diff' : '' ?>"><?= e($p['category_name'] ?: '—') ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="compare-row-label">Price</td>
                            <?php $vals = array_column($products, 'price'); foreach ($products as $p): ?>
                            <td class="<?= count(array_unique($vals)) > 1 ? 'diff' : '' ?>"><?= money($p['price']) ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="compare-row-label">Deposit required</td>
                            <?php foreach ($products as $p): ?>
                            <td><?= $p['is_high_end'] ? (int) $p['deposit_percentage'] . '%' : 'Paid in full' ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="compare-row-label">Availability</td>
                            <?php $vals = array_column($products, 'stock_status'); foreach ($products as $p): ?>
                            <td class="<?= count(array_unique($vals)) > 1 ? 'diff' : '' ?>"><span class="badge <?= stock_status_badge_class($p['stock_status']) ?>"><?= e(stock_status_label($p['stock_status'])) ?></span></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="compare-row-label">Rating</td>
                            <?php foreach ($products as $p): $r = get_product_rating($pdo, $p['id']); ?>
                            <td><?= render_stars($r['avg'], $r['count']) ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td class="compare-row-label">Description</td>
                            <?php foreach ($products as $p): ?>
                            <td class="compare-description"><?= e($p['description'] ?: '—') ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php foreach ($allSpecLabels as $label): $vals = array_map(fn($p) => $specsByProduct[$p['id']][$label] ?? '—', $products); ?>
                        <tr>
                            <td class="compare-row-label"><?= e($label) ?></td>
                            <?php foreach ($products as $p): ?>
                            <td class="<?= count(array_unique($vals)) > 1 ? 'diff' : '' ?>"><?= e($specsByProduct[$p['id']][$label] ?? '—') ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td class="compare-row-label"></td>
                            <?php foreach ($products as $p): ?>
                            <td><a href="<?= url('/product_details.php?id=' . $p['id']) ?>" class="btn btn-accent btn-sm">View product</a></td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="hint" style="margin-top: 14px;">Highlighted rows show specifications that differ between the products you're comparing.</p>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
