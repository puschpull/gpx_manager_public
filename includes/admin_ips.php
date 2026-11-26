<?php
declare(strict_types=1);

/**
 * Admin-by-IP allowlist, shared by auth.php and public_access.php.
 *
 * Loopback (127.0.0.1, ::1) counts as admin only on the development PC
 * (APP_ENV = local). In production the app sits behind nginx → Varnish → nginx,
 * so a loopback REMOTE_ADDR means "came through the proxy", not "the owner".
 * On 21 Nov 2026 the proxy passed a client-supplied X-Real-IP header through,
 * and "X-Real-IP: 127.0.0.1" made anyone an admin without a password.
 * Loopback is therefore dropped in production even when ADMIN_IPS lists it
 * (config.php falls back to "127.0.0.1,::1" when ADMIN_IPS is missing).
 */

/** @return list<string> */
function admin_allowed_ips(): array
{
    $loopback = ['127.0.0.1', '::1'];
    $cfg = array_values(array_filter(array_map('trim', explode(',', (string) ADMIN_IPS)), 'strlen'));

    if (APP_ENV === 'local') {
        return array_values(array_unique(array_merge($loopback, $cfg)));
    }
    return array_values(array_diff($cfg, $loopback));
}

function is_admin_ip(string $ip): bool
{
    return $ip !== '' && in_array($ip, admin_allowed_ips(), true);
}
