<?php
/**
 * Nastavení aplikace — jednotky, mapová vrstva, jazyk
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
check_page_access('settings');

/* ===== Theme ===== */
init_theme_cookie();
$theme = active_theme();
$available_themes = available_themes();
$allowedLangs = available_langs();

/* ===== Uložení nastavení (POST) ===== */
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
    $cookieExpire = time() + 365 * 24 * 3600;

    // Jednotky
    $units = $_POST['units'] ?? 'metric';
    if (in_array($units, ['metric', 'imperial'])) {
        setcookie('app_units', $units, $cookieExpire, '/');
        $_COOKIE['app_units'] = $units; // pro okamžité zobrazení
    }

    // Výchozí mapová vrstva (uloží se do cookie, JS ji přečte)
    $mapLayer = $_POST['map_layer'] ?? '';
    if ($mapLayer !== '') {
        setcookie('app_map_layer', $mapLayer, $cookieExpire, '/');
        $_COOKIE['app_map_layer'] = $mapLayer;
    }

    // Jazyk
    $lang = $_POST['lang'] ?? 'cs';
    if (in_array($lang, $allowedLangs)) {
        setcookie('app_lang', $lang, $cookieExpire, '/');
        $_COOKIE['app_lang'] = $lang;
    }

    $saved = true;
}

/* ===== Aktuální hodnoty ===== */
$currentUnits    = $_COOKIE['app_units']     ?? 'metric';
$currentMapLayer = $_COOKIE['app_map_layer'] ?? '';
$currentLang     = $_COOKIE['app_lang']      ?? 'cs';

/* ===== Mapové vrstvy ===== */
$mapLayers = [
    ''                          => '— Poslední použitá —',
    '🗺️ OSM'                   => '🗺️ OpenStreetMap',
    '🏞️ Topo'                  => '🏞️ OpenTopoMap',
    '🌍 Satelit (Esri)'        => '🌍 Satelit (Esri)',
    '🗺️ Mapy.com základní'     => '🗺️ Mapy.com základní',
    '🧭 Mapy.com turistická'   => '🧭 Mapy.com turistická',
    '❄️ Mapy.com zimní'        => '❄️ Mapy.com zimní',
    '✈️ Mapy.com letecká'      => '✈️ Mapy.com letecká',
    '🥾 Thunderforest'         => '🥾 Thunderforest Outdoors',
];
?>

