<?php
declare(strict_types=1);

/**
 * api/story/delete.php — smaže verzi (text pryč, útrata v součtu měsíce zůstává)
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
    if (!story_generator($pdo)->repository()->delete((int)($_POST['id'] ?? 0))) {
        http_response_code(409);
        return ['ok' => false, 'error' => t('story_err_delete', 'Tuto verzi teď smazat nejde.')];
    }
    return ['ok' => true];
}, ['csrf' => true, 'admin' => true, 'name' => 'story/delete']);
