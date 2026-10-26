<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_user();

$pageTitle = 'Notifications';
$userId = $_SESSION['user_id'];

// Fetch notifications
$stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();
$unreadCount = count(array_filter($notifications, fn($notification) => (int) $notification['is_read'] === 0));

// Helper kept for backwards compatibility; prefer the shared
// admin_notification_type() helper (same classification everywhere).
function notification_type(string $title): string
{
    return admin_notification_type($title);
}

// Helper to get icon based on type
function notification_icon(string $type): string
{
    [$icon] = admin_notification_meta($type, 'system');
    return 'bi-' . $icon;
}

// Group notifications by date period
function period_label(string $created_at): string
{
    $timestamp = strtotime($created_at);
    $today = strtotime('today');
    $yesterday = strtotime('yesterday');
    $week_ago = strtotime('-7 days');

    if ($timestamp >= $today) {
        return 'Today';
    }
    if ($timestamp >= $yesterday) {
        return 'Yesterday';
    }
    if ($timestamp >= $week_ago) {
        return 'This Week';
    }
    return 'Older';
}

$grouped = [];
foreach ($notifications as $n) {
    $period = period_label($n['created_at']);
    $grouped[$period][] = $n;
}

include __DIR__ . '/../../includes/user_layout_start.php';
?>

<div class="panel notifications-page">
    <div class="panel-head notifications-toolbar">
        <h2>Notifications</h2>
        <div class="notifications-actions">
            <div class="notifications-tabs" role="tablist" aria-label="Notification filter">
                <button type="button" class="notification-filter is-active" data-filter="all" role="tab" aria-selected="true">All <span><?= count($notifications) ?></span></button>
                <button type="button" class="notification-filter" data-filter="unread" role="tab" aria-selected="false">Unread <span><?= $unreadCount ?></span></button>
            </div>
            <?php if (!empty($notifications)): ?>
                <form method="post" action="<?= url('/user/notifications/clear.php') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Clear all notifications?"><i class="bi bi-trash3 me-1" aria-hidden="true"></i>Clear all</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel-body">
        <?php if (empty($notifications)): ?>
            <div class="notifications-empty">
                <span class="notifications-empty-icon"><i class="bi bi-bell-slash" aria-hidden="true"></i></span>
                <h3>Nothing new here</h3>
                <p>Updates about your requests and orders will appear in this space.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped as $period => $items): ?>
                <div class="notification-group" data-notification-group>
                    <div class="notification-group-label"><?= e($period) ?></div>
                    <?php foreach ($items as $notification): ?>
                        <?php $type = notification_type($notification['title']); ?>
                        <?php $isUnread = (int) $notification['is_read'] === 0; ?>
                        <div class="notification-card notification-<?= $type ?><?= $isUnread ? ' is-unread' : '' ?>" data-notification="<?= $isUnread ? 'unread' : 'read' ?>">
                            <div class="notification-card-header">
                                <div>
                                    <i class="bi <?= notification_icon($type) ?> notification-card-icon" aria-hidden="true"></i>
                                    <strong><?= e($notification['title']) ?></strong>
                                    <?php if ($isUnread): ?><span class="notification-new">New</span><?php endif; ?>
                                </div>
                                <time datetime="<?= e($notification['created_at']) ?>"><?= display_date($notification['created_at']) ?></time>
                            </div>
                            <div class="notification-card-body">
                                <p><?= e($notification['message']) ?></p>
                                <?php if ($notification['link']): ?>
                                    <form method="post" action="<?= url('/user/notifications/read.php') ?>" class="notification-read-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $notification['id'] ?>">
                                        <button type="submit" class="notification-view-link">View details <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= url('/user/notifications/read.php') ?>" class="notification-read-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $notification['id'] ?>">
                                        <button type="submit" class="notification-view-link">Mark as read <i class="bi bi-check2" aria-hidden="true"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    document.querySelectorAll('.notification-filter').forEach(function(filter) {
        filter.addEventListener('click', function() {
            var selected = filter.dataset.filter;
            document.querySelectorAll('.notification-filter').forEach(function(item) {
                var active = item === filter;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            document.querySelectorAll('[data-notification-group]').forEach(function(group) {
                var visible = 0;
                group.querySelectorAll('[data-notification]').forEach(function(item) {
                    var show = selected === 'all' || item.dataset.notification === selected;
                    item.hidden = !show;
                    if (show) visible++;
                });
                group.hidden = visible === 0;
            });
        });
    });
</script>

<?php include __DIR__ . '/../../includes/user_layout_end.php'; ?>