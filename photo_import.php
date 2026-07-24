<?php
declare(strict_types=1);

/**
 * GPX Manager — Lokalni import fotek (entry point)
 *
 * Admin-only page. All AJAX endpoints moved to api/photo_import/*.php (TASK-19).
 *
 * DB query logic:  (none — page has no server-side data queries)
 * HTML template:   includes/photo_import_view.php
 * CSS:             css/photo-import.css
 * JS:              js/photo-import.js
 *
 * Covers: QR-6, PERF-16, PERF-18 (TASK-19)
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/photo_helper.php';

/* =====================================================================
   Access gate — page is admin-only (auth.php already redirects guests)
   ===================================================================== */
if (empty($_SESSION['is_admin'])) {
    header('Location: login.php');
    exit;
}

/* =====================================================================
   Layout
   ===================================================================== */
require __DIR__ . '/includes/photo_import_view.php';
