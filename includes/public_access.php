<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  public_access.php — Session + admin detekce bez přesměrování
 *  Použij místo auth.php na stránkách, které mohou navštívit
 *  i běžní návštěvníci (viditelnost řídí check_page_access)
 * ===========================================================
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/security.php';

start_secure_session();
send_security_headers();

require_once __DIR__ . '/admin_ips.php';

// Auto-přihlášení admina z povolené IP (stejná logika jako auth.php)
// Seznam z .env (ADMIN_IPS); localhost jen na vývojovém PC — viz admin_ips.php
$_pub_is_real_admin = is_admin_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

if ($_pub_is_real_admin) {
    if (empty($_SESSION['is_admin']) && empty($_SESSION['visitor_preview'])) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_via'] = 'IP';
    }
}

// Visitor preview přepínač — funguje pouze pro adminy ze správné IP
if ($_pub_is_real_admin && isset($_GET['visitor_preview'])) {
    // Odstraní visitor_preview z URL a přesměruje (čistá URL po přepnutí)
    // Back to the same page of this app only: the script name, never the raw
    // request path ("//evil.example/…" would be an open redirect, N9).
    $parts = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '')) ?: [];
    parse_str($parts['query'] ?? '', $_vp_q);
    unset($_vp_q['visitor_preview']);
    $cleanUrl = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php')) . ($_vp_q ? '?' . http_build_query($_vp_q) : '');

    if ($_GET['visitor_preview'] === '1') {
        $_SESSION['is_admin']       = false;
        $_SESSION['visitor_preview'] = true;
    } else {
        unset($_SESSION['visitor_preview']);
        $_SESSION['is_admin']   = true;
        $_SESSION['admin_via']  = 'IP';
    }
    header('Location: ' . $cleanUrl);
    exit;
}

unset($_pub_is_real_admin);

// Admin partials (render_admin_menu, render_visitor_preview_banner).
require_once __DIR__ . '/partials/admin_banner.php';

/**
 * Zkontroluje přístup k dané stránce pro návštěvníky.
 * - Admin: zobrazí banner, povolí přístup
 * - Visitor: pokud stránka není v visible_pages → přesměruje na index.php
 *
 * MUSÍ být zavolána po načtení db.php + app_config.php
 */
function check_page_access(string $pageKey): void {
    // Na AJAX requestech bannery nevypisovat — response musí být čistý JSON
    $isAjax = !empty($_GET['ajax']) ||
              (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    if (!empty($_SESSION['is_admin'])) {
        return;
    }

    // Visitor preview mód — banner renderuje layout_header.php

    // Visitor (nebo preview): zkontroluj viditelnost stránky
    if (empty($_SESSION['visitor_preview'])) {
        $visible = get_app_config('visible_pages', all_pages());
        if (!in_array($pageKey, $visible)) {
            header('Location: index.php');
            exit;
        }
    } else {
        // V preview módu: stejná kontrola jako pro normálního visitora
        $visible = get_app_config('visible_pages', all_pages());
        if (!in_array($pageKey, $visible)) {
            header('Location: index.php');
            exit;
        }
    }
}
