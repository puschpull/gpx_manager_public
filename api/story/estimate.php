<?php
declare(strict_types=1);

/**
 * api/story/estimate.php — odhad ceny nové verze cestopisu + útrata za měsíc
 * POST track_id, style, model, with_map, photo_mode | csrf: yes | admin: yes
 * Nic neplatí (API modelu se nevolá).
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/story_helper.php';

ajax_endpoint(function () use ($pdo): array {
    if ($guard = story_endpoint_guard()) {
        return $guard;
    }
    $v = story_read_variant($_POST);
    if ($v === null) {
        http_response_code(400);
        return ['ok' => false, 'error' => t('story_err_invalid', 'Neplatná volba.')];
    }

    $gen = story_generator($pdo);
    $e = $gen->estimate($v['track_id'], $v['style'], $v['model'], $v['with_map'], $v['photo_mode']);

    return [
        'ok'           => true,
        'usd'          => $e['usd'],
        'photos'       => $e['photos'],
        'from_history' => $e['from_history'],
        'month_spent'  => round($gen->repository()->monthSpend(), 4),
        'cap'          => story_monthly_cap(),
    ];
}, ['csrf' => true, 'admin' => true, 'name' => 'story/estimate']);
