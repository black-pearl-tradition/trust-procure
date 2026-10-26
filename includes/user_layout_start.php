<?php

/**
 * User area layout — sidebar, topbar, opens <main class="app-content">.
 * Requires: $pageTitle, and that auth_check.php + require_user() already ran.
 */
$userNav = [
    'Overview' => [
        ['label' => 'Dashboard', 'href' => '/user/dashboard.php', 'match' => 'dashboard.php'],
    ],
    'Procurement' => [
        ['label' => 'My Requests', 'href' => '/user/requests/index.php', 'match' => 'requests'],
        ['label' => 'My Orders', 'href' => '/user/orders/index.php', 'match' => 'orders'],
        ['label' => 'Payments', 'href' => '/user/payments/index.php', 'match' => 'payments'],
        ['label' => 'Request cart', 'href' => '/user/cart/index.php', 'match' => 'cart'],
    ],
    'Communication' => [
        ['label' => 'Notifications', 'href' => '/user/notifications/index.php', 'match' => 'notifications'],
        ['label' => 'Messages', 'href' => '/user/messages/index.php', 'match' => 'messages'],
    ],
];

$userNavIcons = [
    'Dashboard' => 'speedometer2',
    'My Requests' => 'clipboard-check',
    'My Orders' => 'bag-check',
    'Payments' => 'credit-card',
    'Request cart' => 'cart3',
    'Notifications' => 'bell',
    'Messages' => 'chat-dots',
];
$currentPath = $_SERVER['SCRIPT_NAME'];
$notificationCountStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$notificationCountStmt->execute([$_SESSION['user_id']]);
$unreadNotifications = (int) $notificationCountStmt->fetchColumn();

// Count unread admin replies
$userEmailStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
$userEmailStmt->execute([$_SESSION['user_id']]);
$userEmail = $userEmailStmt->fetchColumn();
$unreadRepliesStmt = $pdo->prepare("SELECT COUNT(*) FROM contact_messages WHERE email = ? AND admin_reply IS NOT NULL AND is_read = 0");
$unreadRepliesStmt->execute([$userEmail]);
$unreadReplies = (int) $unreadRepliesStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) : 'My Account' ?> — Trust Procurement</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('/assets/css/style.css?v=20261026-header-height') ?>">
    <script defer src="<?= url('/assets/js/navigation.js?v=20261014-shared-dropdown') ?>"></script>
</head>

<body>
    <?php include __DIR__ . '/partials/system_notifications.php'; ?>
    <div class="app-shell">
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
        <aside class="app-sidebar shadow-sm" id="appSidebar">
            <a href="<?= url('/user/dashboard.php') ?>" class="brand"><i class="bi bi-box-seam me-2" aria-hidden="true"></i>Trust<span>Procure</span></a>
            <nav>
                <?php foreach ($userNav as $navSectionLabel => $navSectionLinks): ?>
                    <div class="sidebar-label"><?= e($navSectionLabel) ?></div>
                    <?php foreach ($navSectionLinks as $navLink): ?>
                        <a href="<?= url($navLink['href']) ?>" class="<?= str_contains($currentPath, $navLink['match']) ? 'active' : '' ?>">
                            <i class="bi bi-<?= e($userNavIcons[$navLink['label']] ?? 'circle') ?> sidebar-nav-icon" aria-hidden="true"></i>
                            <span><?= e($navLink['label']) ?></span>
                            <?php if ($navLink['label'] === 'Notifications' && $unreadNotifications > 0): ?> <span class="badge badge-warning" style="margin-left: auto;"><?= $unreadNotifications ?></span><?php endif; ?>
                            <?php if ($navLink['label'] === 'Messages' && $unreadReplies > 0): ?> <span class="badge badge-danger" style="margin-left: auto;"><?= $unreadReplies ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-icon">
                        <img class="sidebar-user-avatar" src="<?= e(user_profile_picture_url($_SESSION['user_profile_image'] ?? null)) ?>" alt="Profile picture">
                    </div>
                    <div class="sidebar-user-info">
                        <strong><?= e($_SESSION['user_name'] ?? 'User') ?></strong>
                        <small>Signed in</small>
                    </div>
                </div>
                <div class="sidebar-actions">
                    <a href="<?= url('/account.php') ?>" class="btn btn-outline-light btn-sm w-100"><i class="bi bi-gear me-1" aria-hidden="true"></i>My account</a>
                    <a href="<?= url('/products.php') ?>" class="btn btn-outline-light btn-sm w-100"><i class="bi bi-grid me-1" aria-hidden="true"></i>Browse products</a>
                    <a href="<?= url('/index.php') ?>" class="btn btn-outline-light btn-sm w-100"><i class="bi bi-globe2 me-1" aria-hidden="true"></i>View site</a>
                    <a href="<?= url('/logout.php') ?>" class="btn btn-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Log out</a>
                </div>
            </div>
        </aside>
        <div class="app-main">
            <div class="app-topbar">
                <div class="topbar-left">
                    <button class="mobile-menu-toggle" id="sidebarToggle" type="button" aria-label="Toggle navigation" aria-controls="appSidebar" aria-expanded="false"><i class="bi bi-list" aria-hidden="true"></i></button>
                    <h1><?= e($pageTitle ?? 'My Account') ?></h1>
                </div>
                <div class="topbar-user">
                    <?php
                    $bellUrls = [
                        'feed' => '/user/notifications/feed.php',
                        'read' => '/user/notifications/read.php',
                        'mark_all' => '/user/notifications/mark_read_ajax.php',
                        'inbox' => '/user/notifications/index.php',
                    ];
                    include __DIR__ . '/partials/topbar_bell.php';
                    ?>
                    <img class="topbar-user-avatar" src="<?= e(user_profile_picture_url($_SESSION['user_profile_image'] ?? null)) ?>" alt="Profile picture">
                    <strong><?= e($_SESSION['user_name'] ?? 'User') ?></strong>
                </div>
            </div>
            <div class="app-content">
                <?php render_flashes(); ?>