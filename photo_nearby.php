<?php
declare(strict_types=1);

/**
 * Fotografie v okolí — kliknutím na mapu najde nejbližší fotky z výletů.
 * Entry point: access gate + view. Data servíruje api/photos/nearby.php.
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
check_page_access('photo_nearby');
require_once __DIR__ . '/includes/photo_nearby_view.php';
