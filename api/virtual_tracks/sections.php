<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/sections.php — store the sections of a virtual track
 * POST id, sections = JSON {"boundaries":[int…], "starts":[int…], "profiles":[string…]}
 *      or clear = 1 (sections removed)
 * csrf: yes | admin: yes
 *
 * Sections used to live only in the browser's localStorage (migration 0023
 * added virtual_tracks.sections), so another computer did not see them.
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    $id = (int)($_POST['id'] ?? 0);
    $ex = $pdo->prepare('SELECT COUNT(*) FROM virtual_tracks WHERE id = ?');
    $ex->execute([$id]);
    if ($id <= 0 || (int)$ex->fetchColumn() === 0) {
        http_response_code(404);
        return ['ok' => false, 'error' => 'Not found'];
    }

    if (($_POST['clear'] ?? '') === '1') {
        $pdo->prepare('UPDATE virtual_tracks SET sections = NULL WHERE id = ?')->execute([$id]);
        return ['ok' => true, 'cleared' => true];
    }

    $raw = json_decode((string)($_POST['sections'] ?? ''), true);
    $bad = static function (): array {
        http_response_code(400);
        return ['ok' => false, 'error' => t('api_bad_param', 'Invalid parameter')];
    };
    if (!is_array($raw) || !isset($raw['boundaries']) || !is_array($raw['boundaries'])) {
        return $bad();
    }
    $ints = static function ($a): ?array {
        if (!is_array($a) || count($a) > 500) {
            return null;
        }
        $out = [];
        foreach ($a as $v) {
            if (!is_int($v) || $v < 0 || $v > 100000) {
                return null;
            }
            $out[] = $v;
        }
        return $out;
    };
    $profilesOk = ['foot_hiking', 'foot_fast', 'bike_road', 'bike_mountain', 'car_fast', 'car_short'];

    $boundaries = $ints($raw['boundaries']);
    $starts     = $ints($raw['starts'] ?? []);
    $profiles   = $raw['profiles'] ?? [];
    if ($boundaries === null || $starts === null || !is_array($profiles) || count($profiles) > 500) {
        return $bad();
    }
    foreach ($profiles as $p) {
        if (!is_string($p) || !in_array($p, $profilesOk, true)) {
            return $bad();
        }
    }

    $json = json_encode(['boundaries' => $boundaries, 'starts' => $starts, 'profiles' => array_values($profiles)]);
    $pdo->prepare('UPDATE virtual_tracks SET sections = ? WHERE id = ?')->execute([$json, $id]);
    return ['ok' => true];
}, ['csrf' => true, 'admin' => true, 'name' => 'virtual_tracks/sections']);
