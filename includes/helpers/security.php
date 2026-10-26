<?php

/** Validate a password against the app's policy. */
function password_policy_errors(string $password): array
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }

    return $errors;
}

function validate_email_address(string $email): bool
{
    if ($email === '') {
        return false;
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $email) === 1) {
        return false;
    }

    $email = trim($email);
    if ($email === '') {
        return false;
    }

    if (preg_match('/\s/', $email) === 1) {
        return false;
    }

    if (strlen($email) > 254) {
        return false;
    }

    if (substr_count($email, '@') !== 1) {
        return false;
    }

    [$localPart, $domain] = explode('@', $email, 2);
    if ($localPart === '' || $domain === '') {
        return false;
    }

    if (str_starts_with($localPart, '.') || str_ends_with($localPart, '.') || preg_match('/\.\./', $localPart) === 1 || preg_match('/\.\./', $domain) === 1) {
        return false;
    }

    if (preg_match('/^(?:[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.-]+)$/', $localPart) !== 1) {
        return false;
    }

    if (str_starts_with($domain, '.') || str_ends_with($domain, '.') || preg_match('/\.$/', $domain) === 1) {
        return false;
    }

    if ($domain === 'localhost' || !preg_match('/\./', $domain) || !preg_match('/^(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,63}$/', $domain)) {
        return false;
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function normalize_upload_subfolder(string $subfolder): string
{
    $clean = preg_replace('/[^a-zA-Z0-9_-]+/', '', trim($subfolder));
    return $clean !== '' ? $clean : 'general';
}

/**
 * Validate a Y-m-d date string, returning it in canonical form, or null when the
 * value is empty or is not a real calendar date (e.g. 2026-02-31).
 */
function normalize_iso_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        return null;
    }

    return $value;
}

function login_attempts_key(string $email): string
{
    return 'login_attempts_' . md5(strtolower(trim($email)));
}

function login_attempts_exceeded(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    $key = login_attempts_key($email);
    $attempts = $_SESSION[$key] ?? [];
    $now = time();
    $attempts = array_values(array_filter(array_map('intval', $attempts), static fn(int $timestamp): bool => $timestamp > $now - 900));
    $_SESSION[$key] = $attempts;

    return count($attempts) >= 5;
}

function record_failed_login(string $email): void
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return;
    }

    $key = login_attempts_key($email);
    $attempts = $_SESSION[$key] ?? [];
    $attempts[] = time();
    $attempts = array_values(array_filter(array_map('intval', $attempts), static fn(int $timestamp): bool => $timestamp > time() - 900));
    $_SESSION[$key] = $attempts;
}

function clear_login_attempts(string $email): void
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return;
    }
    unset($_SESSION[login_attempts_key($email)]);
}
