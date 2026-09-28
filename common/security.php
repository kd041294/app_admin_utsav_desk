<?php

declare(strict_types=1);

function isHttpsRequest(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
}

function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Content-Security-Policy: default-src \'self\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'; object-src \'none\'; script-src \'self\' \'unsafe-inline\' https://code.jquery.com https://cdn.jsdelivr.net; style-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src \'self\' https://fonts.gstatic.com https://cdn.jsdelivr.net; connect-src \'self\'; img-src \'self\' data:');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if (isHttpsRequest()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function startSecureSession(): void
{
    require_once __DIR__ . '/settings.php';
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('UTSAVDESK_ADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isHttpsRequest() || (defined('APP_ENV') && APP_ENV !== 'local'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $lastActivity = isset($_SESSION['LAST_ACTIVITY']) ? (int) $_SESSION['LAST_ACTIVITY'] : 0;

    $idleTimeout = defined('SESSION_IDLE_TIMEOUT') ? (int) SESSION_IDLE_TIMEOUT : 1800;
    $policy = platformSettingsRuntime();
    $idleTimeout = max(5, min(240, (int)$policy['session_timeout'])) * 60;

    if ($lastActivity > 0 && time() - $lastActivity > $idleTimeout) {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    $_SESSION['LAST_ACTIVITY'] = time();
}

function csrfToken(): string
{
    if (empty($_SESSION['CSRF_TOKEN'])) {
        $_SESSION['CSRF_TOKEN'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['CSRF_TOKEN'];
}

function verifyCsrfToken(?string $token): bool
{
    return isset($_SESSION['CSRF_TOKEN'])
        && is_string($token)
        && hash_equals((string) $_SESSION['CSRF_TOKEN'], $token);
}
