<?php
declare(strict_types=1);

/**
 * api/markers/save.php — uloží novou nebo upravenou značku (stránka Mapa)
 * POST id (0 = nová), lat, lon, title, note, symbol, is_public
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/map_markers.php';

ajax_endpoint(function () use ($pdo): array {
    $bad = static function (string $what): array {
        http_response_code(400);
        return ['ok' => false, 'error' => t('api_bad_param', 'Invalid parameter') . ': ' . $what];
    };

    $id  = (int)($_POST['id'] ?? 0);
    $lat = $_POST['lat'] ?? '';
    $lon = $_POST['lon'] ?? '';
    if (!is_string($lat) || !is_numeric($lat) || abs((float)$lat) > 90)  return $bad('lat');
    if (!is_string($lon) || !is_numeric($lon) || abs((float)$lon) > 180) return $bad('lon');

    $title = trim((string)($_POST['title'] ?? ''));
    $note  = trim((string)($_POST['note'] ?? ''));
    if ($title === '' || mb_strlen($title) > 120) return $bad('title');
    if (mb_strlen($note) > 5000)                  return $bad('note');

    $symbol = (string)($_POST['symbol'] ?? 'pin');
    if (!isset(map_marker_symbols()[$symbol])) $symbol = 'pin';
    $isPublic = ($_POST['is_public'] ?? '0') === '1' ? 1 : 0;

    $params = [
        ':lat' => round((float)$lat, 6), ':lon' => round((float)$lon, 6),
        ':title' => $title, ':note' => $note !== '' ? $note : null,
        ':symbol' => $symbol, ':pub' => $isPublic,
    ];
    if ($id > 0) {
        $params[':id'] = $id;
        $st = $pdo->prepare('UPDATE map_markers
                             SET lat = :lat, lon = :lon, title = :title, note = :note,
                                 symbol = :symbol, is_public = :pub
                             WHERE id = :id');
        $st->execute($params);
        $exists = $pdo->prepare('SELECT COUNT(*) FROM map_markers WHERE id = ?');
        $exists->execute([$id]);
        if ((int)$exists->fetchColumn() === 0) {
            http_response_code(404);
            return ['ok' => false, 'error' => 'Not found'];
        }
    } else {
        $st = $pdo->prepare('INSERT INTO map_markers (lat, lon, title, note, symbol, is_public)
                             VALUES (:lat, :lon, :title, :note, :symbol, :pub)');
        $st->execute($params);
        $id = (int)$pdo->lastInsertId();
    }

    $row = $pdo->prepare('SELECT id, lat, lon, title, note, symbol, is_public FROM map_markers WHERE id = ?');
    $row->execute([$id]);
    return ['ok' => true, 'marker' => map_marker_out($row->fetch(\PDO::FETCH_ASSOC))];
}, ['csrf' => true, 'admin' => true, 'name' => 'markers/save']);
