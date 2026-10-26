<?php
require_once __DIR__ . '/../../includes/auth_check.php';
require_staff();

$pageTitle = 'Notifications';

$filter = $_GET['filter'] ?? 'all';
$allowedFilters = ['all', 'unread', 'payments', 'orders', 'system'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$allStmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
$allStmt->execute([$_SESSION['user_id']]);
$allNotifications = $allStmt->fetchAll();

$counts = ['all' => count($allNotifications), 'unread' => 0, 'payments' => 0, 'orders' => 0, 'system' => 0];
foreach ($allNotifications as $row) {
    if ((int) $row['is_read'] === 0) $counts['unread']++;
    $counts[admin_notification_category($row)]++;
}

$filtered = array_values(array_filter($allNotifications, static function (array $row) use ($filter): bool {
    if ($filter === 'unread') return (int) $row['is_read'] === 0;
    if ($filter === 'all') return true;
    return admin_notification_category($row) === $filter;
}));
$notifications = array_slice($filtered, 0, 50);
$unreadCount = $counts['unread'];

$filterLabels = ['all' => 'All', 'unread' => 'Unread', 'payments' => 'Payments', 'orders' => 'Orders', 'system' => 'System'];

include __DIR__ . '/../../includes/admin_layout_start.php';
?>

<div class="panel notifications-page">
    <div class="panel-head notifications-toolbar">
        <div>
            <h2 class="notifications-title">Notifications</h2>
            <p class="notifications-subtitle">Customer and system updates, grouped so urgent reviews surface first.</p>
        </div>
        <div class="notifications-actions">
            <span class="badge badge-warning"><?= $unreadCount ?> unread</span>
            <?php if ($unreadCount > 0): ?>
                <form method="post" action="<?= url('/admin/notifications/mark_all_read.php') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Mark all as read</button>
                </form>
            <?php endif; ?>
            <?php if ($allNotifications): ?>
                <form method="post" action="<?= url('/admin/notifications/clear.php') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Clear all notifications?"><i class="bi bi-trash3 me-1" aria-hidden="true"></i>Clear all</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="panel-body">
        <div class="notifications-tabs" role="tablist" aria-label="Filter notifications">
            <?php foreach ($filterLabels as $key => $label): ?>
                <a href="<?= url('/admin/notifications/index.php?filter=' . $key) ?>"
                   class="notification-filter <?= $filter === $key ? 'is-active' : '' ?>"
                   role="tab" aria-selected="<?= $filter === $key ? 'true' : 'false' ?>">
                    <?= e($label) ?><span><?= $counts[$key] ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!$notifications): ?>
            <div class="notifications-empty">
                <span class="notifications-empty-icon"><i class="bi bi-bell-slash" aria-hidden="true"></i></span>
                <h3><?= $filter === 'all' ? 'Nothing new here' : 'No ' . e(strtolower($filterLabels[$filter])) . ' notifications' ?></h3>
                <p><?= $filter === 'unread' ? 'You are all caught up.' : 'Customer and system updates will appear in this space.' ?></p>
            </div>
        <?php else: ?>
            <?php
            $grouped = ['unread' => [], 'read' => []];
            foreach ($notifications as $notification) {
                $grouped[(int) $notification['is_read'] === 0 ? 'unread' : 'read'][] = $notification;
            }
            ?>
            <?php foreach (['unread' => 'Needs attention', 'read' => 'Earlier'] as $groupKey => $groupLabel): ?>
                <?php if ($grouped[$groupKey]): ?>
                    <div class="notification-group-label"><?= $groupLabel ?> · <?= count($grouped[$groupKey]) ?></div>
                    <?php $loopIndex = 0; ?>
                    <?php foreach ($grouped[$groupKey] as $notification): ?>
                        <?php
                        $type = admin_notification_type($notification['title']);
                        $category = admin_notification_category($notification);
                        [$icon, $categoryLabel] = admin_notification_meta($type, $category);
                        $loopIndex++;
                        ?>
                        <div class="notification-card notification-<?= e($type) ?><?= (int) $notification['is_read'] === 0 ? ' is-unread' : '' ?>" style="animation: notif-in .35s ease both; animation-delay: <?= min($loopIndex * 45, 400) ?>ms">
                            <div class="notification-card-header">
                                <div>
                                    <span class="notification-card-icon" aria-hidden="true"><i class="bi bi-<?= e($icon) ?>"></i></span>
                                    <strong><?= e($notification['title']) ?></strong>
                                    <span class="badge badge-neutral" style="margin-left: 8px;"><?= e($categoryLabel) ?></span>
                                    <?php if (!(int) $notification['is_read']): ?><span class="notification-new">New</span><?php endif; ?>
                                </div>
                                <time datetime="<?= e($notification['created_at']) ?>"><?= display_date($notification['created_at']) ?></time>
                            </div>
                            <div class="notification-card-body">
                                <p><?= e($notification['message']) ?></p>
                                <form method="post" action="<?= url('/admin/notifications/read.php') ?>" class="notification-read-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $notification['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="margin-top: 12px;">
                                        <?= $notification['link'] ? 'View details' : 'Mark as read' ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/admin_layout_end.php'; ?>
