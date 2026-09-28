<?php
declare(strict_types=1);

/**
 * Cestopis — napojení tříd src/Cestopis na aplikaci (konfigurace, strop,
 * běh na pozadí). Pravidla a zjištění z dat: src/Cestopis/CLAUDE.md.
 */

require_once __DIR__ . '/story_classes.php';

use GpxManager\Cestopis\Narrator;
use GpxManager\Cestopis\StoryGenerator;

/** Výchozí měsíční strop v USD, dokud ho admin nezmění. */
const STORY_DEFAULT_CAP_USD = 5.0;

/** API klíč jazykového modelu z .env (ANTHROPIC_API_KEY). Prázdný = generovat nejde. */
function story_api_key(): string {
    $key = trim((string)($_ENV['ANTHROPIC_API_KEY'] ?? ''));
    // Zkoušky proti falešnému API na localhostu (viz story_api_endpoint)
    if ($key === '' && story_api_endpoint() !== null) {
        $key = 'local-test';
    }
    return $key;
}

/**
 * Adresa API. Jiná než výchozí jen na localhostu pro zkoušky bez placení
 * (app_config 'story_test_endpoint'); na produkci se nastavení ignoruje.
 */
function story_api_endpoint(): ?string {
    if (!defined('APP_ENV') || APP_ENV !== 'local') {
        return null;
    }
    $url = trim((string)get_app_config('story_test_endpoint', ''));
    return preg_match('~^http://127\.0\.0\.1:\d+/~', $url) ? $url : null;
}

function story_generator(PDO $pdo): StoryGenerator {
    // Nominatim a Overpass chtějí, aby se aplikace představila
    $host = preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $args = [
        $pdo,
        story_api_key(),
        'GPX-Manager-Cestopis/1.0 (+https://' . $host . '/)',
        rtrim(uploads_fs('photos'), '/\\') . DIRECTORY_SEPARATOR,
        // CURL_CA_BUNDLE je cesta z WAMPu (config.php) — na serveru neexistuje a curl
        // by s ní selhal u každého spojení. Jen když soubor opravdu je (vzor radar_helper).
        (defined('CURL_CA_BUNDLE') && CURL_CA_BUNDLE !== '' && is_file(CURL_CA_BUNDLE)) ? (string)CURL_CA_BUNDLE : '',
    ];
    $endpoint = story_api_endpoint();
    if ($endpoint !== null) {
        $args[] = $endpoint;
    }
    return new StoryGenerator(...$args);
}

/** Měsíční strop útraty v USD (Administrace — stránka Cestopis). */
function story_monthly_cap(): float {
    $v = get_app_config('story_monthly_cap_usd', null);
    return is_numeric($v) ? max(0.0, (float)$v) : STORY_DEFAULT_CAP_USD;
}

/** Styly textu: klíč (do DB) => popisek. */
function story_styles(): array {
    return [
        'factual'   => t('story_style_factual', 'věcný'),
        'narrative' => t('story_style_narrative', 'vypravěčský'),
        'literary'  => t('story_style_literary', 'literární'),
    ];
}

/** Modely: id => popisek. Ceník je v Narrator::MODELS. */
function story_models(): array {
    $labels = [
        'claude-opus-5'   => t('story_model_opus', 'Opus — nejlepší text'),
        'claude-sonnet-5' => t('story_model_sonnet', 'Sonnet — levnější'),
    ];
    return array_intersect_key($labels, Narrator::MODELS);
}

/** Režimy fotek: klíč => popisek. */
function story_photo_modes(): array {
    return [
        'none' => t('story_photos_none', 'žádné'),
        'rest' => t('story_photos_rest', 'jen z delších zastavení'),
        'all'  => t('story_photos_all', 'ze všech zastávek'),
    ];
}

/**
 * Varianta z POST (track_id, style, model, with_map, photo_mode) — whitelist.
 * @return array{track_id:int, style:string, model:string, with_map:bool, photo_mode:string}|null
 */
function story_read_variant(array $src): ?array {
    $v = [
        'track_id'   => (int)($src['track_id'] ?? 0),
        'style'      => (string)($src['style'] ?? ''),
        'model'      => (string)($src['model'] ?? ''),
        'with_map'   => !empty($src['with_map']),
        'photo_mode' => (string)($src['photo_mode'] ?? 'none'),
    ];
    if ($v['track_id'] <= 0
        || !array_key_exists($v['style'], story_styles())
        || !array_key_exists($v['model'], story_models())
        || !array_key_exists($v['photo_mode'], story_photo_modes())) {
        return null;
    }
    return $v;
}

/** Společný začátek endpointů: jen POST a jen se zapnutou funkcí. Null = pokračovat. */
function story_endpoint_guard(): ?array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        return ['ok' => false, 'error' => 'Method not allowed'];
    }
    if (!feature_enabled('story')) {
        http_response_code(403);
        return ['ok' => false, 'error' => t('story_disabled', 'Funkce Cestopis je vypnutá.')];
    }
    return null;
}

/** Krátký popis varianty verze, např. „literární · Opus · mapa · 23 fotek". */
function story_variant_label(array $s): string {
    $parts = [story_styles()[$s['style']] ?? $s['style']];
    $parts[] = str_contains((string)$s['model'], 'sonnet') ? 'Sonnet' : 'Opus';
    if ($s['with_map']) {
        $parts[] = t('story_map_short', 'mapa');
    }
    if ((int)$s['photos_sent'] > 0) {
        $parts[] = (int)$s['photos_sent'] . ' ' . t('story_photos_short', 'fotek');
    }
    return implode(' · ', $parts);
}

/**
 * Odešle JSON odpověď hned a $job pustí až po ní — generování trvá až
 * ~2 minuty a proxy na serveru by tak dlouhý požadavek usekla.
 *
 * PHP-FPM (produkce): fastcgi_finish_request(). Apache mod_php (WAMP):
 * odpověď s Content-Length + Connection: close, prohlížeč ji dostane celou
 * hned. Kdyby ani to nešlo, prohlížeč jen počká na konec — nic se neztratí.
 * Session se zavře dřív, jinak by zjišťování průběhu čekalo na její zámek.
 */
function story_respond_then_run(array $response, callable $job): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    @set_time_limit(600);

    $body = (string)json_encode($response, JSON_UNESCAPED_UNICODE);
    if (function_exists('fastcgi_finish_request')) {
        echo $body;
        fastcgi_finish_request();
    } else {
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');   // komprese by odpověď zadržela
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Connection: close');
        header('Content-Length: ' . strlen($body));
        echo $body;
        flush();
    }

    try {
        $job();
    } catch (Throwable $e) {
        // Chyba je zapsaná u verze (status error) — tady už jen do logu
        error_log('[cestopis] ' . $e->getMessage());
    }
}
