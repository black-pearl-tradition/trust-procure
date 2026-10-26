<?php

/** Store a one-time flash message in the session. */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Retrieve and clear all pending flash messages. */
function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/** Render flash messages as HTML alerts. */
function render_flashes(): void
{
    foreach (get_flashes() as $flash) {
        $type = $flash['type'] === 'error' ? 'error' : $flash['type'];
        echo '<div class="alert alert-' . e($type) . '">' . e($flash['message']) . '</div>';
    }
}
