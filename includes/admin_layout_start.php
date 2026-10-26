<?php

/**
 * Admin area layout — sidebar, topbar, opens <main class="app-content">.
 * Requires: $pageTitle, and that auth_check.php + require_staff() (or
 * stricter) already ran.
 */
$adminNav = [
    'Overview' => [
        ['label' => 'Dashboard', 'href' => '/admin/dashboard.php', 'match' => 'dashboard.php'],
    ],
    'Catalog' => [
        ['label' => 'Products', 'href' => '/admin/products/index.php', 'match' => 'products/index.php'],
        ['label' => 'Inventory', 'href' => '/admin/inventory/index.php', 'match' => 'inventory'],
        ['label' => 'Categories', 'href' => '/admin/categories/index.php', 'match' => 'categories'],
        ['label' => 'Suppliers', 'href' => '/admin/suppliers/index.php', 'match' => 'suppliers'],
        ['label' => 'Supplier Scorecards', 'href' => '/admin/suppliers/scorecards.php', 'match' => 'suppliers/scorecards.php'],
        ['label' => 'RFQ Comparison', 'href' => '/admin/suppliers/quotes.php', 'match' => 'suppliers/quotes.php'],
    ],
    'Operations' => [
        // First because it is what counter staff reach for all day. The match
        // string is the whole "pos/" directory, not a bare "pos", so the link
        // lights up on the till's own pages (index, receipt, sales, wallets)
        // without any future path that happens to contain those three letters.
        ['label' => 'Point of Sale', 'href' => '/admin/pos/index.php', 'match' => 'pos/'],
        ['label' => 'Customer Orders', 'href' => '/admin/orders/index.php', 'match' => 'orders'],
        ['label' => 'Approval Workflow', 'href' => '/admin/approvals/index.php', 'match' => 'approvals'],
        ['label' => 'Purchase Orders', 'href' => '/admin/purchase_orders/index.php', 'match' => 'purchase_orders'],
        ['label' => 'Goods Receiving', 'href' => '/admin/purchase_orders/receive.php', 'match' => 'purchase_orders/receive.php'],
        ['label' => 'Payments', 'href' => '/admin/payments/index.php', 'match' => 'payments'],
        ['label' => 'Notifications', 'href' => '/admin/notifications/index.php', 'match' => 'notifications'],
        ['label' => 'Messages', 'href' => '/admin/messages/index.php', 'match' => 'messages'],
    ],
    'Marketing' => [
        ['label' => 'Promotional Banners', 'href' => '/admin/banners/index.php', 'match' => 'banners'],
        ['label' => 'New Arrivals', 'href' => '/admin/products/new_arrivals.php', 'match' => 'new_arrivals'],
        ['label' => 'Product Reviews', 'href' => '/admin/reviews/index.php', 'match' => 'reviews'],
    ],
];

$adminNavIcons = [
    'Dashboard' => 'speedometer2',
    'Point of Sale' => 'upc-scan',
    'Products' => 'box-seam',
    'Inventory' => 'clipboard-data',
    'Categories' => 'collection',
    'Suppliers' => 'truck',
    'Supplier Scorecards' => 'clipboard-check',
    'RFQ Comparison' => 'bar-chart',
    'Supplier Product Submissions' => 'inbox',
    'Customer Orders' => 'bag-check',
    'Approval Workflow' => 'check2-square',
    'Goods Receiving' => 'upc-scan',
    'Payments' => 'credit-card-2-front',
    'Notifications' => 'bell',
    'Messages' => 'chat-left-text',
    'Promotional Banners' => 'images',
    'New Arrivals' => 'stars',
    'Product Reviews' => 'star',
    'Purchase Orders' => 'file-earmark-text',
    'Users' => 'people',
    'Reports' => 'bar-chart-line',
    'Audit Logs' => 'journal-text',
    'System Data' => 'database',
];

// Users, Reports, and Audit Logs hold sensitive account/business data —
// visible to admin and super_admin only, not staff.
if (is_admin()) {
    $adminNav['Catalog'][] = ['label' => 'Supplier Product Submissions', 'href' => '/admin/supplier_submissions/index.php', 'match' => 'supplier_submissions'];
    $adminNav['Administration'] = [
        ['label' => 'Users', 'href' => '/admin/users/index.php', 'match' => 'users'],
        ['label' => 'Reports', 'href' => '/admin/reports/index.php', 'match' => 'reports'],
        ['label' => 'Audit Logs', 'href' => '/admin/audit_logs/index.php', 'match' => 'audit_logs'],
    ];

    if (is_super_admin()) {
        $adminNav['Administration'][] = ['label' => 'System Data', 'href' => '/admin/system/index.php', 'match' => 'system'];
    }
}

