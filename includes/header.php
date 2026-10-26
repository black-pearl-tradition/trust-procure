<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth_check.php';

$currentPage  = basename($_SERVER['SCRIPT_NAME']);
$compareCount = count(get_compare_list());
$isLoggedIn   = !empty($_SESSION['user_id']);
$userRole     = $_SESSION['role'] ?? 'user';
$isAdmin      = in_array($userRole, ['admin', 'super_admin', 'staff'], true);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' — Trust Procurement' : 'Trust Procurement' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('/assets/css/style.css?v=20261026-mobile-public-navigation') ?>">
    <script defer src="<?= url('/assets/js/navigation.js?v=20261026-mobile-public-navigation') ?>"></script>
    <script defer src="<?= url('/assets/js/system-notifications.js?v=20261014') ?>"></script>
</head>

<body>
    <?php include __DIR__ . '/partials/system_notifications.php'; ?>

    <header class="site-header">
        <div class="container">
            <a href="<?= url('/index.php') ?>" class="brand">
                <i class="bi bi-box-seam" aria-hidden="true"></i>Trust<span>Procure</span>
            </a>

            <button type="button" class="public-menu-toggle" id="publicMenuToggle" aria-label="Open site navigation" aria-controls="publicNav" aria-expanded="false">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>

            <nav class="main-nav" id="publicNav">
                <a href="<?= url('/index.php') ?>" class="<?= $currentPage === 'index.php' ? 'active' : '' ?>" <?= $currentPage === 'index.php' ? 'aria-current="page"' : '' ?>>Home</a>
                <a href="<?= url('/products.php') ?>" class="<?= in_array($currentPage, ['products.php', 'product_details.php'], true) ? 'active' : '' ?>" <?= in_array($currentPage, ['products.php', 'product_details.php'], true) ? 'aria-current="page"' : '' ?>>Products</a>
                <a href="<?= url('/about.php') ?>" class="<?= $currentPage === 'about.php' ? 'active' : '' ?>" <?= $currentPage === 'about.php' ? 'aria-current="page"' : '' ?>>About</a>
                <a href="<?= url('/contact.php') ?>" class="<?= $currentPage === 'contact.php' ? 'active' : '' ?>" <?= $currentPage === 'contact.php' ? 'aria-current="page"' : '' ?>>Contact</a>
            </nav>

            <div class="nav-actions">
                <?php if ($isLoggedIn): ?>
                    <div class="user-menu-wrap">
                        <button type="button" class="header-user-button" aria-label="Open account menu" aria-expanded="false">
                            <img class="header-user-avatar" src="<?= e(user_profile_picture_url($_SESSION['user_profile_image'] ?? null)) ?>" alt="Profile picture">
                            <span class="header-user-meta">
                                <span class="header-user-name"><?= e($_SESSION['user_name'] ?? 'User') ?></span>
                                <small><?= e(role_label($userRole)) ?></small>
                            </span>
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </button>
                        <div class="user-menu-dropdown" role="menu" aria-label="Account menu">
                            <a href="<?= url('/account.php') ?>" role="menuitem">Edit profile</a>
                            <a href="<?= url('/account.php') ?>" role="menuitem">My account</a>
                            <a href="<?= url($isAdmin ? '/admin/dashboard.php' : '/user/dashboard.php') ?>" role="menuitem">My dashboard</a>
                            <a href="<?= url('/logout.php') ?>" role="menuitem">Log out</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="<?= url('/login.php') ?>" class="btn btn-outline btn-sm">Log In</a>
                    <a href="<?= url('/register.php') ?>" class="btn btn-accent btn-sm">Get Started</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Utility toolbar (search & compare) -->
    <div class="header-toolbar">
        <div class="container">
            <form method="get" action="<?= url('/products.php') ?>" class="header-search" role="search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" placeholder="Search products by name or brand…" value="<?= e($_GET['q'] ?? '') ?>" aria-label="Search products">
                <button type="submit">Search</button>
            </form>

            <a href="<?= url('/compare.php') ?>" class="compare-btn">
                <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                <span>Compare</span>
                <?php if ($compareCount > 0): ?>
                    <span class="badge badge-warning"><?= $compareCount ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <main>
        <div class="container" style="padding-top: 24px;">
            <?php render_flashes(); ?>
        </div>