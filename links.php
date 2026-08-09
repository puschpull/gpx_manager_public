<?php
declare(strict_types=1);

/**
 * Podobné weby — rozcestník na příbuzné nástroje (prohlížeče GPX, plánovače, mapy).
 * Entry point: access gate + view. Obsah je v includes/links_data.php.
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/links_data.php';
check_page_access('links');
require_once __DIR__ . '/includes/links_view.php';
