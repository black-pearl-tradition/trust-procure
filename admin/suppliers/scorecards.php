<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Supplier scorecards';

$suppliers = $pdo->query('SELECT id, name, status, email FROM suppliers ORDER BY name')->fetchAll();
$scorecards = [];

foreach ($suppliers as $supplier) {
    $supplierId = (int) $supplier['id'];

    $purchaseOrdersStmt = $pdo->prepare(
        'SELECT po.id, po.status, po.created_at, po.expected_delivery_date, po.supplier_response, po.supplier_response_at, po.supplier_proposed_delivery_date
         FROM purchase_orders po
         WHERE po.supplier_id = ?
         ORDER BY po.created_at DESC'
    );
    $purchaseOrdersStmt->execute([$supplierId]);
    $purchaseOrders = $purchaseOrdersStmt->fetchAll();

    $deliveryStatsStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(sdi.declared_quantity), 0) AS declared_quantity,
                COALESCE(SUM(COALESCE(sdi.accepted_quantity, 0)), 0) AS accepted_quantity,
                COALESCE(SUM(COALESCE(sdi.rejected_quantity, 0)), 0) AS rejected_quantity
         FROM supplier_delivery_items sdi
         JOIN supplier_deliveries sd ON sd.id = sdi.delivery_id
         WHERE sd.supplier_id = ?'
    );
    $deliveryStatsStmt->execute([$supplierId]);
    $deliveryStats = $deliveryStatsStmt->fetch();

    $declaredQuantity = (float) ($deliveryStats['declared_quantity'] ?? 0);
    $acceptedQuantity = (float) ($deliveryStats['accepted_quantity'] ?? 0);
    $qualityScore = 0.0;
    if ($declaredQuantity > 0) {
        $qualityScore = min(100, (($acceptedQuantity / $declaredQuantity) * 100));
    }

    $responseSummaryStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total_responses,
                SUM(CASE WHEN TIMESTAMPDIFF(HOUR, po.created_at, po.supplier_response_at) <= 48 THEN 1 ELSE 0 END) AS on_time_responses
         FROM purchase_orders po
         WHERE po.supplier_id = ?
           AND po.supplier_response IN ("acknowledged", "declined")'
    );
    $responseSummaryStmt->execute([$supplierId]);
    $responseSummary = $responseSummaryStmt->fetch();

    $responseScore = 0.0;
    $totalResponses = (int) ($responseSummary['total_responses'] ?? 0);
    if ($totalResponses > 0) {
        $responseScore = min(100, ((int) ($responseSummary['on_time_responses'] ?? 0) / $totalResponses) * 100);
    }

    $onTimeScore = 0.0;
    $timelyOrders = 0;
    $eligibleOrders = 0;

    foreach ($purchaseOrders as $order) {
        $status = $order['status'] ?? '';
        if (in_array($status, ['draft', 'cancelled', 'rejected'], true)) {
            continue;
        }

        $eligibleOrders++;
        $targetDate = $order['supplier_proposed_delivery_date'] ?: $order['expected_delivery_date'];
        if (!$targetDate) {
            continue;
        }

        if ($order['supplier_response'] === 'acknowledged' || $order['supplier_response'] === 'declined') {
            $dateDiff = strtotime($targetDate) - strtotime((string) $order['created_at']);
            if ($dateDiff >= 0) {
                $timelyOrders++;
            }
        }
    }

    if ($eligibleOrders > 0) {
        $onTimeScore = min(100, (($timelyOrders / $eligibleOrders) * 100));
    }

    $linkedPricesStmt = $pdo->prepare(
        'SELECT sp.product_id, sp.supplier_price, p.name AS product_name
         FROM supplier_products sp
         JOIN products p ON p.id = sp.product_id
         WHERE sp.supplier_id = ? AND sp.supplier_price IS NOT NULL'
    );
    $linkedPricesStmt->execute([$supplierId]);
    $linkedPrices = $linkedPricesStmt->fetchAll();

    $pricingScore = 0.0;
    if ($linkedPrices) {
        $scores = [];
        foreach ($linkedPrices as $row) {
            $supplierPrice = (float) ($row['supplier_price'] ?? 0);
            if ($supplierPrice <= 0) {
                continue;
            }

            $productPricesStmt = $pdo->prepare(
                'SELECT supplier_price
                 FROM supplier_products
                 WHERE product_id = ? AND supplier_price IS NOT NULL AND supplier_id != ?'
            );
            $productPricesStmt->execute([(int) $row['product_id'] ?? 0, $supplierId]);
            $otherPrices = array_map('floatval', array_column($productPricesStmt->fetchAll(), 'supplier_price'));
            $competitiveBase = $otherPrices ? min($otherPrices) : $supplierPrice;
            if ($competitiveBase <= 0) {
                $scores[] = 100;
                continue;
            }

            $priceGap = $supplierPrice - $competitiveBase;
            if ($priceGap <= 0) {
                $scores[] = 100;
            } else {
                $scores[] = max(0, 100 - (($priceGap / $competitiveBase) * 100));
            }
        }

        if ($scores) {
            $pricingScore = array_sum($scores) / count($scores);
        }
    }

    $overallScore = round(
        ($onTimeScore * 0.35) +
        ($qualityScore * 0.25) +
        ($responseScore * 0.2) +
        ($pricingScore * 0.2),
        1
    );

    $statusKey = supplier_status_key($overallScore);
    $pdo->prepare('UPDATE suppliers SET performance_status = ? WHERE id = ?')->execute([$statusKey, $supplierId]);

    $scorecards[] = [
        'supplier' => $supplier,
        'on_time' => round($onTimeScore, 1),
        'quality' => round($qualityScore, 1),
        'response' => round($responseScore, 1),
        'pricing' => round($pricingScore, 1),
        'overall' => $overallScore,
        'purchase_orders' => count($purchaseOrders),
        'status_label' => supplier_status_label($overallScore),
        'status_class' => supplier_status_class($overallScore),
    ];
}

