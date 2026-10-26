<?php
/**
 * Till sales register — every sale rung up at the point of sale.
 *
 * A till sale is an ordinary order carrying channel = 'pos', so this reads the
 * same rows the receipt does. It is deliberately read-only: correcting a sale
 * happens on the order itself, not here, so the register stays an honest record
 * of what the counter took.
 *
 * Staff can reach this (the till itself is require_staff), which matches the
 * "Sales" button on the terminal. Cost data is not shown — revenue figures only.
 */
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Till Sales';

$search = trim((string) ($_GET['q'] ?? ''));
$methodFilter = (string) ($_GET['method'] ?? '');
$availableMethods = pos_payment_methods();
if (!array_key_exists($methodFilter, $availableMethods)) {
    $methodFilter = '';
}

// How a sale is paid for, as one expression reused by the list, the totals and
// the filter. A sale with money still owed is "credit" whatever was recorded
// against it; everything else is whatever the verified payment says.
$methodSql = "CASE WHEN o.balance_amount > 0 THEN 'credit'
                   ELSE COALESCE((SELECT p.method FROM payments p
                                   WHERE p.order_id = o.id AND p.status = 'verified'
                                   ORDER BY p.id LIMIT 1), '')
              END";

$whereClauses = ["o.channel = 'pos'"];
$params = [];

