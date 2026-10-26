<?php

/** Escape output for safe HTML rendering. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Get the full base URL (scheme + host + path) for building absolute URLs. */
function base_url(): string
{
    if (!defined('BASE_URL')) {
        define('BASE_URL', '');
    }

    if (BASE_URL === '') {
        return '';
    }

    // If BASE_URL is already a full URL (has a scheme), use it as-is
    if (strpos(BASE_URL, '://') !== false) {
        return BASE_URL;
    }

    // Build full URL from the current request's scheme and host
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $port = '';

    // Include port only if it's non-standard
    if ($host && isset($_SERVER['SERVER_PORT'])) {
        $standardPort = ($scheme === 'https') ? 443 : 80;
        if ((int) $_SERVER['SERVER_PORT'] !== $standardPort) {
            $port = ':' . (int) $_SERVER['SERVER_PORT'];
        }
    }

    // Ensure BASE_URL starts with a slash
    $basePath = '/' . ltrim(BASE_URL, '/');

    return $scheme . '://' . $host . $port . $basePath;
}

/** Redirect to a path relative to the base URL and stop execution. */
function redirect(string $path): void
{
    header('Location: ' . base_url() . $path);
    exit;
}

/** Build a URL relative to the base URL. */
function url(string $path = ''): string
{
    return base_url() . $path;
}

/** Resolve an admin-entered link against the base URL without altering external URLs. */
function link_url(string $link): string
{
    $link = trim($link);
    if ($link === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#)~i', $link)) {
        return $link;
    }
    return url('/' . ltrim($link, '/'));
}

/** Format a number as Ugandan Shillings. */
function money($amount): string
{
    return 'UGX ' . number_format((float) $amount, 0);
}

/** Format a date/time value for display. */
function display_date($datetime, string $format = 'd M Y, H:i'): string
{
    if (empty($datetime)) {
        return '—';
    }
    return date($format, strtotime($datetime));
}

/** Turn a string into a URL-friendly slug. */
function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    $text = strtolower($text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    return $text ?: 'item';
}
