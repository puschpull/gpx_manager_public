<?php
declare(strict_types=1);

/**
 * api/explore/tiles.php — PUBLIC read-only: explored squares (zoom-14 tiles)
 * GET ?act=all|foot  &year=YYYY (tiles first visited that year are counted)
 * csrf: no | admin: no — page 'explore' must be visible for visitors.
 *
 * Only an admin request recomputes new or changed tracks (it may parse many
 * GPX files); a visitor gets what was stored last time.
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/explore_helper.php';

ajax_endpoint(function () use ($pdo): array {
    $isAdmin = !empty($_SESSION['is_admin']);
    if (!$isAdmin && !in_array('explore', (array)get_app_config('visible_pages', all_pages()), true)) {
        http_response_code(403);
        return ['ok' => false, 'error' => 'Forbidden'];
    }

    $act  = ($_GET['act'] ?? 'all') === 'foot' ? foot_activities() : null;
    $year = isset($_GET['year']) && is_string($_GET['year']) && preg_match('/^(19|20)\d{2}$/', $_GET['year']) ? (int)$_GET['year'] : null;

    $complete = true;
    if ($isAdmin) {
        set_time_limit(180);
        ini_set('memory_limit', '512M');
        [$store, , $complete] = explore_refresh($pdo, 60.0);
    } else {
        $store = explore_load();
    }

    return ['ok' => true, 'complete' => $complete] + explore_aggregate($store, $act, $year);
}, ['csrf' => false, 'admin' => false, 'name' => 'explore/tiles']);