$currentPath = $_SERVER['SCRIPT_NAME'];
$unreadMessages = (int) $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE is_read = 0")->fetchColumn();
$pendingReviews = (int) $pdo->query("SELECT COUNT(*) FROM product_reviews WHERE status = 'pending'")->fetchColumn();
$pendingPayments = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
$pendingPOs = is_admin() ? (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'submitted'")->fetchColumn() : 0;
$pendingSupplierSubmissions = is_admin()
    ? (int) $pdo->query("SELECT COUNT(*) FROM supplier_product_submissions WHERE status = 'pending'")->fetchColumn()
    : 0;
$unreadNotificationsStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadNotificationsStmt->execute([$_SESSION['user_id']]);
$unreadNotifications = (int) $unreadNotificationsStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' — Admin' : 'Admin' ?> — Trust Procurement</title>
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
            <a href="<?= url('/admin/dashboard.php') ?>" class="brand"><i class="bi bi-shield-check me-2" aria-hidden="true"></i>Trust<span>Procure</span></a>
            <nav>
                <?php foreach ($adminNav as $navSectionLabel => $navSectionLinks): ?>
                    <div class="sidebar-label"><?= e($navSectionLabel) ?></div>
                    <?php foreach ($navSectionLinks as $navLink): ?>
                        <a href="<?= url($navLink['href']) ?>" class="<?= str_contains($currentPath, $navLink['match']) ? 'active' : '' ?>">
                            <i class="bi bi-<?= e($adminNavIcons[$navLink['label']] ?? 'circle') ?> sidebar-nav-icon" aria-hidden="true"></i><span><?= e($navLink['label']) ?></span><?php
                                                                                                                                                                                        if ($navLink['label'] === 'Notifications' && $unreadNotifications > 0): ?> <span class="badge badge-warning" style="margin-left: 4px;"><?= $unreadNotifications ?></span><?php endif;
                                                                                                                                                                                                                                                                                                                                                                if ($navLink['label'] === 'Messages' && $unreadMessages > 0): ?> <span class="badge badge-warning" style="margin-left: 4px;"><?= $unreadMessages ?></span><?php endif;
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        if ($navLink['label'] === 'Payments' && $pendingPayments > 0): ?> <span class="badge badge-warning" style="margin-left: 4px;"><?= $pendingPayments ?></span><?php endif;
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    if ($navLink['label'] === 'Product Reviews' && $pendingReviews > 0): ?> <span class="badge badge-warning" style="margin-left: 4px;"><?= $pendingReviews ?></span><?php endif;
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        if ($navLink['label'] === 'Purchase Orders' && $pendingPOs > 0): ?> <span class="badge badge-warning" style="margin-left: 4px;"><?= $pendingPOs ?></span><?php endif;
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             if ($navLink['label'] === 'Supplier Product Submissions' && $pendingSupplierSubmissions > 0): ?> <span class="badge badge-warning" style="margin-left: 4px;"><?= $pendingSupplierSubmissions ?></span><?php endif;
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            ?>
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
                        <strong><?= e($_SESSION['user_name'] ?? 'Administrator') ?></strong>
                        <small><?= e(role_label(current_role())) ?></small>
                    </div>
                </div>
                <div class="sidebar-actions">
                    <a href="<?= url('/account.php') ?>" class="btn btn-outline-light btn-sm w-100"><i class="bi bi-gear me-1" aria-hidden="true"></i>My account</a>
                    <a href="<?= url('/index.php') ?>" class="btn btn-outline-light btn-sm w-100"><i class="bi bi-globe2 me-1" aria-hidden="true"></i>View site</a>
                    <a href="<?= url('/logout.php') ?>" class="btn btn-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Log out</a>
                </div>
            </div>
        </aside>
        <div class="app-main">
            <div class="app-topbar">
                <div class="topbar-left">
                    <button class="mobile-menu-toggle" id="sidebarToggle" type="button" aria-label="Toggle navigation" aria-controls="appSidebar" aria-expanded="false"><i class="bi bi-list" aria-hidden="true"></i></button>
                    <h1><?= e($pageTitle ?? 'Admin') ?></h1>
                </div>
                <div class="topbar-right">
                    <?php include __DIR__ . '/partials/topbar_bell.php'; ?>
                </div>
            </div>
            <div class="app-content">
                <?php render_flashes(); ?>