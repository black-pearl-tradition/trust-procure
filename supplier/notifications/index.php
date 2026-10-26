<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_supplier();

$pageTitle = 'Notifications';
$stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();
$unreadCount = count(array_filter($notifications, static fn(array $notification): bool => (int) $notification['is_read'] === 0));

function supplier_notification_type(string $title): string
{
    $title = strtolower($title);
    if (str_contains($title, 'declined') || str_contains($title, 'rejected') || str_contains($title, 'cancelled') || str_contains($title, 'failed')) {
        return 'danger';
    }
    if (str_contains($title, 'sent') || str_contains($title, 'approved') || str_contains($title, 'received') || str_contains($title, 'completed')) {
        return 'success';
    }
    return 'info';
}

include __DIR__ . '/../../includes/supplier_layout_start.php';
?>

<div class="panel notifications-page">
    <div class="panel-head notifications-toolbar">
        <h2>Notifications</h2>
        <div class="notifications-actions">
            <span class="badge badge-warning"><?= $unreadCount ?> unread</span>
            <?php if ($notifications): ?>
                <form method="post" action="<?= url('/supplier/notifications/clear.php') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Clear all notifications?"><i class="bi bi-trash3 me-1" aria-hidden="true"></i>Clear all</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="panel-body">
        <?php if (!$notifications): ?>
            <div class="notifications-empty">
                <span class="notifications-empty-icon"><i class="bi bi-bell-slash" aria-hidden="true"></i></span>
                <h3>Nothing new here</h3>
                <p>Purchase orders placed with you will appear in this space.</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notification): ?>
                <div class="notification-card notification-<?= e(supplier_notification_type($notification['title'])) ?><?= (int) $notification['is_read'] === 0 ? ' is-unread' : '' ?>">
                    <div class="notification-card-header">
                        <div>
                            <strong><?= e($notification['title']) ?></strong>
                            <?php if (!(int) $notification['is_read']): ?><span class="notification-new">New</span><?php endif; ?>
                        </div>
                        <time datetime="<?= e($notification['created_at']) ?>"><?= display_date($notification['created_at']) ?></time>
                    </div>
                    <div class="notification-card-body">
                        <p><?= e($notification['message']) ?></p>
                        <form method="post" action="<?= url('/supplier/notifications/read.php') ?>" class="notification-read-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $notification['id'] ?>">
                            <button type="submit" class="notification-view-link">
                                <?= $notification['link'] ? 'View details' : 'Mark as read' ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/supplier_layout_end.php'; ?>
