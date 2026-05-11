<?php
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

// Auto-přihlášení admina z povolené IP (stejná logika jako auth.php)
// Načteno z .env (ADMIN_IPS), localhost je vždy povolen
$_pub_admin_ips_cfg = array_filter(array_map('trim', explode(',', ADMIN_IPS)));
$_pub_allowed_ips   = array_unique(array_merge(['127.0.0.1', '::1'], $_pub_admin_ips_cfg));
unset($_pub_admin_ips_cfg);
$_pub_is_real_admin = in_array($_SERVER['REMOTE_ADDR'], $_pub_allowed_ips);

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
    $parts = parse_url($_SERVER['REQUEST_URI']);
    parse_str($parts['query'] ?? '', $_vp_q);
    unset($_vp_q['visitor_preview']);
    $cleanUrl = ($parts['path'] ?? '/') . ($_vp_q ? '?' . http_build_query($_vp_q) : '');

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

unset($_pub_allowed_ips, $_pub_is_real_admin);

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
        if (!$isAjax) {
            // Admin banner s tlačítkem pro přepnutí do visitor preview
            $via      = htmlspecialchars($_SESSION['admin_via'] ?? 'login', ENT_QUOTES, 'UTF-8');
            $ip       = htmlspecialchars($_SERVER['REMOTE_ADDR'], ENT_QUOTES, 'UTF-8');
            $parts    = parse_url($_SERVER['REQUEST_URI']);
            parse_str($parts['query'] ?? '', $_bq);
            $_bq['visitor_preview'] = '1';
            $previewUrl = ($parts['path'] ?? '/') . '?' . http_build_query($_bq);
            $previewUrl = htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8');
            echo <<<HTML
<div style="background:#003366;color:white;padding:6px 12px;font-family:Arial,sans-serif;font-size:13px;display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #002244;position:sticky;top:0;z-index:9999;">
  <div>Prihlasen jako <strong>administrator</strong> <span style="opacity:.8;">($via)</span> &nbsp;|&nbsp; IP: <strong>$ip</strong></div>
  <div style="display:flex;gap:8px;align-items:center;">
    <a href="$previewUrl" style="color:#fff;text-decoration:none;background:#e65c00;padding:4px 10px;border-radius:6px;" title="Zobrazit stránku jako běžný návštěvník">👁 Náhled jako návštěvník</a>
    <a href="login.php?logout=1" style="color:#fff;text-decoration:none;background:#cc3333;padding:4px 10px;border-radius:6px;">Odhlasit se</a>
  </div>
</div>
HTML;
        }
        return;
    }

    // Visitor preview mód — zobrazit oranžový banner s tlačítkem zpět
    if (!empty($_SESSION['visitor_preview']) && !$isAjax) {
        $parts = parse_url($_SERVER['REQUEST_URI']);
        parse_str($parts['query'] ?? '', $_bq);
        $_bq['visitor_preview'] = '0';
        $exitUrl = htmlspecialchars(($parts['path'] ?? '/') . '?' . http_build_query($_bq), ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<div style="background:#e65c00;color:white;padding:6px 12px;font-family:Arial,sans-serif;font-size:13px;display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #cc4400;position:sticky;top:0;z-index:9999;">
  <div>👁 <strong>Náhled jako návštěvník</strong> — vidíte stránku bez admin oprávnění</div>
  <a href="$exitUrl" style="color:#fff;text-decoration:none;background:#003366;padding:4px 10px;border-radius:6px;">✕ Zpět na admin pohled</a>
</div>
HTML;
        // V preview módu se kontrola viditelnosti aplikuje normálně
    }

    // Visitor (nebo preview): zkontroluj viditelnost stránky
    if (empty($_SESSION['visitor_preview'])) {
        $visible = get_app_config('visible_pages',
            ['stats','calendar','heatmap','map_search','nearby','filter','compare','settings']);
        if (!in_array($pageKey, $visible)) {
            header('Location: index.php');
            exit;
        }
    } else {
        // V preview módu: stejná kontrola jako pro normálního visitora
        $visible = get_app_config('visible_pages',
            ['stats','calendar','heatmap','map_search','nearby','filter','compare','settings']);
        if (!in_array($pageKey, $visible)) {
            header('Location: index.php?visitor_preview=0');
            exit;
        }
    }
}
