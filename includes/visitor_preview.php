<?php
declare(strict_types=1);

/**
 * Visitor preview switch (?visitor_preview=1 / =0), shared by public_access.php
 * and auth.php.
 *
 * Who may switch: an admin by IP, an admin who logged in with the password,
 * or a session that is in preview started by one of those. Until 25 Nov 2026
 * only the IP admin could — after a password login (phone, away from home)
 * the link was shown but silently ignored.
 *
 * Entering preview drops is_admin and remembers how the admin got in
 * (preview_via); leaving restores exactly that. The flags live only in the
 * server-side session, so a visitor cannot grant itself anything.
 *
 * Redirect target: the same script of this app (never the raw request path —
 * "//evil.example/…" would be an open redirect, N9).
 *
 * @param bool $enterToIndex  true on admin-only pages (auth.php): preview starts on index.php
 */
function visitor_preview_handle(bool $isIpAdmin, bool $enterToIndex): void
{
    if (!isset($_GET['visitor_preview']) || !is_string($_GET['visitor_preview'])) {
        return;
    }
    $parts = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '')) ?: [];
    parse_str($parts['query'] ?? '', $q);
    unset($q['visitor_preview']);
    $cleanUrl = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php')) . ($q ? '?' . http_build_query($q) : '');

    $location = visitor_preview_apply($_SESSION, $_GET['visitor_preview'], $isIpAdmin, $enterToIndex, $cleanUrl);
    if ($location !== null) {
        header('Location: ' . $location);
        exit;
    }
}

/**
 * The decision itself, without redirect (unit-tested in VisitorPreviewTest).
 * Changes $session and returns where to redirect, or null = not allowed / nothing to do.
 *
 * @param array<string, mixed> $session
 */
function visitor_preview_apply(array &$session, string $param, bool $isIpAdmin, bool $enterToIndex, string $cleanUrl): ?string
{
    $loginAdmin = !empty($session['is_admin']) && ($session['admin_via'] ?? '') === 'login';
    $inPreview  = !empty($session['visitor_preview']);
    if (!$isIpAdmin && !$loginAdmin && !$inPreview) {
        return null;   // plain visitor: the parameter means nothing
    }

    if ($param === '1') {
        if (!$inPreview) {
            $session['preview_via'] = $loginAdmin ? 'login' : 'IP';
        }
        $session['is_admin']        = false;
        $session['visitor_preview'] = true;
        return $enterToIndex ? 'index.php' : $cleanUrl;
    }

    $via = $inPreview ? (string) ($session['preview_via'] ?? 'IP') : (string) ($session['admin_via'] ?? 'IP');
    unset($session['visitor_preview'], $session['preview_via']);
    // Admin rights come back only to who still has them: a password login,
    // or an IP admin who is (still) on an allowed IP.
    if ($via !== 'login' && !$isIpAdmin) {
        unset($session['is_admin'], $session['admin_via']);
        return $cleanUrl;
    }
    $session['is_admin']  = true;
    $session['admin_via'] = $via === 'login' ? 'login' : 'IP';
    return $cleanUrl;
}
