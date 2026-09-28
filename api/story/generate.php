<?php
declare(strict_types=1);

/**
 * api/story/generate.php — vygeneruje novou verzi cestopisu (PLACENÉ)
 * POST track_id, style, model, with_map, photo_mode, confirm_over_cap?
 * csrf: yes | admin: yes
 *
 * Odpoví hned ({ok, id}) a generování doběhne až po odeslání odpovědi
 * (story_respond_then_run) — průběh se zjišťuje přes api/story/status.php.
 * Když by nová verze překročila měsíční strop, vrátí needs_confirm a nic
 * nespustí; znovu se pošle s confirm_over_cap=1.
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
    if (story_api_key() === '') {
        return ['ok' => false, 'error' => t('story_no_key', 'Chybí API klíč (ANTHROPIC_API_KEY v .env).')];
    }

    $gen = story_generator($pdo);
    $repo = $gen->repository();
    if ($repo->photos($v['track_id']) === []) {
        return ['ok' => false, 'error' => t('story_no_photos', 'Trasa nemá fotky s časem — není z čeho psát.')];
    }

    // Měsíční strop: počítá se i s odhadem téhle verze
    $spent = $repo->monthSpend();
    $cap = story_monthly_cap();
    $estimate = (float)($gen->estimate($v['track_id'], $v['style'], $v['model'], $v['with_map'], $v['photo_mode'])['usd'] ?? 0);
    if (empty($_POST['confirm_over_cap']) && $spent + $estimate > $cap) {
        return [
            'ok'            => false,
            'needs_confirm' => true,
            'spent'         => round($spent, 4),
            'cap'           => $cap,
            'estimate'      => round($estimate, 4),
        ];
    }

    try {
        $id = $gen->begin($v['track_id'], $v['style'], $v['model'], $v['with_map'], $v['photo_mode']);
    } catch (RuntimeException $e) {
        // Jediný běžný případ po kontrolách výše: pro trasu už jedno generování běží
        return ['ok' => false, 'error' => t('story_busy', 'Pro tuto trasu už jeden cestopis vzniká.')];
    }

    story_respond_then_run(['ok' => true, 'id' => $id], static function () use ($gen, $id): void {
        $gen->run($id);
    });
    exit;
}, ['csrf' => true, 'admin' => true, 'name' => 'story/generate']);
