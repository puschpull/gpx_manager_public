<?php
/**
 * Security helpers: session, CSRF tokens, HTTP headers
 */

// --- Secure session startup ---

/**
 * Starts a session with hardened cookie parameters.
 * Safe to call multiple times — no-ops if session is already active.
 *
 * Cookie flags:
 *   - HttpOnly: JS cannot read the session cookie (mitigates XSS token theft)
 *   - SameSite=Lax: cookie not sent on cross-site POST (CSRF defense in depth)
 *   - Secure: cookie only sent over HTTPS (detected dynamically — allows local HTTP dev)
 */
function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    // HTTPS detection — on production (Webglobe) we force secure=true,
    // because the reverse proxy doesn't always set $_SERVER['HTTPS'].
    // On local (WAMP) we only enable it for real HTTPS so dev still works.
    $secure = (defined('APP_ENV') && APP_ENV !== 'local') || (
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// --- CSRF ---

function csrf_token(): string {
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

function csrf_verify(): bool {
    start_secure_session();
    $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// --- HTTP Security Headers ---

function send_security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
