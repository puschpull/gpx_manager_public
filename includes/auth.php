<?php
/**
 * GPX Manager – ochrana administrace
 * Kombinace IP + přihlášení + vizuální banner
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/security.php';

start_secure_session();
send_security_headers();

// ====== Nastavení — povolené admin IP adresy ======
// Načteno z .env (ADMIN_IPS = 127.0.0.1,::1,vaše.ip.adresa)
// Localhost je vždy povolen automaticky
$_admin_ips_cfg = array_filter(array_map('trim', explode(',', ADMIN_IPS)));
$allowed_ips    = array_unique(array_merge(['127.0.0.1', '::1'], $_admin_ips_cfg));
unset($_admin_ips_cfg);

// ====== Automatické přihlášení z povolené IP ======
if (in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    if (empty($_SESSION['is_admin'])) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_via'] = 'IP';
    }
}

// ====== Ověření přihlášení ======
if (empty($_SESSION['is_admin'])) {
    $current = basename($_SERVER['SCRIPT_NAME']);
    if ($current !== 'login.php') {
        header('Location: login.php');
        exit;
    }
}

// ====== Banner ======
function render_admin_banner() {
    if (empty($_SESSION['is_admin'])) return;
    $via        = htmlspecialchars($_SESSION['admin_via'] ?? 'login', ENT_QUOTES, 'UTF-8');
    $ip         = htmlspecialchars($_SERVER['REMOTE_ADDR'], ENT_QUOTES, 'UTF-8');
    // Visitor preview odkaz vede na index.php (admin stránky jsou pro visitora nepřístupné)
    $previewUrl = htmlspecialchars('index.php?visitor_preview=1', ENT_QUOTES, 'UTF-8');
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
?>
