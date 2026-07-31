<?php
declare(strict_types=1);

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

$_auth_is_real_admin = in_array($_SERVER['REMOTE_ADDR'], $allowed_ips);

// ====== Visitor preview přepínač (stejná logika jako public_access.php) ======
if ($_auth_is_real_admin && isset($_GET['visitor_preview'])) {
    $parts = parse_url($_SERVER['REQUEST_URI']);
    parse_str($parts['query'] ?? '', $_vp_q);
    unset($_vp_q['visitor_preview']);
    $cleanUrl = ($parts['path'] ?? '/') . ($_vp_q ? '?' . http_build_query($_vp_q) : '');

    if ($_GET['visitor_preview'] === '1') {
        $_SESSION['is_admin']        = false;
        $_SESSION['visitor_preview'] = true;
        header('Location: index.php');
    } else {
        unset($_SESSION['visitor_preview']);
        $_SESSION['is_admin']  = true;
        $_SESSION['admin_via'] = 'IP';
        header('Location: ' . $cleanUrl);
    }
    exit;
}

// ====== V preview módu: admin stránky jsou nedostupné ======
if (!empty($_SESSION['visitor_preview'])) {
    header('Location: index.php');
    exit;
}

// ====== Automatické přihlášení z povolené IP ======
if ($_auth_is_real_admin) {
    if (empty($_SESSION['is_admin'])) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_via'] = 'IP';
    }
}
unset($_auth_is_real_admin);

// ====== Ověření přihlášení ======
if (empty($_SESSION['is_admin'])) {
    $current = basename($_SERVER['SCRIPT_NAME']);
    if ($current !== 'login.php') {
        header('Location: login.php');
        exit;
    }
}

// ====== Banner ======
// render_admin_menu() is defined in the partial below.
require_once __DIR__ . '/partials/admin_banner.php';
?>
