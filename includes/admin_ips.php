<?php
declare(strict_types=1);

/**
 * Admin-by-IP allowlist, shared by auth.php and public_access.php.
 *
 * The allowlist (and loopback 127.0.0.1, ::1) applies ONLY on a development
 * machine: APP_ENV = local AND the .gpx-local marker file exists. Behind
 * a reverse proxy REMOTE_ADDR is loopback for every client, and a proxy that
 * forwards a client-supplied X-Real-IP lets anyone claim 127.0.0.1 — so in
 * production loopback must never mean "the owner". The marker file is
 * required on top of APP_ENV because an installation may also treat a
 * "localhost" Host header as local, and that header can be forged.
 *
 * Production ignores ADMIN_IPS completely (security check S2, 25 Nov 2026):
 * a home IP is shared by every device on the network (guests' phones, the TV)
 * and may move to a stranger when the ISP reassigns it. Production admin =
 * password login only (with "remember me", see remember_login.php).
 */

/** @return list<string> */
function admin_allowed_ips(): array
{
    $loopback = ['127.0.0.1', '::1'];
    $cfg = array_values(array_filter(array_map('trim', explode(',', (string) ADMIN_IPS)), 'strlen'));

    if (APP_ENV === 'local' && is_file(dirname(__DIR__) . '/.gpx-local')) {
        return array_values(array_unique(array_merge($loopback, $cfg)));
    }
    return [];
}

function is_admin_ip(string $ip): bool
{
    return $ip !== '' && in_array($ip, admin_allowed_ips(), true);
}
