<?php
declare(strict_types=1);

/**
 * api/story/publish.php — zveřejní verzi (ostatní verze trasy stáhne),
 * nebo s publish=0 stáhne zveřejněnou verzi trasy
 * POST id, publish (1|0) | csrf: yes | admin: yes
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
    $s = $repo->story((int)($_POST['id'] ?? 0));
    if ($s === null || $s['status'] !== 'done') {
        http_response_code(404);
        return ['ok' => false, 'error' => t('story_err_not_found', 'Verze nenalezena.')];
    }

    if (!empty($_POST['publish'])) {
        $repo->publish((int)$s['id']);
    } else {
        $repo->unpublish((int)$s['track_id']);
    }
    return ['ok' => true];
}, ['csrf' => true, 'admin' => true, 'name' => 'story/publish']);
