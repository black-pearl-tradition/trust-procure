<?php

require_once __DIR__ . '/../includes/functions.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERTION FAILED: ' . $message);
    }
}

assert_true(function_exists('user_profile_picture_url'), 'The user profile picture helper should exist.');
assert_true(function_exists('user_profile_picture_path'), 'The user profile picture path helper should exist.');

$emptyUrl = user_profile_picture_url('');
$defaultUrl = user_profile_picture_url(null);
$missingFileUrl = user_profile_picture_url('ghost-user.png');
assert_true($emptyUrl === '/assets/images/default-avatar.svg', 'Empty profile picture should fall back to the default avatar.');
assert_true($defaultUrl === '/assets/images/default-avatar.svg', 'Null profile picture should fall back to the default avatar.');
assert_true($missingFileUrl === '/assets/images/default-avatar.svg', 'Missing profile images should fall back to the default avatar.');

echo "User profile picture helper tests passed.\n";
