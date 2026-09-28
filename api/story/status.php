<?php
declare(strict_types=1);

/**
 * api/story/status.php — stav generování jedné verze
 * POST id | csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/story_helper.php';

ajax_endpoint(function () use ($pdo): array {
    if ($guard = story_endpoint_guard()) {
        return $guard;
    }
    $repo = story_generator($pdo)->repository();
    $repo->expireStale();   // spadlé generování (running > 15 min) = chyba
    $s = $repo->story((int)($_POST['id'] ?? 0));
    if ($s === null) {
        http_response_code(404);
        return ['ok' => false, 'error' => t('story_err_not_found', 'Verze nenalezena.')];
    }

    return [
        'ok'         => true,
        'status'     => $s['status'],
        'error'      => $s['error_message'],
        'cost_usd'   => $s['cost_usd'],
        'duration_s' => $s['duration_s'],
    ];
}, ['csrf' => true, 'admin' => true, 'name' => 'story/status']);
