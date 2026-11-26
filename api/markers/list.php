<?php
declare(strict_types=1);

/**
 * api/markers/list.php — značky s poznámkou (stránka Mapa)
 * GET (bez parametrů)
 * csrf: no | admin: no — admin dostane všechny, návštěvník jen veřejné.
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/map_markers.php';

ajax_endpoint(function () use ($pdo): array {
    // Vypnutá stránka nevydává data ani přes API (N3)
    if (!api_pages_visible('map')) {
        http_response_code(403);
        return ['ok' => false, 'error' => 'Forbidden'];
    }
    $where = empty($_SESSION['is_admin']) ? 'WHERE is_public = 1' : '';
    $rows  = $pdo->query("
        SELECT id, lat, lon, title, note, symbol, is_public
        FROM   map_markers
        $where
        ORDER  BY id
        LIMIT  2000
    ")->fetchAll(\PDO::FETCH_ASSOC);

    return ['ok' => true, 'markers' => array_map('map_marker_out', $rows)];
}, ['csrf' => false, 'admin' => false, 'name' => 'markers/list']);
