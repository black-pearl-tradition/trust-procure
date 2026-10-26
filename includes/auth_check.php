<?php

/**
 * Session bootstrap and access-control helpers.
 * Included at the top of every protected page.
 *
 * Roles (least to most privileged): user (customer), supplier, staff, admin, super_admin.
 * - user:        public site + their own request/order/payment portal only.
 * - supplier:    supplier portal — dashboard, purchase orders, own profile and
 *                linked products. Suppliers can submit new products and quoted
 *                costs for admin review, but cannot set public catalog prices
 *                or publish products directly.
 * - staff:       the operational admin pages — products, categories,
 *                orders/purchase orders, payments, messages, reviews.
 * - admin:       everything staff can do, plus users, reports, audit logs,
 *                banners, and role management (except super_admin accounts).
 * - super_admin: everything, including managing other admin/super_admin
 *                accounts. Protected from deletion by other admins.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** True if a user is currently logged in. */
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/** The current user's role, or '' if not logged in. */
function current_role(): string
{
    return $_SESSION['role'] ?? '';
}

/** True if the logged-in user holds any of the given roles. */
function has_role(array $roles): bool
{
    return is_logged_in() && in_array(current_role(), $roles, true);
}

/** True for admin or super_admin — the full back-office area. */
function is_admin(): bool
{
    return has_role(['admin', 'super_admin']);
}

/** True for staff, admin, or super_admin — the operational admin pages. */
function is_staff_or_above(): bool
{
    return has_role(['staff', 'admin', 'super_admin']);
}

/** True for supplier, staff, admin, or super_admin. */
function is_supplier_or_above(): bool
{
    return has_role(['supplier', 'staff', 'admin', 'super_admin']);
}

/** True only for the super_admin role. */
function is_super_admin(): bool
{
    return has_role(['super_admin']);
}

/** True only for the supplier role. */
function is_supplier(): bool
{
    return has_role(['supplier']);
}

/** Human-readable label for a role, for display in the admin UI. */
function role_label(string $role): string
{
    $labels = [
        'super_admin' => 'Super Administrator',
        'admin'       => 'Administrator',
        'staff'       => 'Staff',
        'supplier'    => 'Supplier',
        'user'        => 'Customer',
    ];
    return $labels[$role] ?? ucfirst($role);
}

/**
 * Require any authenticated, active, non-deleted user; otherwise redirect
 * to login. Also re-checks the account's live status against the database
 * on every request, so a suspended or deleted account is logged out
 * immediately rather than waiting for the session to expire naturally.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        set_flash('error', 'Please log in to continue.');
        redirect('/login.php');
    }

    global $pdo;
    static $verified = false;
    if (!$verified) {
        $verified = true;
        $stmt = $pdo->prepare('SELECT role, status, deleted_at, session_version FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $account = $stmt->fetch();

        if (
            !$account || $account['deleted_at'] !== null || $account['status'] !== 'active'
            || (int) ($_SESSION['session_version'] ?? 0) !== (int) $account['session_version']
        ) {
            $_SESSION = [];
            session_destroy();
            session_start();
            set_flash('error', 'Your session has ended. Please log in again.');
            redirect('/login.php');
        }

        // Keep the session role in sync in case an admin changed it mid-session.
        $_SESSION['role'] = $account['role'];
        $_SESSION['session_version'] = (int) $account['session_version'];
    }
}

/** Require an administrator (admin or super_admin) account. */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        set_flash('error', 'You do not have permission to view that page.');
        redirect('/user/dashboard.php');
    }
}

/** Require staff, admin, or super_admin — the operational admin pages. */
function require_staff(): void
{
    require_login();
    if (!is_staff_or_above()) {
        set_flash('error', 'You do not have permission to view that page.');
        redirect('/user/dashboard.php');
    }
}

/** Require the super_admin role specifically. */
function require_super_admin(): void
{
    require_login();
    if (!is_super_admin()) {
        set_flash('error', 'Only a Super Administrator can do that.');
        redirect('/admin/dashboard.php');
    }
}

/** Require a standard customer account, keeping suppliers and staff/admins in their own areas. */
function require_user(): void
{
    require_login();
    if (is_staff_or_above()) {
        redirect('/admin/dashboard.php');
    }
    if (is_supplier()) {
        redirect('/supplier/dashboard.php');
    }
}

/** Require a supplier account. */
function require_supplier(): void
{
    require_login();
    if (!is_supplier()) {
        set_flash('error', 'You do not have permission to view that page.');
        redirect('/user/dashboard.php');
    }
}

/**
 * Redirect the current user to the appropriate dashboard based on their role.
 */
function redirect_user_by_role(): void
{
    $role = current_role();
    if ($role === 'supplier') {
        redirect('/supplier/dashboard.php');
    } elseif (in_array($role, ['staff', 'admin', 'super_admin'], true)) {
        redirect('/admin/dashboard.php');
    } else {
        redirect('/user/dashboard.php');
    }
}
