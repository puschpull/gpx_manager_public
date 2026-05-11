<?php
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
check_page_access('nearby');
require_once __DIR__ . '/includes/nearby_data.php';
require_once __DIR__ . '/includes/nearby_view.php';
?>
