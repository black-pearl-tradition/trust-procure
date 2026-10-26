<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Product Report';

$byCategory = $pdo->query(
    "SELECT c.name, COUNT(p.id) AS total, COALESCE(AVG(p.price),0) AS avg_price
     FROM categories c LEFT JOIN products p ON p.category_id = c.id
     GROUP BY c.id ORDER BY total DESC"
)->fetchAll();

$statusCounts = $pdo->query("SELECT status, COUNT(*) AS total FROM products GROUP BY status")->fetchAll();
$highEndCount = $pdo->query("SELECT COUNT(*) FROM products WHERE is_high_end = 1")->fetchColumn();
$totalProducts = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<a href="<?= url('/admin/reports/index.php') ?>" class="btn btn-outline btn-sm" style="margin-bottom: 20px;">Back to reports</a>
<a href="<?= url('/admin/reports/export.php?type=products') ?>" class="btn btn-accent btn-sm" style="margin-bottom: 20px; margin-left: 8px;">Export CSV</a>

<div class="stat-grid">
    <div class="stat-card"><div class="label">Total products</div><div class="value"><?= (int) $totalProducts ?></div></div>
    <div class="stat-card"><div class="label">High-end (deposit required)</div><div class="value"><?= (int) $highEndCount ?></div></div>
    <?php foreach ($statusCounts as $s): ?>
    <div class="stat-card"><div class="label"><?= ucfirst(e($s['status'])) ?></div><div class="value"><?= (int) $s['total'] ?></div></div>
    <?php endforeach; ?>
</div>

<div class="panel">
    <div class="panel-head"><h3>By category</h3></div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th>Category</th><th>Products</th><th>Average price</th></tr></thead>
            <tbody>
                <?php foreach ($byCategory as $row): ?>
                <tr><td><?= e($row['name']) ?></td><td><?= (int) $row['total'] ?></td><td><?= money($row['avg_price']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$byCategory): ?><tr><td colspan="3" class="empty-state">No categories yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
