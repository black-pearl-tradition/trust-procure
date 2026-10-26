<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'RFQ comparison';

$selectedProductId = (int) ($_GET['product_id'] ?? 0);

$products = $pdo->query(
    "SELECT id, name, price FROM products WHERE status = 'active' ORDER BY name"
)->fetchAll();

$offerSql = "
    SELECT sp.id, sp.product_id, sp.supplier_id, sp.supplier_price, sp.lead_time_days, sp.warranty_covered, sp.notes,
           p.name AS product_name, p.price AS catalog_price,
           s.name AS supplier_name, s.status AS supplier_status, s.performance_status, s.email AS supplier_email
    FROM supplier_products sp
    JOIN products p ON p.id = sp.product_id
    JOIN suppliers s ON s.id = sp.supplier_id
    WHERE p.status = 'active'
";
$params = [];
if ($selectedProductId > 0) {
    $offerSql .= ' AND sp.product_id = ?';
    $params[] = $selectedProductId;
}
$offerSql .= " ORDER BY CASE s.performance_status WHEN 'preferred' THEN 0 WHEN 'watchlist' THEN 1 ELSE 2 END ASC, sp.supplier_price ASC, sp.lead_time_days ASC";
$offersStmt = $pdo->prepare($offerSql);
$offersStmt->execute($params);
$offers = $offersStmt->fetchAll();

$comparison = [];
foreach ($offers as $offer) {
    $productId = (int) $offer['product_id'];
    if (!isset($comparison[$productId])) {
        $comparison[$productId] = [
            'product_id' => $productId,
            'product_name' => $offer['product_name'],
            'catalog_price' => (float) $offer['catalog_price'],
            'offers' => [],
        ];
    }

    $comparison[$productId]['offers'][] = [
        'supplier_id' => (int) $offer['supplier_id'],
        'supplier_name' => $offer['supplier_name'],
        'supplier_status' => $offer['supplier_status'],
        'supplier_performance_status' => $offer['performance_status'] ?? 'watchlist',
        'supplier_email' => $offer['supplier_email'],
        'supplier_price' => (float) $offer['supplier_price'],
        'lead_time_days' => (int) ($offer['lead_time_days'] ?? 0),
        'warranty_covered' => (bool) $offer['warranty_covered'],
        'notes' => $offer['notes'],
    ];
}

foreach ($comparison as &$group) {
    $prices = array_map(static fn (array $offer): float => $offer['supplier_price'], $group['offers']);
    $leadTimes = array_map(static fn (array $offer): int => $offer['lead_time_days'], $group['offers']);
    $minPrice = $prices ? min($prices) : 0.0;
    $minLead = $leadTimes ? min(array_filter($leadTimes, static fn (int $lead): bool => $lead > 0)) : 0;

    foreach ($group['offers'] as &$offer) {
        $priceScore = 100.0;
        if ($minPrice > 0) {
            $priceGapPct = (($offer['supplier_price'] - $minPrice) / $minPrice) * 100;
            $priceScore = max(0, 100 - $priceGapPct);
        }

        $leadScore = 100.0;
        if ($minLead > 0 && $offer['lead_time_days'] > 0) {
            $leadGapPct = (($offer['lead_time_days'] - $minLead) / $minLead) * 100;
            $leadScore = max(0, 100 - $leadGapPct);
        }

        if ($offer['lead_time_days'] <= 0) {
            $leadScore = 60.0;
        }

        $warrantyBoost = $offer['warranty_covered'] ? 10 : 0;
        $performanceBonus = match (($offer['supplier_performance_status'] ?? 'watchlist')) {
            'preferred' => 12,
            'watchlist' => 4,
            'needs_review' => -8,
            default => 0,
        };
        $comparisonScore = round(min(100, ($priceScore * 0.65) + ($leadScore * 0.25) + $warrantyBoost + $performanceBonus), 1);

        $offer['price_score'] = round($priceScore, 1);
        $offer['lead_score'] = round($leadScore, 1);
        $offer['performance_bonus'] = $performanceBonus;
        $offer['comparison_score'] = $comparisonScore;
    }
    unset($offer);

    usort($group['offers'], static fn (array $a, array $b): int => $b['comparison_score'] <=> $a['comparison_score']);
    $group['best_offer'] = $group['offers'][0] ?? null;
}
unset($group);

