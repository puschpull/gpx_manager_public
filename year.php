<?php
declare(strict_types=1);

/**
 * Year summary — one calendar year on one page: totals vs the previous year,
 * map of all tracks, months, highlights, favourites, new places, photos.
 * GET ?y=YYYY (default: the newest year with a track).
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/year_data.php';
require_once __DIR__ . '/includes/track_title.php';
check_page_access('year');

$_isAdmin   = !empty($_SESSION['is_admin']);
$_visible   = (array)get_app_config('visible_pages', all_pages());
$showPhotos = $_isAdmin || in_array('photos', $_visible, true);

$years = year_list($pdo);
$reqY  = isset($_GET['y']) && is_string($_GET['y']) && preg_match('/^\d{4}$/', $_GET['y']) ? (int)$_GET['y'] : 0;
$year  = in_array($reqY, $years, true) ? $reqY : ($years[0] ?? (int)date('Y'));

$yr = year_build($pdo, $year, $showPhotos);

$page_title = str_replace('{y}', (string)$year, t('yr_title', 'Rok {y}'));
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/year_view.php';
require __DIR__ . '/includes/layout_footer.php';
