<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Messages';

$page = current_page();
$perPage = 20;
$filter = $_GET['filter'] ?? '';

$where = '';
if ($filter === 'unread') {
    $where = 'WHERE is_read = 0';
}

$total = (int) $pdo->query("SELECT COUNT(*) FROM contact_messages $where")->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$stmt = $pdo->prepare("SELECT * FROM contact_messages $where ORDER BY (is_read = 0) DESC, created_at DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$messages = $stmt->fetchAll();

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2>Contact messages</h2>
    </div>
    <div class="panel-body">
        <div class="table-actions">
            <a href="<?= url('/admin/messages/index.php') ?>" class="btn btn-<?= $filter === '' ? 'primary' : 'outline' ?> btn-sm">All</a>
            <a href="<?= url('/admin/messages/index.php?filter=unread') ?>" class="btn btn-<?= $filter === 'unread' ? 'primary' : 'outline' ?> btn-sm">Unread</a>
        </div>
    </div>
    <div class="panel-body flush">
        <table>
            <thead><tr><th></th><th>From</th><th>Subject</th><th>Received</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($messages as $m): ?>
                <tr>
                    <td><?php if (!$m['is_read']): ?><span class="badge badge-warning">New</span><?php endif; ?></td>
                    <td><?= e($m['name']) ?><div class="muted" style="font-size: 0.8rem;"><?= e($m['email']) ?></div></td>
                    <td><?= e($m['subject'] ?: '(no subject)') ?></td>
                    <td><?= display_date($m['created_at']) ?></td>
                    <td><a href="<?= url('/admin/messages/view.php?id=' . $m['id']) ?>" class="btn btn-outline btn-sm">Read</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$messages): ?>
                    <tr><td colspan="5" class="empty-state">No messages to show.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php render_pagination($page, $totalPages, '/admin/messages/index.php', ['filter' => $filter]); ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
