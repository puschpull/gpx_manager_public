<?php
declare(strict_types=1);

/**
 * api/tracks/lines_set.php — PUBLIC read-only: simplified lines of a set of tracks
 * GET ?year=YYYY            — all tracks of a calendar year (page 'year' must be visible)
 * GET ?collection=<id>      — tracks of a collection (public collection + page 'collections')
 * csrf: no | admin: no — tracks are public like their detail; the page gate
 * mirrors the page that shows the map.
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/track_lines.php';

const LINES_SET_MAX = 400;

ajax_endpoint(function () use ($pdo): array {
    $isAdmin = !empty($_SESSION['is_admin']);
    $visible = (array)get_app_config('visible_pages', all_pages());
    $cols    = 'id, filename, track_name, alt_title, place_name, date_start, distance_km';

    $yearRaw = $_GET['year'] ?? null;
    $collRaw = $_GET['collection'] ?? null;

    if (is_string($yearRaw) && preg_match('/^(19|20)\d{2}$/', $yearRaw)) {
        if (!$isAdmin && !in_array('year', $visible, true)) {
            http_response_code(403);
            return ['ok' => false, 'error' => 'Forbidden'];
        }
        $y  = (int)$yearRaw;
        $st = $pdo->prepare("SELECT $cols FROM tracks
                             WHERE date_start >= ? AND date_start < ?
                             ORDER BY date_start ASC LIMIT " . LINES_SET_MAX);
        $st->execute([sprintf('%04d-01-01', $y), sprintf('%04d-01-01', $y + 1)]);
    } elseif (is_string($collRaw) && ctype_digit($collRaw)) {
        if (!$isAdmin && !in_array('collections', $visible, true)) {
            http_response_code(403);
            return ['ok' => false, 'error' => 'Forbidden'];
        }
        $st = $pdo->prepare("SELECT is_public FROM collections WHERE id = ?");
        $st->execute([(int)$collRaw]);
        $pub = $st->fetchColumn();
        if ($pub === false || (!$isAdmin && (int)$pub !== 1)) {
            http_response_code(404);
            return ['ok' => false, 'error' => 'Not found'];
        }
        $st = $pdo->prepare("SELECT t.id, t.filename, t.track_name, t.alt_title, t.place_name, t.date_start, t.distance_km
                             FROM collection_tracks ct JOIN tracks t ON t.id = ct.track_id
                             WHERE ct.collection_id = ?
                             ORDER BY ct.position ASC, t.date_start ASC LIMIT " . LINES_SET_MAX);
        $st->execute([(int)$collRaw]);
    } else {
        http_response_code(400);
        return ['ok' => false, 'error' => t('api_bad_param', 'Invalid parameter')];
    }

    set_time_limit(120);
    return ['ok' => true, 'tracks' => track_lines_for_rows($pdo, $st->fetchAll(\PDO::FETCH_ASSOC))];
}, ['csrf' => false, 'admin' => false, 'name' => 'tracks/lines_set']);
