<?php

/**
 * SMTP configuration. Set these values as environment variables in production.
 * SMTP_ENCRYPTION accepts tls, ssl, or an empty value.
 */
define('SMTP_HOST', (string) (getenv('SMTP_HOST') ?: ''));
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_USERNAME', (string) (getenv('SMTP_USERNAME') ?: ''));
define('SMTP_PASSWORD', (string) (getenv('SMTP_PASSWORD') ?: ''));
define('SMTP_ENCRYPTION', strtolower((string) (getenv('SMTP_ENCRYPTION') ?: 'tls')));
define('MAIL_FROM_ADDRESS', (string) (getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@trustprocurement.local'));
define('MAIL_FROM_NAME', (string) (getenv('MAIL_FROM_NAME') ?: 'Trust Procurement System'));
