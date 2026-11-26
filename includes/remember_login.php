<?php
declare(strict_types=1);

/**
 * "Remember me" for the password login (security check 21 Nov 2026, S2:
 * in production the admin is only who logged in — no admin by IP).
 *
 * Cookie gpx_remember = "<expires>.<hmac>" — no server-side token table.
 * The HMAC key is derived from ADMIN_PASS_HASH and a counter in app_config
 * ('remember_epoch'):
 *   - changing the password invalidates every remembered device,
 *   - logging out bumps the counter → all remembered devices are logged out
 *     (lost phone: log out anywhere and the phone's cookie is worthless).
 */

// The HMAC key needs app_config → the database: public_access.php and auth.php
// load db.php (at global scope) before this file. Without $pdo the epoch falls
// back to '0' and remembered tokens simply do not match (safe failure).

const REMEMBER_COOKIE = 'gpx_remember';
const REMEMBER_DAYS   = 30;

function remember_key(): string
{
    $epoch = function_exists('get_app_config') ? (string) get_app_config('remember_epoch', '0') : '0';
    return hash('sha256', 'gpx-remember|' . ADMIN_USER . '|' . ADMIN_PASS_HASH . '|' . $epoch);
}

/** Token valid until $expires (unix time). */
function remember_token_create(int $expires): string
{
    return $expires . '.' . hash_hmac('sha256', (string) $expires, remember_key());
}

function remember_token_valid(string $token, ?int $now = null): bool
{
    if (ADMIN_PASS_HASH === '' || !preg_match('/^(\d{9,11})\.([0-9a-f]{64})$/', $token, $m)) {
        return false;
    }
    $expires = (int) $m[1];
    if ($expires < ($now ?? time())) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', (string) $expires, remember_key()), $m[2]);
}

function remember_cookie_secure(): bool
{
    return (defined('APP_ENV') && APP_ENV !== 'local')
        || (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
}

function remember_cookie_set(): void
{
    $expires = time() + REMEMBER_DAYS * 86400;
    setcookie(REMEMBER_COOKIE, remember_token_create($expires), [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => remember_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function remember_cookie_clear(): void
{
    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => remember_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Logout everywhere: every remembered device's cookie stops working. */
function remember_revoke_all(): void
{
    if (function_exists('set_app_config')) {
        set_app_config('remember_epoch', bin2hex(random_bytes(8)));
    }
}

/**
 * Called on every request after session start: a remembered device becomes
 * admin again (admin_via = 'login'). Not during visitor preview.
 */
function remember_try_login(): void
{
    if (!empty($_SESSION['is_admin']) || !empty($_SESSION['visitor_preview'])) {
        return;
    }
    $token = $_COOKIE[REMEMBER_COOKIE] ?? null;
    if (!is_string($token) || $token === '') {
        return;
    }
    if (!remember_token_valid($token)) {
        // Not cleared: a database hiccup must not log the device out for good
        return;
    }
    session_regenerate_id(true);
    $_SESSION['is_admin']  = true;
    $_SESSION['admin_via'] = 'login';
}

/**
 * In production an admin session obtained by IP (before 25 Nov 2026) is
 * no longer an admin — only the password login counts there.
 */
function drop_ip_admin_session(): void
{
    if (defined('APP_ENV') && APP_ENV === 'local') {
        return;
    }
    if (!empty($_SESSION['is_admin']) && ($_SESSION['admin_via'] ?? '') === 'IP') {
        unset($_SESSION['is_admin'], $_SESSION['admin_via']);
    }
    if (!empty($_SESSION['visitor_preview']) && ($_SESSION['preview_via'] ?? 'IP') === 'IP') {
        unset($_SESSION['visitor_preview'], $_SESSION['preview_via']);
    }
}
