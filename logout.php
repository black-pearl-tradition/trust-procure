<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    log_activity($pdo, $_SESSION['user_id'], 'logout', 'User logged out');
}

$_SESSION = [];
session_destroy();

session_start();
set_flash('success', 'You have been logged out.');
redirect('/login.php');
