<?php

/** Google OAuth configuration and helpers. */
function google_oauth_config(): array
{
    $redirectUri = getenv('GOOGLE_REDIRECT_URI') ?: (BASE_URL . '/google_callback.php');
    return [
        'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
        'redirect_uri' => $redirectUri,
    ];
}

function google_oauth_enabled(): bool
{
    $config = google_oauth_config();
    return $config['client_id'] !== ''
        && $config['client_secret'] !== ''
        && $config['redirect_uri'] !== ''
        && function_exists('curl_init');
}

function google_oauth_authorization_url(): string
{
    $config = google_oauth_config();
    $_SESSION['google_oauth_state'] = bin2hex(random_bytes(32));
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $_SESSION['google_oauth_state'],
        'access_type' => 'online',
        'prompt' => 'select_account',
    ]);
}

function google_oauth_post(string $url, array $fields): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for Google sign-in.');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    $data = json_decode((string) $response, true);
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        throw new RuntimeException('Google sign-in could not be completed.');
    }
    return $data;
}

function google_oauth_get(string $url, string $accessToken): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for Google sign-in.');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $accessToken],
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    $data = json_decode((string) $response, true);
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        throw new RuntimeException('Google account details could not be read.');
    }
    return $data;
}
