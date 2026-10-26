<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/google.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!google_oauth_enabled()) {
    set_flash('error', 'Google sign-in is not configured yet. Please use email and password.');
    redirect('/login.php');
}

header('Location: ' . google_oauth_authorization_url());
exit;
