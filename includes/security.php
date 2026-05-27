<?php
declare(strict_types=1);

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
    // X-XSS-Protection intentionally omitted — deprecated and harmful in some browsers (SEC-021)
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');

    // HSTS only on production — local dev runs plain HTTP (SEC-021)
    if (defined('APP_ENV') && APP_ENV !== 'local') {
        header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
    }

    // CSP — covers all CDN domains used in the application (SEC-021)
    // 'unsafe-inline' for script-src and style-src is required because the codebase
    // uses inline <script> blocks and style="" attributes throughout *.php templates.
    // 'unsafe-eval' is required by Alpine.js 3.x standard CDN build (uses new Function()
    // internally to evaluate x-data/x-show/x-bind expressions). Long-term fix: migrate
    // to Alpine CSP build (@alpinejs/csp) + Alpine.data() pattern — large refactor.
    // Long-term goal: replace with nonces (separate task — large refactor).
    $csp = "default-src 'self'; "
         . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://unpkg.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; "
         . "style-src 'self' 'unsafe-inline' https://unpkg.com https://fonts.googleapis.com; "
         . "img-src 'self' data: blob: https:; "
         . "font-src 'self' https://fonts.gstatic.com; "
         . "connect-src 'self' https://commons.wikimedia.org "
         . "https://*.tile.openstreetmap.org https://*.tile.opentopomap.org "
         . "https://server.arcgisonline.com https://api.mapy.com "
         . "https://*.tile.thunderforest.com https://tiles.mapillary.com "
         . "https://api.open-meteo.com https://archive-api.open-meteo.com; "
         . "frame-ancestors 'none'; "
         . "base-uri 'self'; "
         . "form-action 'self';";
    header("Content-Security-Policy: $csp");
}