usort($scorecards, static fn (array $a, array $b): int => $b['overall'] <=> $a['overall']);
$averageScore = $scorecards ? round(array_sum(array_column($scorecards, 'overall')) / count($scorecards), 1) : 0;

function supplier_status_key(float $score): string
{
    if ($score >= 85) {
        return 'preferred';
    }
    if ($score >= 70) {
        return 'watchlist';
    }
    return 'needs_review';
}

function supplier_status_label(float $score): string
{
    return match (supplier_status_key($score)) {
        'preferred' => 'Preferred',
        'watchlist' => 'Watchlist',
        default => 'Needs review',
    };
}

function supplier_status_class(float $score): string
{
    return match (supplier_status_key($score)) {
        'preferred' => 'badge badge-success',
        'watchlist' => 'badge badge-warning',
        default => 'badge badge-danger',
    };
}

function score_tag_class(float $score): string
{
    return supplier_status_class($score);
}

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="label">Active suppliers</div>
        <div class="value"><?= count($suppliers) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Average score</div>
        <div class="value"><?= number_format($averageScore, 1) ?> / 100</div>
    </div>
    <div class="stat-card">
        <div class="label">Top performer</div>
        <div class="value"><?= e($scorecards[0]['supplier']['name'] ?? '—') ?></div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Supplier performance scorecards</h2>
            <p class="muted mb-0">A simple weighted view of delivery reliability, quality, responsiveness, and pricing competitiveness.</p>
        </div>
        <a href="<?= url('/admin/suppliers/index.php') ?>" class="btn btn-outline btn-sm">Back to suppliers</a>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Overall</th>
                    <th>On-time</th>
                    <th>Quality</th>
                    <th>Response</th>
                    <th>Pricing</th>
                    <th>Status</th>
                    <th>POs</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scorecards as $scorecard): ?>
                    <?php $supplier = $scorecard['supplier']; ?>
                    <tr>
                        <td>
                            <strong><?= e($supplier['name']) ?></strong>
                            <?php if ($supplier['email']): ?><div class="muted small"><?= e($supplier['email']) ?></div><?php endif; ?>
                        </td>
                        <td><span class="<?= score_tag_class((float) $scorecard['overall']) ?>"><?= number_format((float) $scorecard['overall'], 1) ?></span></td>
                        <td><?= number_format((float) $scorecard['on_time'], 1) ?>%</td>
                        <td><?= number_format((float) $scorecard['quality'], 1) ?>%</td>
                        <td><?= number_format((float) $scorecard['response'], 1) ?>%</td>
                        <td><?= number_format((float) $scorecard['pricing'], 1) ?>%</td>
                        <td><span class="<?= e($scorecard['status_class']) ?>"><?= e($scorecard['status_label']) ?></span></td>
                        <td><?= (int) $scorecard['purchase_orders'] ?></td>
                        <td>
                            <div class="table-actions">
                                <a href="<?= url('/admin/suppliers/view.php?id=' . $supplier['id']) ?>" class="btn btn-outline btn-sm icon-action" title="Open supplier profile"><i class="bi bi-eye" aria-hidden="true"></i></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$scorecards): ?>
                    <tr>
                        <td colspan="9" class="empty-state">No supplier records are available yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
