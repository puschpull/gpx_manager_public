<?php
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
check_page_access('filter');
require_once __DIR__ . '/includes/filter_data.php';
require_once __DIR__ . '/includes/filter_view.php';
?>
