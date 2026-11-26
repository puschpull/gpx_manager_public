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

require_once __DIR__ . '/admin_ips.php';

// JSON API bez session cookie (a bez „zapamatovat“, ne z povolené IP) je vždy
// návštěvník: session se nezakládá, aby každý anonymní požadavek nevytvářel
// na serveru soubor session (kontrola 21. 11. 2026, N11). $_SESSION zůstane
// prázdné pole, takže kontroly is_admin fungují stejně. CSRF endpointy si
// session případně založí samy v csrf_verify() — a stejně jsou jen pro admina.
$_pub_script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
if (strpos($_pub_script, '/api/') !== false
    && !isset($_COOKIE[session_name()]) && !isset($_COOKIE['gpx_remember'])
    && !is_admin_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
    $_SESSION = [];
} else {
    start_secure_session();
}
unset($_pub_script);
send_security_headers();
require_once __DIR__ . '/db.php';              // $pdo — klíč pro „zapamatovat“ je v app_config
require_once __DIR__ . '/remember_login.php';

// Produkce: admin jen přes přihlášení heslem (S2) — dřívější session podle IP
// přestává platit; zapamatované zařízení (cookie gpx_remember) se přihlásí samo.
drop_ip_admin_session();
remember_try_login();

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

// Visitor preview přepínač (admin podle IP i po přihlášení heslem)
require_once __DIR__ . '/visitor_preview.php';
visitor_preview_handle($_pub_is_real_admin, false);

unset($_pub_is_real_admin);

// Admin partials (render_admin_menu, render_visitor_preview_banner).
require_once __DIR__ . '/partials/admin_banner.php';

/**
 * Pro JSON API: smí tento požadavek data číst? Admin vždy, návštěvník jen
 * když jsou VŠECHNY uvedené stránky ve visible_pages (kontrola 21. 11. 2026,
 * N3 — vypnutá stránka dřív dál vydávala data přes své API).
 */
function api_pages_visible(string ...$pageKeys): bool {
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }
    $visible = (array) get_app_config('visible_pages', all_pages());
    foreach ($pageKeys as $k) {
        if (!in_array($k, $visible, true)) {
            return false;
        }
    }
    return true;
}

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