if ($search !== '') {
    // Wildcards the operator typed stay literal, so "50%" is not a prefix match.
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
    $whereClauses[] = '(o.order_number LIKE ? OR o.invoice_number LIKE ? OR u.full_name LIKE ?
                       OR u.email LIKE ? OR o.walk_in_customer_name LIKE ? OR CAST(o.id AS CHAR) LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if ($methodFilter !== '') {
    $whereClauses[] = "$methodSql = ?";
    $params[] = $methodFilter;
}

$whereSql = ' WHERE ' . implode(' AND ', $whereClauses);

$fromSql = ' FROM orders o
             JOIN users u ON u.id = o.user_id
             LEFT JOIN users s ON s.id = o.sold_by';

$countStmt = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
$countStmt->execute($params);
$filteredTotal = (int) $countStmt->fetchColumn();

$perPage = 20;
$page = current_page();
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$dataSql = "SELECT o.*, u.full_name AS customer_name, u.email AS customer_email,
                   s.full_name AS cashier_name,
                   $methodSql AS method,
                   (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS line_count,
                   (SELECT COALESCE(SUM(oi2.quantity), 0) FROM order_items oi2 WHERE oi2.order_id = o.id) AS unit_count,
                   (SELECT COALESCE(SUM(p.amount), 0) FROM payments p
                     WHERE p.order_id = o.id AND p.status = 'verified') AS paid"
    . $fromSql . $whereSql
    . ' ORDER BY o.created_at DESC, o.id DESC LIMIT ? OFFSET ?';

$stmt = $pdo->prepare($dataSql);
// Positional placeholders throughout: PDO refuses to mix these with named
// ones, and a filtered search is exactly when both would otherwise appear.
$index = 1;
foreach ($params as $value) {
    $stmt->bindValue($index++, $value, PDO::PARAM_STR);
}
$stmt->bindValue($index++, (int) $perPage, PDO::PARAM_INT);
$stmt->bindValue($index++, (int) $offset, PDO::PARAM_INT);
$stmt->execute();
$sales = $stmt->fetchAll();

// Today's takings, independent of the filters above so the figures always
// describe the whole day at the counter.
$todayStart = date('Y-m-d 00:00:00');
$todayStmt = $pdo->prepare(
    "SELECT COUNT(*) AS sale_count,
            COALESCE(SUM(o.total_amount - o.discount_amount), 0) AS billed,
            COALESCE(SUM(o.balance_amount), 0) AS owing
     FROM orders o
     WHERE o.channel = 'pos' AND o.created_at >= ?"
);
$todayStmt->execute([$todayStart]);
$today = $todayStmt->fetch() ?: ['sale_count' => 0, 'billed' => 0, 'owing' => 0];

$paginationParams = [];
if ($search !== '') {
    $paginationParams['q'] = $search;
}
if ($methodFilter !== '') {
    $paginationParams['method'] = $methodFilter;
}

$listUrl = static function (array $overrides = []) use ($paginationParams): string {
    $params = array_merge($paginationParams, $overrides);

    return url('/admin/pos/sales.php' . ($params ? '?' . http_build_query($params) : ''));
};

include __DIR__ . '/../../includes/admin_layout_start.php';
?>
<div class="account-shell">
    <div class="account-summary">
        <div class="account-summary-main">
            <div class="account-summary-meta">Register</div>
            <div>
                <div class="section-kicker">Point of sale</div>
                <h2>Till sales</h2>
                <p>Every sale rung up at the counter, with its receipt and the order behind it.</p>
            </div>
        </div>
        <a href="<?= url('/admin/pos/index.php') ?>" class="btn btn-primary"><i class="bi bi-upc-scan me-1" aria-hidden="true"></i>Open the till</a>
    </div>

    <div class="request-stat-grid">
        <div class="request-stat-card">
            <div class="label">Sales today</div>
            <div class="value"><?= (int) $today['sale_count'] ?></div>
        </div>
        <div class="request-stat-card is-success">
            <div class="label">Billed today</div>
            <div class="value"><?= money($today['billed']) ?></div>
        </div>
        <div class="request-stat-card is-alert">
            <div class="label">On credit today</div>
            <div class="value"><?= money($today['owing']) ?></div>
        </div>
        <div class="request-stat-card">
            <div class="label">Matching filter</div>
            <div class="value"><?= $filteredTotal ?></div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>Sales register</h2>
                <p class="admin-list-subtitle"><?= $filteredTotal ?> sale<?= $filteredTotal === 1 ? '' : 's' ?><?= $search !== '' || $methodFilter !== '' ? ' matching your filters' : '' ?>.</p>
            </div>
        </div>
        <div class="panel-body">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
                <div class="filter-bar" style="margin-bottom: 0;">
                    <a href="<?= $listUrl(['method' => null]) ?>" class="btn btn-<?= $methodFilter === '' ? 'primary' : 'outline' ?> btn-sm">All</a>
                    <?php foreach ($availableMethods as $methodKey => $methodLabel): ?>
                        <a href="<?= $listUrl(['method' => $methodKey]) ?>"
                           class="btn btn-<?= $methodFilter === $methodKey ? 'primary' : 'outline' ?> btn-sm"><?= e($methodLabel) ?></a>
                    <?php endforeach; ?>
                </div>
                <form method="get" action="<?= url('/admin/pos/sales.php') ?>" style="display: flex; gap: 8px; align-items: center;">
                    <?php if ($methodFilter !== ''): ?>
                        <input type="hidden" name="method" value="<?= e($methodFilter) ?>">
                    <?php endif; ?>
                    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Receipt, customer, amount..." class="form-input form-input-sm" style="min-width: 240px;">
                    <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-search me-1" aria-hidden="true"></i>Search</button>
                    <?php if ($search !== ''): ?>
                        <a href="<?= $listUrl(['q' => null]) ?>" class="btn btn-outline btn-sm">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="panel-body flush">
            <table>
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>When</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th>Method</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Served by</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales as $sale): ?>
                        <?php
                        $saleTotal = max(0.0, (float) $sale['total_amount'] - (float) $sale['discount_amount']);
                        $saleBalance = (float) $sale['balance_amount'];
                        $saleMethod = (string) $sale['method'];
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($sale['order_number'] ?: ('#' . $sale['id'])) ?></strong>
                                <?php if ((float) $sale['discount_amount'] > 0.005): ?>
                                    <div class="muted" style="font-size: .78rem;"><?= money($sale['discount_amount']) ?> discount</div>
                                <?php endif; ?>
                            </td>
                            <td><?= display_date($sale['created_at']) ?></td>
                            <td>
                                <?= e(pos_sale_customer_name($sale)) ?>
                                <?php if (pos_is_walk_in_sale($sale)): ?>
                                    <span class="badge badge-neutral">Walk-in</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $sale['unit_count'] ?> unit<?= (int) $sale['unit_count'] === 1 ? '' : 's' ?> in <?= (int) $sale['line_count'] ?> line<?= (int) $sale['line_count'] === 1 ? '' : 's' ?></td>
                            <td><?= e($saleMethod !== '' ? pos_payment_method_label($saleMethod) : '—') ?></td>
                            <td><?= money($saleTotal) ?></td>
                            <td><?= money($sale['paid']) ?></td>
                            <td>
                                <?php if ($saleBalance > 0.005): ?>
                                    <span class="badge badge-warning"><?= money($saleBalance) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-success">Settled</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($sale['cashier_name'] ?: '—') ?></td>
                            <td class="payment-actions-cell">
                                <a href="<?= url('/admin/pos/receipt.php?order=' . (int) $sale['id']) ?>" class="btn btn-outline btn-sm">Receipt</a>
                                <a href="<?= url('/admin/orders/view.php?id=' . (int) $sale['id']) ?>" class="btn btn-outline btn-sm">Order</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$sales): ?>
                        <tr>
                            <td colspan="10" class="empty-state">
                                <?= $search !== '' || $methodFilter !== '' ? 'No sales match your filters.' : 'No sales have been rung up yet. They appear here as soon as the till completes one.' ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="panel-body">
                <?php render_pagination($page, $totalPages, '/admin/pos/sales.php', $paginationParams); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>

