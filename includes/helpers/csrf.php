<?php

/**
 * CSRF protection.
 *
 * csrf_token() returns (creating if needed) a per-session random token.
 * csrf_field() prints it as a hidden form field — include this in every
 * form that changes data. verify_csrf() must be called at the top of every
 * script that handles such a POST, before any data is mutated; on failure
 * it flashes an error and redirects to $fallback rather than proceeding.
 */
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(string $fallback): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        set_flash('error', 'Your session expired or the request could not be verified. Please try again.');
        redirect($fallback);
    }
}
