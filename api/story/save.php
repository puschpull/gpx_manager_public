<?php
declare(strict_types=1);

/**
 * api/story/save.php — uloží text napsaný mimo API jako novou verzi
 * (místopisný cestopis, nebo ruční úprava existující verze). Koncept, cena 0;
 * API klíč není potřeba.
 * POST track_id, text, sources (řádky „Název | https://…“), base_id (volitelně)
 * | csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/story_helper.php';

use GpxManager\Cestopis\StoryText;

ajax_endpoint(function () use ($pdo): array {
    if ($guard = story_endpoint_guard()) {
        return $guard;
    }
    $trackId = (int)($_POST['track_id'] ?? 0);
    $text = (string)($_POST['text'] ?? '');
    $baseId = (int)($_POST['base_id'] ?? 0);
    if ($trackId <= 0 || trim($text) === '') {
        http_response_code(400);
        return ['ok' => false, 'error' => t('story_err_empty', 'Text je prázdný.')];
    }
    if (mb_strlen($text) > 100000) {
        http_response_code(400);
        return ['ok' => false, 'error' => t('story_err_too_long', 'Text je příliš dlouhý.')];
    }

    try {
        $id = story_generator($pdo)->saveManual(
            $trackId,
            $text,
            StoryText::parseSources((string)($_POST['sources'] ?? '')),
            $baseId > 0 ? $baseId : null,
        );
    } catch (\RuntimeException | \InvalidArgumentException $e) {
        http_response_code(400);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'id' => $id];
}, ['csrf' => true, 'admin' => true, 'name' => 'story/save']);
