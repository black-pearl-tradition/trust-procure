<?php

/**
 * Database connection
 * Trust Procurement System
 */

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'procure_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('APP_URL', rtrim((string) (getenv('APP_URL') ?: ''), '/'));

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
}

// Base URL used to build links and asset paths across the app.
// Auto-detected from the project's location relative to the web root, so the
// app keeps working whether it's installed at the domain root, in a
// subfolder, or under a renamed folder (e.g. XAMPP's htdocs).
$projectRoot = str_replace('\\', '/', dirname(__DIR__));
$documentRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
$basePath = '';
if ($documentRoot !== '' && strpos($projectRoot, $documentRoot) === 0) {
    $basePath = substr($projectRoot, strlen($documentRoot));
}
define('BASE_URL', $basePath);

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);

    $dbHost = htmlspecialchars((string) DB_HOST, ENT_QUOTES, 'UTF-8');
    $dbName = htmlspecialchars((string) DB_NAME, ENT_QUOTES, 'UTF-8');
    $dbUser = htmlspecialchars((string) DB_USER, ENT_QUOTES, 'UTF-8');
    $message = 'Database connection failed. Check that MySQL is running and that the database "' . $dbName . '" exists. If this is a fresh install, import config/schema.sql or run config/migrations.sql first.';

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Connection details: host=' . DB_HOST . ', db=' . DB_NAME . ', user=' . DB_USER . PHP_EOL);
        exit(1);
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Database connection failed</title><style>body{font-family:Arial,sans-serif;max-width:720px;margin:48px auto;padding:0 20px;color:#1f2937}h1{margin-bottom:12px}code{background:#f3f4f6;padding:2px 6px;border-radius:4px}p{line-height:1.6}</style></head><body>';
    echo '<h1>Database connection failed</h1>';
    echo '<p>' . $message . '</p>';
    echo '<p>Configured connection: <code>host=' . $dbHost . '</code>, <code>db=' . $dbName . '</code>, <code>user=' . $dbUser . '</code></p>';
    echo '<p>For a local XAMPP setup, confirm MySQL is started and run <code>mysql -u root -p &lt; config/schema.sql</code> to create the required tables.</p>';
    echo '</body></html>';
    exit;
}
