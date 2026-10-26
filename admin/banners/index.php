<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_admin();

$pageTitle = 'Promotional Banners';

$banners = $pdo->query("SELECT * FROM promotional_banners ORDER BY sort_order, created_at DESC")->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>All banners</h2>
        <a href="<?= url('/admin/banners/add.php') ?>" class="btn btn-accent btn-sm">Add banner</a>
    </div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th></th><th>Title</th><th>Active window</th><th>Status</th><th>Order</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($banners as $b): ?>
                <tr>
                    <td>
                        <?php if ($b['image']): ?>
                            <div class="gallery-thumb" style="width: 64px;"><img src="<?= url('/uploads/banners/' . e($b['image'])) ?>" alt=""></div>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($b['title']) ?></td>
                    <td class="muted">
                        <?= $b['start_date'] ? display_date($b['start_date'], 'd M Y') : 'Any time' ?>
                        &rarr;
                        <?= $b['end_date'] ? display_date($b['end_date'], 'd M Y') : 'No end date' ?>
                    </td>
                    <td><span class="badge badge-<?= $b['status'] === 'active' ? 'success' : 'neutral' ?>"><?= ucfirst(e($b['status'])) ?></span></td>
                    <td><?= (int) $b['sort_order'] ?></td>
                    <td>
                        <div class="table-actions">
                            <a href="<?= url('/admin/banners/edit.php?id=' . $b['id']) ?>" class="btn btn-outline btn-sm">Edit</a>
                            <form method="post" action="<?= url('/admin/banners/delete.php') ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this banner?">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$banners): ?>
                    <tr><td colspan="6" class="empty-state">No banners yet. Add one to feature it on the homepage.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
