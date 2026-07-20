<?php
/**
 * Plánovač výšlapu — klikáním do mapy naplánuj trasu po cestách
 * (Mapy.com routing), výškový profil, počasí, odhad času a export GPX.
 *
 * Admin: vždy plný přístup (vč. ukládání plánů).
 * Návštěvník: jen pokud je stránka povolena v Administraci → Viditelné
 * stránky ('planner') — může plánovat, prohlížet uložené plány a exportovat
 * GPX, ale NEmůže ukládat ani mazat (viz api/planner/*).
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
check_page_access('planner');
$_isAdmin = !empty($_SESSION['is_admin']);
require_once __DIR__ . '/includes/planner_view.php';