ksort($comparison);

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>RFQ comparison</h2>
            <p class="muted mb-0">Compare supplier offers by total landed cost, lead time, and warranty coverage before approving a PO.</p>
        </div>
        <div class="table-actions">
            <a href="<?= url('/admin/suppliers/index.php') ?>" class="btn btn-outline btn-sm">Back to suppliers</a>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Filter products</h3></div>
    <div class="panel-body">
        <form method="get" class="table-actions" style="align-items:flex-end;">
            <label for="product_id" class="visually-hidden">Product</label>
            <select id="product_id" name="product_id" onchange="this.form.submit()">
                <option value="">All products</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?= (int) $product['id'] ?>" <?= $selectedProductId === (int) $product['id'] ? 'selected' : '' ?>><?= e($product['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php if (!$comparison): ?>
    <div class="panel">
        <div class="panel-body">
            <div class="empty-state">No supplier offers are linked to active products yet.</div>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($comparison as $group): ?>
        <?php $bestOffer = $group['best_offer']; ?>
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h3><?= e($group['product_name']) ?></h3>
                    <p class="muted mb-0">Catalog price: <?= money((float) $group['catalog_price']) ?></p>
                </div>
                <?php if ($bestOffer): ?>
                    <div class="table-actions">
                        <span class="badge badge-success">Best quote: <?= e($bestOffer['supplier_name']) ?> · <?= money((float) $bestOffer['supplier_price']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="panel-body flush">
                <table>
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Unit price</th>
                            <th>Lead time</th>
                            <th>Warranty</th>
                            <th>Price score</th>
                            <th>Lead score</th>
                            <th>Overall</th>
                            <th>Preference</th>
                            <th>Notes</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group['offers'] as $offer): ?>
                            <tr>
                                <td>
                                    <strong><?= e($offer['supplier_name']) ?></strong>
                                    <?php if ($offer['supplier_email']): ?>
                                        <div class="muted small"><?= e($offer['supplier_email']) ?></div>
                                    <?php endif; ?>
                                    <?php
                                        $performanceStatus = $offer['supplier_performance_status'] ?? 'watchlist';
                                        $performanceLabel = match ($performanceStatus) {
                                            'preferred' => 'Preferred',
                                            'watchlist' => 'Watchlist',
                                            'needs_review' => 'Needs review',
                                            default => ucfirst((string) $performanceStatus),
                                        };
                                    ?>
                                    <div class="small muted">Supplier status: <?= e($performanceLabel) ?></div>
                                    <?php if ($performanceStatus === 'preferred'): ?>
                                        <span class="badge badge-success">Preferred supplier</span>
                                    <?php elseif ($performanceStatus === 'needs_review'): ?>
                                        <span class="badge badge-danger">Review before ordering</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= money((float) $offer['supplier_price']) ?></td>
                                <td><?= $offer['lead_time_days'] > 0 ? (int) $offer['lead_time_days'] . ' days' : 'Not set' ?></td>
                                <td><?= $offer['warranty_covered'] ? 'Covered' : 'Not covered' ?></td>
                                <td><?= number_format((float) $offer['price_score'], 1) ?>%</td>
                                <td><?= number_format((float) $offer['lead_score'], 1) ?>%</td>
                                <td><span class="badge badge-<?= $offer['comparison_score'] >= 85 ? 'success' : ($offer['comparison_score'] >= 70 ? 'warning' : 'danger') ?>"><?= number_format((float) $offer['comparison_score'], 1) ?>%</span></td>
                                <td>
                                    <?php
                                        $statusBadge = match (($offer['supplier_performance_status'] ?? 'watchlist')) {
                                            'preferred' => 'badge badge-success',
                                            'watchlist' => 'badge badge-warning',
                                            'needs_review' => 'badge badge-danger',
                                            default => 'badge badge-secondary',
                                        };
                                    ?>
                                    <span class="<?= $statusBadge ?>"><?= e(ucfirst((string) ($offer['supplier_performance_status'] ?? 'watchlist'))) ?></span>
                                    <div class="small muted">+<?= (int) $offer['performance_bonus'] ?> score</div>
                                </td>
                                <td><?= e($offer['notes'] ?: '—') ?></td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?= url('/admin/purchase_orders/add.php?supplier_id=' . (int) $offer['supplier_id'] . '&product_id=' . (int) $group['product_id'] . '&quote_price=' . rawurlencode((string) $offer['supplier_price'])) ?>" class="btn btn-accent btn-sm">Create PO</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
