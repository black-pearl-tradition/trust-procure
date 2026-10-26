<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Suppliers';

$suppliers = $pdo->query(
    "SELECT s.*,
        (SELECT COUNT(*) FROM supplier_products sp WHERE sp.supplier_id = s.id) AS product_count,
        (SELECT COUNT(*) FROM orders o WHERE o.supplier_id = s.id) AS order_count
     FROM suppliers s ORDER BY s.name"
)->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>All suppliers</h2>
        <div class="table-actions">
            <a href="<?= url('/admin/suppliers/scorecards.php') ?>" class="btn btn-outline btn-sm">Supplier scorecards</a>
            <a href="<?= url('/admin/suppliers/quotes.php') ?>" class="btn btn-outline btn-sm">RFQ comparison</a>
            <a href="<?= url('/admin/suppliers/add.php') ?>" class="btn btn-accent btn-sm">Add supplier</a>
        </div>
    </div>
    <div class="panel-body flush">
        <table>
            <thead>
                <tr><th>Supplier</th><th>Contact</th><th>Products supplied</th><th>Orders fulfilled</th><th>Relationship</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($suppliers as $s): ?>
                <tr>
                    <td><?= e($s['name']) ?></td>
                    <td class="muted">
                        <?= e($s['contact_person'] ?: '—') ?>
                        <?php if ($s['phone']): ?><div style="font-size: 0.8rem;"><?= e($s['phone']) ?></div><?php endif; ?>
                    </td>
                    <td><?= (int) $s['product_count'] ?></td>
                    <td><?= (int) $s['order_count'] ?></td>
                    <td>
                        <?php $performance = $s['performance_status'] ?? 'watchlist'; ?>
                        <?php if ($performance === 'preferred'): ?>
                            <span class="badge badge-success">Preferred</span>
                        <?php elseif ($performance === 'needs_review'): ?>
                            <span class="badge badge-danger">Needs review</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Watchlist</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= $s['status'] === 'active' ? 'success' : 'neutral' ?>"><?= ucfirst(e($s['status'])) ?></span></td>
                    <td>
                        <div class="table-actions">
                            <a href="<?= url('/admin/suppliers/view.php?id=' . $s['id']) ?>" class="btn btn-outline btn-sm icon-action" aria-label="View <?= e($s['name']) ?>" title="View supplier"><i class="bi bi-eye" aria-hidden="true"></i></a>
                            <a href="<?= url('/admin/suppliers/edit.php?id=' . $s['id']) ?>" class="btn btn-outline btn-sm icon-action" aria-label="Edit <?= e($s['name']) ?>" title="Edit supplier"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                            <?php if ((int) $s['product_count'] === 0 && (int) $s['order_count'] === 0): ?>
                            <form method="post" action="<?= url('/admin/suppliers/delete.php') ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm icon-action" aria-label="Delete <?= e($s['name']) ?>" title="Delete supplier" data-confirm="Delete this supplier?"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$suppliers): ?>
                    <tr><td colspan="7" class="empty-state">No suppliers yet. Add your first supplier to start tracking sourcing.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($suppliers): ?>
<p class="muted" style="font-size: 0.85rem;">Suppliers with linked products or order history can't be deleted — deactivate them instead to keep their record intact.</p>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
