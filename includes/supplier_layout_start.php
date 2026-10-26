<?php

/**
 * Supplier area layout — sidebar, topbar, opens <main class="app-content">.
 * Requires: $pageTitle, and that auth_check.php + require_supplier() already ran.
 */
$supplierNav = [
    'Overview' => [
        ['label' => 'Dashboard', 'href' => '/supplier/dashboard.php', 'match' => 'dashboard.php'],
    ],
    'Catalogue' => [
        ['label' => 'Products I Supply', 'href' => '/supplier/products/index.php', 'match' => 'products/index.php'],
        ['label' => 'Submit a Product', 'href' => '/supplier/products/submit.php', 'match' => 'products/submit.php'],
    ],
    'Orders' => [
        ['label' => 'Purchase Orders', 'href' => '/supplier/purchase_orders/index.php', 'match' => 'purchase_orders'],
    ],
    'Account' => [
        ['label' => 'My Profile', 'href' => '/supplier/profile/index.php', 'match' => 'profile'],
    ],
    'Communication' => [
        ['label' => 'Notifications', 'href' => '/supplier/notifications/index.php', 'match' => 'notifications'],
    ],
];

$supplierNavIcons = [
    'Dashboard' => 'speedometer2',
    'Products I Supply' => 'box-seam',
    'Submit a Product' => 'cloud-upload',
    'Purchase Orders' => 'file-earmark-text',
    'My Profile' => 'person-badge',
    'Notifications' => 'bell',
];

$currentPath = $_SERVER['SCRIPT_NAME'];
$supplierProfile = get_supplier_for_user((int) ($_SESSION['user_id'] ?? 0));
$pendingPOs = 0;
if ($supplierProfile) {
    $pendingPOsStmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ? AND status = 'sent' AND supplier_response = 'pending'");
    $pendingPOsStmt->execute([$supplierProfile['id']]);
    $pendingPOs = (int) $pendingPOsStmt->fetchColumn();
}
$unreadNotificationsStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadNotificationsStmt->execute([$_SESSION['user_id']]);
$unreadNotifications = (int) $unreadNotificationsStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' — Supplier Portal' : 'Supplier Portal' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('/assets/css/style.css?v=20261026-header-height') ?>">
    <script defer src="<?= url('/assets/js/navigation.js?v=20261014-shared-dropdown') ?>"></script>
</head>

<body>
    <?php include __DIR__ . '/partials/system_notifications.php'; ?>
    <div class="app-shell">
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
        <aside class="app-sidebar" id="appSidebar">
            <div class="sidebar-brand">
                <a href="<?= url('/supplier/dashboard.php') ?>" class="brand">
                    <i class="bi bi-truck me-2" aria-hidden="true"></i>Trust<span>Procure</span>
                </a>
            </div>
            <nav class="sidebar-nav">
                <?php foreach ($supplierNav as $sectionLabel => $sectionLinks): ?>
                    <?php if ($sectionLinks): ?>
                        <div class="sidebar-section">
                            <div class="sidebar-label"><?= e($sectionLabel) ?></div>
                            <?php foreach ($sectionLinks as $navLink): ?>
                                <a href="<?= e($navLink['href']) ?>"
                                    class="sidebar-link<?= str_contains($currentPath, $navLink['match']) ? ' active' : '' ?>">
                                    <i class="bi bi-<?= e($supplierNavIcons[$navLink['label']] ?? 'gear') ?> sidebar-nav-icon" aria-hidden="true"></i>
                                    <span><?= e($navLink['label']) ?></span>
                                    <?php if ($navLink['label'] === 'Notifications' && $unreadNotifications > 0): ?> <span class="badge badge-warning" style="margin-left: auto;"><?= $unreadNotifications ?></span><?php endif; ?>
                                    <?php if ($navLink['label'] === 'Purchase Orders' && $pendingPOs > 0): ?> <span class="badge badge-warning" style="margin-left: auto;"><?= $pendingPOs ?></span><?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-icon">
                        <img class="sidebar-user-avatar" src="<?= e(user_profile_picture_url($_SESSION['user_profile_image'] ?? null)) ?>" alt="Profile picture">
                    </div>
                    <div class="sidebar-user-info">
                        <strong><?= e($_SESSION['user_name'] ?? 'Supplier') ?></strong>
                        <small><?= e(role_label(current_role())) ?></small>
                    </div>
                </div>
                <div class="sidebar-actions">
                    <a href="<?= url('/index.php') ?>" class="btn btn-outline-light btn-sm w-100"><i class="bi bi-globe2 me-1" aria-hidden="true"></i>View site</a>
                    <a href="<?= url('/account.php') ?>" class="btn btn-outline-light btn-sm w-100"><i class="bi bi-gear me-1" aria-hidden="true"></i>My account</a>
                    <a href="<?= url('/logout.php') ?>" class="btn btn-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Log out</a>
                </div>
            </div>
        </aside>
        <div class="app-main">
            <div class="app-topbar">
                <div class="topbar-left">
                    <button class="mobile-menu-toggle" id="sidebarToggle" type="button" aria-label="Toggle navigation" aria-controls="appSidebar" aria-expanded="false"><i class="bi bi-list" aria-hidden="true"></i></button>
                    <h1><?= e($pageTitle ?? 'Supplier') ?></h1>
                </div>
                <div class="topbar-right">
                    <div class="topbar-bell<?= $unreadNotifications > 0 ? ' has-unread' : '' ?>">
                        <a class="topbar-bell-btn" href="<?= url('/supplier/notifications/index.php') ?>"
                            aria-label="Notifications<?= $unreadNotifications > 0 ? ', ' . $unreadNotifications . ' unread' : '' ?>">
                            <i class="bi bi-bell" aria-hidden="true"></i>
                            <?php if ($unreadNotifications > 0): ?><span class="topbar-bell-dot"><?= $unreadNotifications > 9 ? '9+' : $unreadNotifications ?></span><?php endif; ?>
                        </a>
                    </div>
                    <div class="topbar-user">
                        <img class="topbar-user-avatar" src="<?= e(user_profile_picture_url($_SESSION['user_profile_image'] ?? null)) ?>" alt="">
                        <strong><?= e($_SESSION['user_name'] ?? 'Supplier') ?></strong>
                    </div>
                </div>
            </div>
            <div class="app-content">
                <?php render_flashes(); ?>