<?php
$page_title = t('h1_settings');
require __DIR__ . '/includes/layout_header.php';
?>
    <style>
        .settings-form {
            max-width: 640px;
            margin: 16px 0;
        }
        .settings-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 12px;
        }
        .settings-section h2 {
            margin: 0 0 10px;
            font-size: 15px;
            color: var(--text-color);
            border-bottom: 2px solid var(--accent-color);
            padding-bottom: 5px;
        }
        .setting-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 0;
        }
        .setting-label {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-color);
        }
        .setting-desc {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 1px;
        }
        .setting-control {
            flex-shrink: 0;
        }
        .setting-control select {
            min-width: 200px;
            color: var(--text-color);
            background: var(--card-bg);
            border-color: var(--border-color);
        }

        /* Jednotky — inline vedle sebe */
        .radio-group-inline {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .radio-group-inline label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-color);
            cursor: pointer;
            padding: 5px 12px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            transition: border-color 0.15s, background 0.15s;
        }
        .radio-group-inline label:hover {
            border-color: var(--accent-color);
        }
        .radio-group-inline input {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .radio-group-inline label:has(input:focus-visible) {
            outline: 2px solid var(--accent-color);
            outline-offset: 2px;
        }
        .radio-group-inline input:checked + span {
            font-weight: 700;
            color: var(--accent-color);
        }
        .radio-group-inline input:checked ~ span {
            font-weight: 700;
            color: var(--accent-color);
        }
        .radio-group-inline label:has(input:checked) {
            border-color: var(--accent-color);
            background: color-mix(in srgb, var(--accent-color) 8%, transparent);
        }

        /* Jazyky — grid 2 sloupce */
        .lang-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
        }
        .lang-grid label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-color);
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            transition: border-color 0.15s, background 0.15s;
        }
        .lang-grid label:hover {
            border-color: var(--accent-color);
        }
        .lang-grid input {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .lang-grid label:has(input:focus-visible) {
            outline: 2px solid var(--accent-color);
            outline-offset: 2px;
        }
        .lang-grid input:checked + span {
            font-weight: 700;
            color: var(--accent-color);
        }
        .lang-grid label:has(input:checked) {
            border-color: var(--accent-color);
            background: color-mix(in srgb, var(--accent-color) 8%, transparent);
        }

        .save-bar {
            margin-top: 12px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .save-msg {
            color: var(--accent-color, #1a7431);
            font-weight: 600;
            font-size: 13px;
        }

        .unit-preview {
            margin-top: 8px;
            padding: 6px 12px;
            background: var(--bg-color, #f8f9fa);
            border-radius: 6px;
            font-size: 12px;
            color: var(--text-muted);
        }
        .unit-preview strong {
            color: var(--accent-color);
        }

        @media (max-width: 480px) {
            .lang-grid { grid-template-columns: 1fr; }
            .setting-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
            }
            .setting-control select { min-width: 100%; }
        }
    </style>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars(t('back_to_list')) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="settings" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h1_settings')) ?>
    </h1>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12">


<form method="post" class="settings-form">
    <?= csrf_field() ?>
    <!-- Jednotky + Mapa — jeden řádek -->
    <div class="settings-section">
        <h2><?= t('settings_units') ?> & <?= t('settings_map_layer') ?></h2>
        <div class="radio-group-inline" style="margin-bottom: 8px;">
            <label>
                <input type="radio" name="units" value="metric" <?= $currentUnits === 'metric' ? 'checked' : '' ?>>
                <span><?= t('units_metric') ?></span>
            </label>
            <label>
                <input type="radio" name="units" value="imperial" <?= $currentUnits === 'imperial' ? 'checked' : '' ?>>
                <span><?= t('units_imperial') ?></span>
            </label>
        </div>
        <div class="unit-preview" id="unitPreview">
            <?php if ($currentUnits === 'imperial'): ?>
                <?= t('units_preview') ?> <strong>10 km</strong> → <strong><?= number_format(10 * 0.621371, 1, ',', '') ?> mi</strong>,
                <strong>500 m</strong> → <strong><?= number_format(500 * 3.28084, 0, ',', ' ') ?> ft</strong>,
                <strong>25 km/h</strong> → <strong><?= number_format(25 * 0.621371, 1, ',', '') ?> mph</strong>
            <?php else: ?>
                <?= t('units_preview') ?> <strong>10 km</strong>, <strong>500 m</strong>, <strong>25 km/h</strong>
            <?php endif; ?>
        </div>
        <div class="setting-row" style="margin-top: 8px;">
            <div>
                <div class="setting-label"><?= t('maplayer_label') ?></div>
                <div class="setting-desc"><?= t('maplayer_desc') ?></div>
            </div>
            <div class="setting-control">
                <select name="map_layer" class="select">
                    <?php foreach ($mapLayers as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $currentMapLayer === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Jazyk — kompaktní grid 2×4 -->
    <div class="settings-section">
        <h2><?= t('settings_language') ?></h2>
        <div class="lang-grid">
            <?php
            $langCodes = ['cs', 'en', 'de', 'sk', 'es', 'fr', 'pl', 'it'];
            foreach ($langCodes as $lc):
            ?>
            <label>
                <input type="radio" name="lang" value="<?= $lc ?>" <?= $currentLang === $lc ? 'checked' : '' ?>>
                <span><?= t('lang_' . $lc) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="save-bar">
        <button type="submit" class="btn-outdoor btn-outdoor-primary">
            <i data-lucide="save" class="w-4 h-4" aria-hidden="true"></i>
            <?= t('btn_save_settings') ?>
        </button>
        <span class="save-msg" role="status" aria-live="polite"><?php if ($saved): ?><?= t('msg_settings_saved') ?><?php endif; ?></span>
    </div>
</form>

<script>
// Náhled jednotek při přepnutí
document.querySelectorAll('input[name="units"]').forEach(radio => {
    radio.addEventListener('change', () => {
        const preview = document.getElementById('unitPreview');
        if (radio.value === 'imperial') {
            preview.innerHTML = 'Příklad: <strong>10 km</strong> → <strong>6,2 mi</strong>, ' +
                '<strong>500 m</strong> → <strong>1 640 ft</strong>, ' +
                '<strong>25 km/h</strong> → <strong>15,5 mph</strong>';
        } else {
            preview.innerHTML = 'Příklad: <strong>10 km</strong>, <strong>500 m</strong>, <strong>25 km/h</strong>';
        }
    });
});

// Při uložení mapové vrstvy → zapsat i do localStorage (pro JS mapy)
document.querySelector('form').addEventListener('submit', () => {
    const layer = document.querySelector('select[name="map_layer"]').value;
    if (layer) {
        localStorage.setItem('gpx_map_layer', layer);
    }
});
</script>

</div><?php require __DIR__ . '/includes/layout_footer.php'; ?>