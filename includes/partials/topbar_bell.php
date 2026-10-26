<?php
/**
 * Shared topbar notification bell (admin + customer layouts).
 * Requires: $unreadNotifications (int) and optionally $bellUrls with
 * feed/read/mark_all/inbox keys. Falls back to admin URLs when omitted.
 */
$bellUrls = $bellUrls ?? [
    'feed' => '/admin/notifications/feed.php',
    'read' => '/admin/notifications/read.php',
    'mark_all' => '/admin/notifications/mark_read_ajax.php',
    'inbox' => '/admin/notifications/index.php',
];
$unreadBell = (int) ($unreadNotifications ?? 0);
?>
<div class="topbar-bell<?= $unreadBell > 0 ? ' has-unread' : '' ?>" id="topbarBell"
     data-feed-url="<?= url($bellUrls['feed']) ?>"
     data-read-url="<?= url($bellUrls['read']) ?>"
     data-mark-all-url="<?= url($bellUrls['mark_all']) ?>"
     data-inbox-url="<?= url($bellUrls['inbox']) ?>"
     data-csrf="<?= e(csrf_token()) ?>">
    <button class="topbar-bell-btn" id="topbarBellBtn" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
        <i class="bi bi-bell" aria-hidden="true"></i>
        <?php if ($unreadBell > 0): ?><span class="topbar-bell-dot" id="topbarBellDot"><?= $unreadBell > 9 ? '9+' : $unreadBell ?></span><?php endif; ?>
    </button>
    <div class="topbar-bell-menu" id="topbarBellMenu" role="menu" aria-label="Latest notifications" hidden>
        <div class="topbar-bell-head">
            <strong>Notifications</strong>
            <button type="button" class="topbar-bell-mark" id="topbarBellMarkAll">Mark all as read</button>
        </div>
        <div class="topbar-bell-list" id="topbarBellList">
            <div class="topbar-bell-loading">Loading…</div>
        </div>
        <a class="topbar-bell-foot" href="<?= url($bellUrls['inbox']) ?>">View all notifications<i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
</div>
