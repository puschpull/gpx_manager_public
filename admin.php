<?php
declare(strict_types=1);

/**
 * Administrace — vstupní bod.
 * Zobrazí novou verzi (výchozí), nebo původní (admin_classic.php), podle
 * volby v app_config 'admin_ui'. Přepínač je nahoře na obou verzích.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

/* ===== POST: volba vzhledu administrace ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_admin_ui'])) {
    if (!csrf_verify()) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
    $ui = (string)$_POST['set_admin_ui'];
    set_app_config('admin_ui', in_array($ui, ['new', 'classic'], true) ? $ui : 'new');
    header('Location: admin.php');
    exit;
}

if (get_app_config('admin_ui', 'new') === 'classic') {
    require __DIR__ . '/admin_classic.php';
    exit;
}

require_once __DIR__ . '/includes/admin_new_data.php';

$page_title = t('nav_admin', 'Administrace');
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/admin_new_view.php';
require __DIR__ . '/includes/layout_footer.php';
