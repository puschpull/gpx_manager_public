<?php
/**
 * GPX Manager — Správa fotografií
 * Entry point: access gate, data loading, layout.
 *
 * AJAX endpoints are served from api/photos/*.php via ajax_endpoint() wrapper.
 * DB query logic:  includes/photos_data.php  → load_photos_page_data()
 * HTML template:   includes/photos_view.php
 */

require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/photo_helper.php';
require_once __DIR__ . '/includes/photos_data.php';

/* =====================================================================
   Access gate
   ===================================================================== */
$_isAdmin = !empty($_SESSION['is_admin']);

// Visitors need the page whitelisted; admins always get through
if (!$_isAdmin) {
    $visiblePages = get_app_config(
        'visible_pages',
        ['stats','calendar','heatmap','map_search','nearby','filter','compare','settings']
    );
    if (!in_array('photos', $visiblePages)) {
        header('Location: index.php');
        exit;
    }
}

$allowedLangs    = available_langs();

/* =====================================================================
   Data
   ===================================================================== */
$pageData = load_photos_page_data($pdo);
extract($pageData, EXTR_SKIP);

/* =====================================================================
   Layout
   ===================================================================== */
$page_title = 'Fotografie tras';
$show_admin_banner = false;
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/photos_view.php';
require __DIR__ . '/includes/layout_footer.php';
