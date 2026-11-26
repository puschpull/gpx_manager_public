<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  i18n.php — Internationalisation helpers
 *  Language resolution, translation lookups, UI renderers.
 * ===========================================================
 */

/**
 * Returns the current application language code (e.g. 'cs', 'en').
 * Priority: ?app_lang= URL param → cookie → default 'cs'.
 * Persists the choice in a cookie (COOKIE_TTL_SECONDS).
 */
function app_lang(): string {
    static $lang = null;
    if ($lang === null) {
        if (!empty($_GET['app_lang'])) {
            $lang = (string)$_GET['app_lang'];
            if (!headers_sent()) {
                setcookie('app_lang', $lang, time() + (defined('COOKIE_TTL_SECONDS') ? COOKIE_TTL_SECONDS : 365 * 24 * 3600), '/');
            }
            $_COOKIE['app_lang'] = $lang;
        } else {
            $lang = $_COOKIE['app_lang'] ?? 'cs';
        }
        $allowed = function_exists('available_langs') ? available_langs() : all_langs();
        if (!in_array($lang, $allowed)) $lang = $allowed[0] ?? 'cs';
    }
    return $lang;
}

/**
 * Translates a key to the current language.
 * Always returns a string. If the key maps to an array value, returns
 * $default ?? $key (and triggers a warning on local env).
 * Use t_arr() for keys known to be arrays.
 */
function t(string $key, ?string $default = null): string {
    static $strings = null;
    if ($strings === null) {
        $langFile = __DIR__ . '/../lang/' . app_lang() . '.php';
        $strings  = file_exists($langFile) ? require $langFile : [];
    }
    $val = $strings[$key] ?? null;
    if (is_array($val)) {
        if (defined('APP_ENV') && APP_ENV === 'local') {
            trigger_error("t('$key') is an array — use t_arr() instead", E_USER_WARNING);
        }
        return $default ?? $key;
    }
    if ($val === null) return $default ?? $key;
    return (string)$val;
}

/**
 * Translation for HTML output with {placeholders} filled by ready-made HTML.
 *
 * The translated text is escaped first, then each {name} is replaced with
 * $html['name'] as-is — so callers pass already-safe markup, e.g.
 * t_html($key, 'Tras celkem: {n}', ['n' => '<strong>' . (int)$total . '</strong>']).
 * User data inside $html must be escaped by the caller.
 */
function t_html(string $key, string $default, array $html = []): string {
    $s = htmlspecialchars(t($key, $default), ENT_QUOTES, 'UTF-8');
    foreach ($html as $name => $value) {
        $s = str_replace('{' . $name . '}', (string)$value, $s);
    }
    return $s;
}

/**
 * Returns the array value for an i18n key (e.g. 'days_full', 'months_short').
 * Returns an empty array if the key is missing or is not an array.
 */
function t_arr(string $key): array {
    static $strings = null;
    if ($strings === null) {
        $langFile = __DIR__ . '/../lang/' . app_lang() . '.php';
        $strings  = file_exists($langFile) ? require $langFile : [];
    }
    $val = $strings[$key] ?? null;
    return is_array($val) ? $val : [];
}

// renderLangSelector() odstraněna 2026-06-26 — mrtvý kód (jediný volající
// renderHeaderMeta() byl odstraněn při úklidu legacy témat). Jazykový přepínač
// dnes řeší layout_header.php (Alpine.js dropdown).

