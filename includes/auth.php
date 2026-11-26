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

require_once __DIR__ . '/admin_ips.php';

// ====== Admin podle IP ======
// Seznam z .env (ADMIN_IPS); localhost jen na vývojovém PC — viz admin_ips.php
$_auth_is_real_admin = is_admin_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

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
