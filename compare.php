<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
check_page_access('compare');
require_once __DIR__ . '/includes/compare_data.php';
require_once __DIR__ . '/includes/compare_view.php';
?>
