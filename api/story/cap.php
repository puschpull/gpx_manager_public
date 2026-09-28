<?php
declare(strict_types=1);

/**
 * api/story/cap.php — nastaví měsíční strop útraty za cestopisy (USD)
 * POST cap (0–1000) | csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/story_helper.php';

ajax_endpoint(function (): array {
    if ($guard = story_endpoint_guard()) {
        return $guard;
    }
    $raw = str_replace(',', '.', trim((string)($_POST['cap'] ?? '')));
    if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 1000) {
        http_response_code(400);
        return ['ok' => false, 'error' => t('story_err_cap', 'Strop musí být číslo 0–1000.')];
    }
    set_app_config('story_monthly_cap_usd', round((float)$raw, 2));
    return ['ok' => true, 'cap' => story_monthly_cap()];
}, ['csrf' => true, 'admin' => true, 'name' => 'story/cap']);
