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

/**
 * Renders the language-picker widget (flag image + transparent select overlay).
 *
 * @param array $allowedLangs List of enabled language codes.
 */
function renderLangSelector(array $allowedLangs): void {
    $langToFlag = ['cs' => 'cz', 'en' => 'gb', 'de' => 'de', 'sk' => 'sk',
                   'es' => 'es', 'fr' => 'fr', 'pl' => 'pl', 'it' => 'it'];
    $langNames  = ['cs' => 'Čeština',    'en' => 'English',    'de' => 'Deutsch',
                   'sk' => 'Slovenčina', 'es' => 'Español',    'fr' => 'Français',
                   'pl' => 'Polski',     'it' => 'Italiano'];
    $cur      = app_lang();
    $flagCode = $langToFlag[$cur] ?? 'cz';
    $flagSrc  = 'lang/flags/' . $flagCode . '.png';
    $altText  = htmlspecialchars($langNames[$cur] ?? $cur, ENT_QUOTES, 'UTF-8');
    echo '<span class="lang-picker" title="Jazyk / Language">';
    echo '<img id="lang-flag" src="' . $flagSrc . '" alt="' . $altText . '" class="lang-flag-img">';
    echo '<select id="lang-selector" class="lang-selector-hidden">';
    foreach ($allowedLangs as $lc) {
        $sel  = $lc === $cur ? ' selected' : '';
        $name = htmlspecialchars($langNames[$lc] ?? $lc, ENT_QUOTES, 'UTF-8');
        echo "<option value=\"$lc\"$sel>$name</option>";
    }
    echo '</select>';
    echo '</span>';
}

/**
 * Renders the full header-meta block (theme selector, lang picker, DB/server info).
 *
 * @param array  $available_themes List of enabled theme slugs.
 * @param string $theme            Currently active theme slug.
 * @param array  $allowedLangs    List of enabled language codes.
 */
function renderHeaderMeta(array $available_themes, string $theme, array $allowedLangs): void {
    $themeLabels = [
        'classic'   => 'Classic',    'dark'      => 'Dark',
        'darkblue'  => 'Dark Blue',  'darkgreen' => 'Dark Green',
        'blue'      => 'Blue',       'green'     => 'Green',
        'minimal'   => 'Minimal',    'lightgray' => 'Light Gray',
        'brown'     => 'Brown',
    ];
    echo '<span class="header-meta">';
    echo '<span class="header-controls">';
    echo '<select id="theme-selector" class="select">';
    foreach ($available_themes as $th) {
        $sel   = $th === $theme ? ' selected' : '';
        $label = htmlspecialchars($themeLabels[$th] ?? ucfirst($th), ENT_QUOTES, 'UTF-8');
        echo "<option value=\"$th\"$sel>$label</option>";
    }
    echo '</select>';
    renderLangSelector($allowedLangs);
    echo '</span>';
    echo '<small class="header-info">';
    echo t('db_label') . ': <b>' . DB_NAME . '</b> | ' . t('server_label') . ': <b>' . APP_ENV . '</b>';
    echo '</small>';
    echo '</span>';
}
