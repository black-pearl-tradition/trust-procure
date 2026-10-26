<?php
$notificationUserId = (int) ($_SESSION['user_id'] ?? 0);
$notificationCursor = 0;
$notificationInboxUrl = '';

if ($notificationUserId > 0) {
    $cursorStmt = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?');
    $cursorStmt->execute([$notificationUserId]);
    $notificationCursor = (int) $cursorStmt->fetchColumn();

    $notificationInboxUrl = match ($_SESSION['role'] ?? '') {
        'staff', 'admin', 'super_admin' => '/admin/notifications/index.php',
        'supplier' => '/supplier/notifications/index.php',
        default => '/user/notifications/index.php',
    };
}
?>
<div class="system-toast-stack" id="systemToastStack" role="status" aria-live="polite" aria-relevant="additions"></div>
<?php if ($notificationUserId > 0): ?>
    <div id="systemNotificationFeed"
         data-feed-url="<?= url('/ajax/notifications-feed.php') ?>"
         data-inbox-url="<?= url($notificationInboxUrl) ?>"
         data-cursor="<?= $notificationCursor ?>"
         hidden></div>
<?php endif; ?>
