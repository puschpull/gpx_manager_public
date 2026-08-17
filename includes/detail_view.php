<?php
/**
 * Detail trasy — Outdoor redesign.
 * Variables ze detail_data.php: $track, $track_name, $prevId, $nextId, $position, $total_nav,
 *                               $allowedLangs.
 *
 * Důležité: zachovává DOM ID (#map, #elev, #stats, #exportBtn, #qrBox, #qrToggleBtn,
 * #timeMode, #similar-wrap, #similar-tracks) aby JS moduly fungovaly beze změny.
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/track_title.php';
$_isAdmin = !empty($_SESSION['is_admin']);

/* Titulek pro panel prohlížeče, záložky a náhled sdíleného odkazu.
   U tras pojmenovaných časovým razítkem z Garminu se poskládá z místa,
   typu aktivity a data. Nadpis stránky ani seznam tras se tím nemění. */
$page_title = track_display_title($pdo, $track);
// Místo se doplní i u tras s pořádným názvem — v tabulce a v editaci
// se zobrazuje u všech, ne jen u těch s razítkem.
$_place = track_place_name($pdo, $track);

function fmtDur($sec) {
    $sec = (int)$sec;
    if ($sec <= 0) return '–';
    $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60);
    return ($h > 0 ? $h . 'h ' : '') . $m . 'm';
}

// QR / share URL
$protocol = (defined('APP_ENV') && APP_ENV !== 'local') ? 'https' : ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http');
$shareUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['PHP_SELF'], '?') . '?id=' . (int)$track['id'];
$qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($shareUrl);

// Back URL preserving filter state + origin (index karty vs legacy tabulka)
$backQuery = $_GET; unset($backQuery['id']);
$origin = ($_GET['from'] ?? '') === 'legacy' ? 'index-legacy.php' : 'index.php';
unset($backQuery['from']); // 'from' nepatří do back URL
$backUrl = $origin . (!empty($backQuery) ? '?' . http_build_query($backQuery) : '');
$backLabel = $origin === 'index-legacy.php' ? 'Zpět na tabulku' : 'Zpět na seznam';

// Nav between tracks — zachovat filtry + origin
$navQuery = $_GET; unset($navQuery['id']);
$navQs = !empty($navQuery) ? '&' . http_build_query($navQuery) : '';
// (Pozn: 'from' a 'filter_submit' zůstávají, aby prev/next listoval ve stejném kontextu)

// Souřadnice středu trasy pro weather widget
$_bounds     = !empty($track['bounds']) ? json_decode($track['bounds'], true) : null;
$_weatherLat = $_bounds ? round(($_bounds['minlat'] + $_bounds['maxlat']) / 2, 5) : null;
$_weatherLon = $_bounds ? round(($_bounds['minlon'] + $_bounds['maxlon']) / 2, 5) : null;
$_hasWeather = !empty($track['date_start']) && $_weatherLat && $_weatherLon;

/* Open Graph — náhled odkazu při sdílení (diskusní fóra, messengery).
   Bez těchhle značek se z odkazu na výšlap ukáže jen holá adresa.
   Obrázek dělá share_image.php: mapa s trasou 1200×630, generuje se
   až při prvním vyžádání. */
$_ogTitle = $page_title;   // stejný text jako v <title>, jen bez přípony „— GPX Manager“
$_ogBits  = [];
if (!empty($track['date_start']))  $_ogBits[] = date('j. n. Y', strtotime((string)$track['date_start']));
if (!empty($track['distance_km'])) $_ogBits[] = number_format((float)$track['distance_km'], 1, ',', ' ') . ' km';
if (!empty($track['ascent']))      $_ogBits[] = '↑ ' . round((float)$track['ascent']) . ' m';
if (!empty($track['duration']))    $_ogBits[] = fmtDur($track['duration']);
$_ogDesc = implode(' · ', $_ogBits);
if (!empty($track['note'])) {
    $_note = trim(preg_replace('/\s+/u', ' ', (string)$track['note']));
    if ($_note !== '') {
        $_ogDesc .= ' — ' . (mb_strlen($_note) > 160 ? mb_substr($_note, 0, 157) . '…' : $_note);
    }
}
$_ogBase  = $protocol . '://' . $_SERVER['HTTP_HOST']
          . rtrim(str_replace('\\', '/', dirname(strtok($_SERVER['PHP_SELF'], '?'))), '/');
$_ogImage = $_ogBase . '/share_image.php?id=' . (int)$track['id'];

$_og = function (string $prop, string $val): string {
    return '    <meta property="' . $prop . '" content="'
         . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . "\">\n";
};
$page_head_extra =
      $_og('og:type', 'article')
    . $_og('og:site_name', 'GPX Manager')
    . $_og('og:locale', function_exists('app_lang') ? app_lang() : 'cs')
    . $_og('og:title', $_ogTitle)
    . $_og('og:description', $_ogDesc)
    . $_og('og:url', $shareUrl)
    . $_og('og:image', $_ogImage)
    . $_og('og:image:width', '1200')
    . $_og('og:image:height', '630')
    . '    <meta name="twitter:card" content="summary_large_image">' . "\n"
    . '    <meta name="description" content="'
        . htmlspecialchars($_ogDesc, ENT_QUOTES, 'UTF-8') . "\">\n";

require __DIR__ . '/layout_header.php';
?>

<!-- Detail-specific styles (slope legend, tooltip toggle — FE-12 / TASK-24) -->
<link rel="stylesheet" href="<?= asset('css/detail.css') ?>">
<?php if (feature_enabled('replay')): ?><link rel="stylesheet" href="<?= asset('css/replay.css') ?>"><?php endif; ?>

<!-- Leaflet a fullscreen control CSS (jen pro detail) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css" integrity="sha384-weDCJ80JNrg6W2Dha8CBrQyz5PZVPOZ39Lw7vWOzm65zqKvZZfSq/3rR77RY5TWm" crossorigin="anonymous">

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6 anim-fade-up anim-fade-up-1">
    <!-- Breadcrumb / back -->
    <a href="<?= h($backUrl) ?>" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars($backLabel) ?>
    </a>

    <!-- Title row -->
    <div class="flex items-start gap-4 flex-wrap">
        <!-- Metadata vlevo -->
        <div class="min-w-0 flex-1">
            <h1 class="font-[Manrope] text-2xl sm:text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 break-words">
                <?= h($track['track_name'] ?: $track['filename']) ?>
            </h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-forest-700/70 dark:text-sand-100/70">
                <?php if (!empty($track['date_start'])): ?>
                    <span class="inline-flex items-center gap-1">
                        <i data-lucide="calendar" class="w-3.5 h-3.5" aria-hidden="true"></i>
                        <?= date('d.m.Y', strtotime($track['date_start'])) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($_place)): ?>
                    <span class="inline-flex items-center gap-1" title="<?= htmlspecialchars(t('hint_place_name')) ?>">
                        <i data-lucide="map-pin" class="w-3.5 h-3.5" aria-hidden="true"></i>
                        <?= h($_place) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($track['activity_type'])): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-terracotta-500 text-white text-xs font-medium">
                        <?= h(activity_type_label($track['activity_type'])) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($track['difficulty'])): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-forest-100 dark:bg-forest-800 text-forest-700 dark:text-sand-100 text-xs font-medium">
                        <i data-lucide="bar-chart-3" class="w-3 h-3" aria-hidden="true"></i>
                        <?= htmlspecialchars(t('difficulty')) ?> <?= (int)$track['difficulty'] ?>/5
                    </span>
                <?php endif; ?>
                <?php if (!empty($track['filename'])): ?>
                    <span class="inline-flex items-center gap-1 text-xs opacity-70">
                        <i data-lucide="file" class="w-3 h-3" aria-hidden="true"></i>
                        <?= h($track['filename']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Weather widget (uprostřed, skrytý dokud JS nenačte data) -->
        <?php if ($_hasWeather): ?>
        <div id="weather-section" class="hidden shrink-0">
            <div class="card-outdoor p-3 w-52">
                <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60 mb-2 pb-2 border-b border-sand-200 dark:border-forest-700">
                    <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                    Počasí
                    <span class="ml-auto normal-case opacity-60" title="Data z klimatického modelu ERA5 (~10 km rozlišení). Mohou se lišit od skutečnosti.">~ model</span>
                </div>
                <div id="weather-widget">
                    <!-- skeleton -->
                    <div class="flex items-center gap-2 mb-3">
                        <div class="skeleton w-7 h-7 rounded-full"></div>
                        <div class="space-y-1.5">
                            <div class="skeleton w-24 h-3 rounded"></div>
                            <div class="skeleton w-12 h-2.5 rounded"></div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-x-2 gap-y-1.5">
                        <div class="skeleton w-16 h-3 rounded"></div>
                        <div class="skeleton w-16 h-3 rounded"></div>
                        <div class="skeleton w-16 h-3 rounded"></div>
                        <div class="skeleton w-16 h-3 rounded"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action buttons vpravo -->
        <div class="flex flex-wrap items-center gap-2 shrink-0">
            <?php if ($_isAdmin): ?>
                <a href="edit.php?id=<?= (int)$track['id'] ?>&<?= h(http_build_query($_GET)) ?>" class="btn-outdoor btn-outdoor-ghost">
                    <i data-lucide="pencil" class="w-4 h-4" aria-hidden="true"></i>
                    <?= htmlspecialchars(t('btn_edit_track')) ?>
                </a>
            <?php endif; ?>
            <a href="filter.php?id=<?= (int)$track['id'] ?><?= h($navQs) ?>" class="btn-outdoor btn-outdoor-ghost" title="<?= htmlspecialchars(t('btn_clean_track')) ?>">
                <i data-lucide="wand-2" class="w-4 h-4" aria-hidden="true"></i>
                <?= htmlspecialchars(t('btn_clean_track')) ?>
            </a>
        </div>
    </div>

    <!-- Prev/Next track nav -->
    <?php if ($prevId || $nextId): ?>
    <div class="mt-4 flex items-center gap-2 text-sm">
        <?php if ($prevId): ?>
            <a class="btn-outdoor btn-outdoor-ghost !py-1.5" href="detail.php?id=<?= $prevId ?><?= h($navQs) ?>">
                <i data-lucide="chevron-left" class="w-4 h-4" aria-hidden="true"></i>
                <?= htmlspecialchars(t('nav_prev')) ?>
            </a>
        <?php else: ?>
            <span class="btn-outdoor btn-outdoor-ghost !py-1.5 opacity-40 pointer-events-none">
                <i data-lucide="chevron-left" class="w-4 h-4" aria-hidden="true"></i>
                <?= htmlspecialchars(t('nav_prev')) ?>
            </span>
        <?php endif; ?>

        <?php if ($position !== null): ?>
            <span class="text-forest-700/60 dark:text-sand-100/60 px-2">
                <?= htmlspecialchars(t('track_position')) ?> <strong class="stat-num"><?= $position ?></strong> / <?= $total_nav ?>
            </span>
        <?php endif; ?>

        <?php if ($nextId): ?>
            <a class="btn-outdoor btn-outdoor-ghost !py-1.5" href="detail.php?id=<?= $nextId ?><?= h($navQs) ?>">
                <?= htmlspecialchars(t('nav_next')) ?>
                <i data-lucide="chevron-right" class="w-4 h-4" aria-hidden="true"></i>
            </a>
        <?php else: ?>
            <span class="btn-outdoor btn-outdoor-ghost !py-1.5 opacity-40 pointer-events-none">
                <?= htmlspecialchars(t('nav_next')) ?>
                <i data-lucide="chevron-right" class="w-4 h-4" aria-hidden="true"></i>
            </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<!-- STAT CARDS GRID — A11Y-021: target for map skip link -->
<section id="track-stats" class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 anim-fade-up anim-fade-up-2">
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="ruler" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('th_distance')) ?>
            </div>
            <div class="mt-1 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100"><?= number_format((float)$track['distance_km'], 1, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60">km</div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="trending-up" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('th_ascent')) ?>
            </div>
            <div class="mt-1 stat-num text-2xl font-semibold text-forest-600"><?= number_format((int)$track['ascent'], 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60">m</div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="trending-down" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('th_descent')) ?>
            </div>
            <div class="mt-1 stat-num text-2xl font-semibold text-terracotta-500"><?= number_format((int)$track['descent'], 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60">m</div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="clock" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('th_duration')) ?>
            </div>
            <div class="mt-1 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100"><?= fmtDur($track['duration'] ?? 0) ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60">h:m</div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="gauge" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('th_speed_avg')) ?>
            </div>
            <div class="mt-1 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100"><?= number_format((float)($track['speed_avg'] ?? 0), 1, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60">km/h</div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="mountain-snow" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('th_elev_max')) ?>
            </div>
            <div class="mt-1 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100"><?= number_format((int)($track['elevation_max'] ?? 0), 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60">m n.m.</div>
        </div>
    </div>
</section>

<!-- MAP — A11Y-021: skip link + role="img" + aria-label -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <a href="#track-stats" class="sr-only-focusable">
        <?= htmlspecialchars(t('skip_to_data', 'Přejít na data trasy')) ?>
    </a>
    <div id="map"
         role="img"
         aria-label="<?= htmlspecialchars(sprintf(t('map_aria', 'Mapa trasy: %s'), $track['track_name'] ?: $track['filename'])) ?>"
         class="w-full h-[60vh] min-h-[400px] rounded-2xl overflow-hidden shadow-card border border-sand-200 dark:border-forest-800"></div>
</section>

<?php if (feature_enabled('replay')): ?>
<!-- PŘEHRÁVAČ VÝŠLAPU (volitelná funkce — Administrace → Volitelné funkce) -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-3">
    <div id="replay-panel" class="replay-panel" style="display:none;">
        <div class="replay-controls">
            <button type="button" id="rpBackKm"  class="rp-btn" title="−1 km">⏮ 1 km</button>
            <button type="button" id="rpBackMin" class="rp-btn" title="−5 min">⏪ 5 min</button>
            <button type="button" id="rpPlay"    class="rp-btn rp-btn-play"
                    aria-label="<?= htmlspecialchars(t('rp_play', 'Přehrát výšlap')) ?>"
                    title="<?= htmlspecialchars(t('rp_play', 'Přehrát výšlap')) ?>">▶</button>
            <button type="button" id="rpFwdMin"  class="rp-btn" title="+5 min">5 min ⏩</button>
            <button type="button" id="rpFwdKm"   class="rp-btn" title="+1 km">1 km ⏭</button>
            <button type="button" id="rpFollow" class="rp-btn" aria-pressed="false"
                    title="<?= htmlspecialchars(t('rp_follow_title', 'Mapa se sama posouvá za turistou — užitečné při přiblížené mapě')) ?>">🎯 <?= htmlspecialchars(t('rp_follow', 'Sledovat')) ?></button>
            <label class="rp-speed"><?= htmlspecialchars(t('rp_speed', 'Rychlost')) ?>:
                <select id="rpSpeed">
                    <option value="30">30×</option>
                    <option value="60" selected>60×</option>
                    <option value="120">120×</option>
                    <option value="300">300×</option>
                </select>
            </label>
            <span class="rp-readout">
                🕐 <span id="rpTime">–</span> · 📏 <span id="rpDist">–</span> · ⛰️ <span id="rpEle">–</span>
            </span>
        </div>
        <input type="range" id="rpSlider" min="0" max="1000" value="0"
               aria-label="<?= htmlspecialchars(t('rp_progress', 'Průběh výšlapu')) ?>">
        <?php if (feature_enabled('replay_weather')): ?>
        <div id="rpWeather" class="rp-weather" style="display:none;"></div>
        <?php endif; ?>
        <?php if (feature_enabled('replay_radar')): ?>
        <div class="rp-radar-controls">
            <button type="button" id="rpRadarToggle" class="rp-btn">🌧️ <?= htmlspecialchars(t('rp_radar', 'Srážkové pole')) ?></button>
            <label id="rpRadarSourceWrap" style="display:none;"><?= htmlspecialchars(t('rp_radar_source', 'Zdroj')) ?>:
                <select id="rpRadarSource">
                    <option value="chmi"><?= htmlspecialchars(t('rp_radar_src_chmi', 'radar ČHMÚ (5 min)')) ?></option>
                    <option value="model"><?= htmlspecialchars(t('rp_radar_src_model', 'model (odhad, 1 h)')) ?></option>
                </select>
            </label>
            <label id="rpRadarOpacityWrap" style="display:none;"><?= htmlspecialchars(t('rp_radar_opacity', 'Sytost')) ?>:
                <input type="range" id="rpRadarOpacity" min="10" max="100" value="75">
            </label>
            <?php if (!empty($_SESSION['is_admin'])): ?>
            <button type="button" id="rpRadarFetch" class="rp-btn" style="display:none;"
                    title="<?= htmlspecialchars(t('rp_radar_fetch_title', 'Stáhnout radarové snímky ČHMÚ pro dobu této trasy (archiv jen ~7 dní zpět)')) ?>">⬇ <?= htmlspecialchars(t('rp_radar_fetch', 'Stáhnout radar')) ?></button>
            <?php endif; ?>
            <span id="rpRadarStatus" class="rp-radar-status"></span>
        </div>
        <?php endif; ?>
        <?php if (feature_enabled('replay_photos')): ?>
        <div class="rp-photo-controls">
            <button type="button" id="rpPhotoToggle" class="rp-btn"
                    title="<?= htmlspecialchars(t('rp_photos_title', 'Zobrazovat v rohu mapy fotku, kterou panáček zrovna míjí')) ?>">📷 <?= htmlspecialchars(t('rp_photos', 'Míjené fotky')) ?></button>
            <span id="rpPhotoStatus" class="rp-radar-status"></span>
        </div>
        <?php endif; ?>
        <div id="rpNote" class="rp-note" style="display:none;"></div>
    </div>
</section>
<?php endif; ?>

<?php if (feature_enabled('plan_overlay')): ?>
<!-- POROVNÁNÍ S PLÁNEM (volitelná funkce — Administrace → Volitelné funkce) -->
<!-- Panel zůstává skrytý, dokud JS nezjistí, že existuje aspoň jeden plán s geometrií -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-3">
    <div id="plan-compare" class="plan-compare" style="display:none;">
        <div class="plan-controls">
            <button type="button" id="planToggle" class="plan-btn"
                    title="<?= htmlspecialchars(t('pl_toggle_title', 'Zobrazit přes trasu naplánovanou variantu a vyhodnotit odchylky')) ?>">🗺️ <?= htmlspecialchars(t('pl_toggle', 'Porovnat s plánem')) ?></button>
            <label id="planSelectWrap" style="display:none;"><?= htmlspecialchars(t('pl_plan', 'Plán')) ?>:
                <select id="planSelect"></select>
            </label>
            <label id="planTolWrap" style="display:none;"><?= htmlspecialchars(t('pl_tolerance', 'Tolerance')) ?>:
                <select id="planTol">
                    <option value="10">10 m</option>
                    <option value="25" selected>25 m</option>
                    <option value="50">50 m</option>
                    <option value="100">100 m</option>
                </select>
            </label>
            <?php if ($_isAdmin): ?>
            <!-- Označení, že tenhle plán byl uskutečněn právě touto trasou.
                 Detail trasy pak porovnání předvybere podle propojení, ne podle
                 data a polohy, a v Plánovači je u plánu odkaz na trasu. -->
            <button type="button" id="planLinkBtn" class="plan-btn" style="display:none;"></button>
            <?php endif; ?>
        </div>
        <div id="planLegend" class="plan-legend" style="display:none;">
            <span><i class="plan-swatch plan-swatch-plan"></i><?= htmlspecialchars(t('pl_legend_plan', 'plán')) ?></span>
            <span><i class="plan-swatch plan-swatch-real"></i><?= htmlspecialchars(t('pl_legend_real', 'skutečná trasa')) ?></span>
            <span><i class="plan-swatch plan-swatch-dev"></i><?= htmlspecialchars(t('pl_legend_dev', 'mimo plán')) ?></span>
        </div>
        <div id="planStatus" class="plan-status"></div>
    </div>
</section>
<?php endif; ?>

<!-- ACTIONS BAR -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-4">
    <div class="flex flex-wrap items-center gap-2">
        <button id="exportBtn" class="btn-outdoor btn-outdoor-ghost">
            <i data-lucide="image-down" class="w-4 h-4" aria-hidden="true"></i>
            <?= htmlspecialchars(t('btn_export_profile')) ?>
        </button>
        <a class="btn-outdoor btn-outdoor-ghost" href="export_kml.php?id=<?= (int)$track['id'] ?>" title="Google Earth / Google Maps / QGIS">
            <i data-lucide="download" class="w-4 h-4" aria-hidden="true"></i> KML
        </a>
        <a class="btn-outdoor btn-outdoor-ghost" href="export_geojson.php?id=<?= (int)$track['id'] ?>" title="QGIS, Leaflet, Mapbox">
            <i data-lucide="download" class="w-4 h-4" aria-hidden="true"></i> GeoJSON
        </a>
        <a class="btn-outdoor btn-outdoor-ghost" href="<?= h(gpx_url($track['filename'])) ?>" download>
            <i data-lucide="file-down" class="w-4 h-4" aria-hidden="true"></i> GPX
        </a>
        <button class="btn-outdoor btn-outdoor-ghost" id="qrToggleBtn" onclick="document.getElementById('qrBox').classList.toggle('qr-open')">
            <i data-lucide="qr-code" class="w-4 h-4" aria-hidden="true"></i> QR
        </button>

        <span class="ml-auto flex items-center gap-2">
            <label for="timeMode" class="text-sm text-forest-700/70 dark:text-sand-100/70">
                <?= htmlspecialchars(t('label_time_mode')) ?>
            </label>
            <select id="timeMode" class="select px-3 py-1.5 rounded-md bg-white dark:bg-forest-800 border border-sand-200 dark:border-forest-700 text-sm focus:border-forest-500 focus:outline-none">
                <option value="local" selected><?= htmlspecialchars(t('time_mode_local')) ?></option>
                <option value="utc"><?= htmlspecialchars(t('time_mode_utc')) ?></option>
            </select>
        </span>
    </div>

    <!-- QR box (toggle) -->
    <div id="qrBox" class="qr-box mt-3 p-4 rounded-xl bg-white dark:bg-forest-800 border border-sand-200 dark:border-forest-700 hidden flex-col items-center gap-2 max-w-sm">
        <img src="<?= h($qrApiUrl) ?>" alt="QR kód" width="180" height="180" loading="lazy" class="rounded-md">
        <p class="qr-url text-xs text-forest-700/70 dark:text-sand-100/70 break-all"><?= h($shareUrl) ?></p>
    </div>
</section>

<!-- ELEVATION PROFILE -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <div class="card-outdoor p-4">
        <h2 class="font-[Manrope] text-lg font-semibold text-forest-700 dark:text-sand-100 mb-3 flex items-center gap-2">
            <i data-lucide="line-chart" class="w-5 h-5" aria-hidden="true"></i>
            <?= htmlspecialchars(t('h2_elevation')) ?>
        </h2>
        <div id="elev-wrap" class="relative">
            <div class="relative" style="height: 320px;">
                <!-- A11Y-022: role="img" + aria-label for Chart.js canvas -->
                <canvas id="elev"
                        role="img"
                        aria-label="<?= htmlspecialchars(t('elev_chart_aria', 'Výškový profil trasy')) ?>"></canvas>
            </div>
            <!-- A11Y-022: hidden data table fallback — populated by detail-elevation.js -->
            <details class="sr-only" id="elev-data-table-container">
                <summary><?= htmlspecialchars(t('elev_data_table', 'Tabulka dat výškového profilu')) ?></summary>
                <table id="elev-data-table">
                    <caption><?= htmlspecialchars(t('elev_data_caption', 'Hodnoty nadmořské výšky podél trasy')) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?= htmlspecialchars(t('distance', 'Vzdálenost')) ?> (km)</th>
                            <th scope="col"><?= htmlspecialchars(t('elevation', 'Nadm. výška')) ?> (m)</th>
                        </tr>
                    </thead>
                    <tbody id="elev-data-tbody"></tbody>
                </table>
            </details>
            <div class="legend mt-2 text-xs text-forest-700/60 dark:text-sand-100/60">
                <?= htmlspecialchars(t('elev_legend_1')) ?>
                <?= htmlspecialchars(t('elev_legend_2')) ?> <strong><?= htmlspecialchars(t('elev_legend_3')) ?></strong> <?= htmlspecialchars(t('elev_legend_4')) ?>
            </div>
            <div class="badges mt-3 flex flex-wrap gap-2" id="stats"></div>
            <?php if (feature_enabled('baro_note')): ?>
            <!-- Barometrická kontrola výšky start/cíl — naplní js/detail-baro.js,
                 a to jen u okruhů s rozdílem výšky; jinak zůstane prázdné a skryté. -->
            <div id="baro-note" class="hidden"></div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- SIMILAR TRACKS -->
<section id="similar-wrap" class="mx-auto max-w-7xl px-4 sm:px-6 mt-8 mb-12 hidden">
    <h2 class="font-[Manrope] text-xl font-semibold text-forest-700 dark:text-sand-100 mb-4 flex items-center gap-2">
        <i data-lucide="git-fork" class="w-5 h-5" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h2_similar_tracks')) ?>
    </h2>
    <div id="similar-tracks" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>
</section>

<!-- Inline QR-box show class (Tailwind cannot generate dynamic classes for `.qr-open`) -->
<style>
#qrBox.qr-open { display: flex !important; }
.leaflet-container { background: var(--color-sand-100); }
.dark .leaflet-container { background: var(--color-forest-800); }
</style>

<!-- JS knihovny -->
<script defer
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH"
        crossorigin="anonymous"></script>
<script defer
        src="https://unpkg.com/leaflet.vectorgrid@1.3.0/dist/Leaflet.VectorGrid.bundled.js"
        integrity="sha384-FON5fTjCTtPuBgUS1r2H/PGXstH0Rk23YKjZmB6qITkbFqBcqtey/rPo9eXwOWpx"
        crossorigin="anonymous"></script>
<script defer
        src="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.js"
        integrity="sha384-Kigx+fLsY5TWX5hU/QUxy7tQh2bUzeIuoHUZTj2O056ByEtnhW6gi9ib8h6r5yb8"
        crossorigin="anonymous"></script>
<script defer
        src="https://cdnjs.cloudflare.com/ajax/libs/leaflet-gpx/1.7.0/gpx.min.js"
        integrity="sha384-FlFKgUqOEwuywgVc0+0QrDWcRsIzuyedLe+yUpC1jG4WgtdhJGvWf9mKm6GShpJv"
        crossorigin="anonymous"></script>
<script defer
        src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
        integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4"
        crossorigin="anonymous"></script>
<!-- Lightbox pro fotky v mapových vrstvách "Moje fotografie" a "Polohy fotek na trase" -->
<script src="<?= asset('js/lightbox.js') ?>"></script>

<!-- Data z PHP pro JS -->
<script>
window.gpxDetailData = {
    trackId:      <?= js_safe_json($track ? (int)$track['id'] : null) ?>,
    dateStart:    <?= js_safe_json($track['date_start'] ?? null) ?>,
    weatherLat:   <?= js_safe_json($_weatherLat ?? null) ?>,
    weatherLon:   <?= js_safe_json($_weatherLon ?? null) ?>,
    fileNameEnc:  <?= js_safe_json(rawurlencode($track['filename'] ?? '')) ?>,
    fileNamePlain:<?= js_safe_json($track['filename'] ?? '') ?>,
    trackTitle:   <?= js_safe_json(($track['track_name'] ?: $track['filename']) ?? 'profil') ?>,
    gpxUrl:       <?= js_safe_json(gpx_url($track['filename'] ?? '')) ?>,
    dbAvgSpeed:   <?= js_safe_json(round($track['speed_avg']       ?? 0, 3)) ?>,
    dbAvgMoving:  <?= js_safe_json(round($track['speed_avg_total'] ?? 0, 3)) ?>,
    garminColor:  <?= js_safe_json($track['color'] ?? '') ?>,
    trackDateStart: <?= js_safe_json((string)($track['date_start'] ?? '')) ?>,
    isAdmin:        <?= js_safe_json((bool)$_isAdmin) ?>,
    csrfToken:      <?= js_safe_json(csrf_token()) ?>,
    planI18n: {
        loading:  <?= js_safe_json(t('pl_loading', 'Načítám plán…')) ?>,
        error:    <?= js_safe_json(t('pl_error', 'Plán se nepodařilo načíst.')) ?>,
        noPlans:  <?= js_safe_json(t('pl_no_plans', 'Zatím nemáš uložený žádný plán s vypočítanou trasou.')) ?>,
        noGeom:   <?= js_safe_json(t('pl_no_geom', 'Tento plán nemá uloženou vypočítanou trasu.')) ?>,
        onPlan:   <?= js_safe_json(t('pl_on_plan', 'trasy podle plánu')) ?>,
        linkAdd:    <?= js_safe_json(t('pl_link_add',    'Uskutečněno touto trasou')) ?>,
        linkRemove: <?= js_safe_json(t('pl_link_remove', 'Zrušit propojení')) ?>,
        linked:     <?= js_safe_json(t('pl_linked',      'Plán je propojen s touto trasou.')) ?>,
        unlinked:   <?= js_safe_json(t('pl_unlinked',    'Propojení zrušeno.')) ?>,
        linkOther:  <?= js_safe_json(t('pl_link_other',  'Plán je už propojen s jinou trasou.')) ?>,
        maxDev:   <?= js_safe_json(t('pl_max_dev', 'nejdál od plánu')) ?>,
        avgDev:   <?= js_safe_json(t('pl_avg_dev', 'průměrně')) ?>,
        detours:  <?= js_safe_json(t('pl_detours', 'odboček')) ?>,
        planLen:  <?= js_safe_json(t('pl_plan_len', 'plán')) ?>,
        realLen:  <?= js_safe_json(t('pl_real_len', 'realita')) ?>
    },
    <?php // Bez zapnuté funkce nemá smysl posílat její texty do stránky. ?>
    <?php if (feature_enabled('baro_note')): ?>
    baroI18n: {
        baro_title:        <?= js_safe_json(t('baro_title')) ?>,
        baro_show:         <?= js_safe_json(t('baro_show')) ?>,
        baro_hide:         <?= js_safe_json(t('baro_hide')) ?>,
        baro_summary_diff: <?= js_safe_json(t('baro_summary_diff')) ?>,
        baro_loop:         <?= js_safe_json(t('baro_loop')) ?>,
        baro_pressure:     <?= js_safe_json(t('baro_pressure')) ?>,
        baro_verdict_full: <?= js_safe_json(t('baro_verdict_full')) ?>,
        baro_verdict_most: <?= js_safe_json(t('baro_verdict_most')) ?>,
        baro_verdict_part: <?= js_safe_json(t('baro_verdict_part')) ?>,
        baro_verdict_none: <?= js_safe_json(t('baro_verdict_none')) ?>,
        baro_rest:         <?= js_safe_json(t('baro_rest')) ?>,
        baro_verdict_device: <?= js_safe_json(t('baro_verdict_device')) ?>,
        baro_disclaimer:   <?= js_safe_json(t('baro_disclaimer')) ?>
    },
    <?php endif; ?>
    replayFlags: {
        weather: <?= js_safe_json(feature_enabled('replay') && feature_enabled('replay_weather')) ?>,
        radar:   <?= js_safe_json(feature_enabled('replay') && feature_enabled('replay_radar')) ?>,
        photos:  <?= js_safe_json(feature_enabled('replay') && feature_enabled('replay_photos')) ?>
    },
    replayI18n: {
        play:      <?= js_safe_json(t('rp_play', 'Přehrát výšlap')) ?>,
        pause:     <?= js_safe_json(t('rp_pause', 'Pauza')) ?>,
        noTimes:   <?= js_safe_json(t('rp_no_times', 'Trasa nemá časové značky — přehrávání jede po vzdálenosti (4 km/h).')) ?>,
        radarNone: <?= js_safe_json(t('rp_radar_none', 'V den výletu v okolí nepršelo.')) ?>,
        radarLoad: <?= js_safe_json(t('rp_radar_loading', 'Načítám srážková data…')) ?>,
        radarErr:  <?= js_safe_json(t('rp_radar_error', 'Srážková data se nepodařilo načíst.')) ?>,
        weatherNA: <?= js_safe_json(t('rp_weather_na', 'Počasí není k dispozici.')) ?>,
        photosOn:  <?= js_safe_json(t('rp_photos_on', 'Míjené fotky: zapnuto')) ?>,
        photosNone:<?= js_safe_json(t('rp_photos_none', 'Trasa nemá fotky s GPS polohou.')) ?>,
        radarMax:  <?= js_safe_json(t('rp_radar_max', 'max v oblasti: {v} mm/h')) ?>,
        radarNoFrames: <?= js_safe_json(t('rp_radar_no_frames', 'Radarové snímky pro tuto trasu nejsou stažené.')) ?>,
        radarFetching: <?= js_safe_json(t('rp_radar_fetching', 'Stahuji radarové snímky ČHMÚ…')) ?>,
        radarFetched:  <?= js_safe_json(t('rp_radar_fetched', 'Radar: {n} snímků')) ?>,
        radarTooOld:   <?= js_safe_json(t('rp_radar_too_old', 'Archiv ČHMÚ sahá jen ~7 dní zpět — pro tuto trasu už radar není k dispozici.')) ?>,
        radarChmi:     <?= js_safe_json(t('rp_radar_chmi_label', 'radar ČHMÚ')) ?>,
        csrf:          <?= js_safe_json(csrf_token()) ?>,
        wmo: {
            clear:    <?= js_safe_json(t('wmo_clear', 'jasno')) ?>,
            partly:   <?= js_safe_json(t('wmo_partly', 'polojasno')) ?>,
            overcast: <?= js_safe_json(t('wmo_overcast', 'zataženo')) ?>,
            fog:      <?= js_safe_json(t('wmo_fog', 'mlha')) ?>,
            drizzle:  <?= js_safe_json(t('wmo_drizzle', 'mrholení')) ?>,
            rain:     <?= js_safe_json(t('wmo_rain', 'déšť')) ?>,
            snow:     <?= js_safe_json(t('wmo_snow', 'sněžení')) ?>,
            showers:  <?= js_safe_json(t('wmo_showers', 'přeháňky')) ?>,
            snowShow: <?= js_safe_json(t('wmo_snow_showers', 'sněhové přeháňky')) ?>,
            storm:    <?= js_safe_json(t('wmo_storm', 'bouřka')) ?>
        }
    },
    apiKeys: {
        tf:        <?= js_safe_json(defined('TF_API_KEY') ? TF_API_KEY : '') ?>,
        mapycom:   <?= js_safe_json(defined('MAPYCOM_API_KEY') ? MAPYCOM_API_KEY : '') ?>,
        mapillary: <?= js_safe_json(defined('MAPILLARY_TOKEN') ? MAPILLARY_TOKEN : '') ?>
    },
    i18n: {
        elev_label_elev:  <?= js_safe_json(t('elev_label_elev')) ?>,
        elev_label_dist:  <?= js_safe_json(t('elev_label_dist')) ?>,
        slope_steep_down: <?= js_safe_json(t('slope_steep_down')) ?>,
        slope_mild_down:  <?= js_safe_json(t('slope_mild_down')) ?>,
        slope_flat:       <?= js_safe_json(t('slope_flat')) ?>,
        slope_steep_up:   <?= js_safe_json(t('slope_steep_up')) ?>,
        colorful_tooltip: <?= js_safe_json(t('colorful_tooltip')) ?>
    }
};
</script>

<?php if ($_isAdmin): ?>
<!-- Předpřipraví obrázek pro náhled sdíleného odkazu (uploads/share/).
     U nové trasy trvá vykreslení z OSM dlaždic okolo 9 s; když ho spustí
     otevření detailu, je hotový dřív, než odkaz někam vložíš. Běží jen
     administrátorovi a jen jednou — podruhé endpoint jen potvrdí hotový soubor. -->
<script>
    window.addEventListener("load", function () {
        fetch("share_image.php?id=<?= (int)$track['id'] ?>&warm=1", { credentials: "same-origin" })
            .catch(function () { /* náhled odkazu není kritický */ });
    });
</script>
<?php endif; ?>

<!-- Sdílené lib moduly — musí být načteny jako první -->
<script src="<?= asset('js/lib/event-bus.js') ?>"></script>
<script src="<?= asset('js/lib/geo-utils.js') ?>"></script>
<script src="<?= asset('js/lib/format-utils.js') ?>"></script>
<script src="<?= asset('js/lib/map-factory.js') ?>"></script>

<!-- JS moduly -->
<script src="<?= asset('js/detail-data.js') ?>"></script>
<script src="<?= asset('js/detail-map.js') ?>"></script>
<script src="<?= asset('js/detail-elevation.js') ?>"></script>
<?php if (feature_enabled('replay')): ?><script src="<?= asset('js/detail-replay.js') ?>"></script><?php endif; ?>
<?php if (feature_enabled('plan_overlay')): ?><script src="<?= asset('js/detail-plan.js') ?>"></script><?php endif; ?>
<?php if (feature_enabled('baro_note')): ?><script src="<?= asset('js/detail-baro.js') ?>"></script><?php endif; ?>
<script src="<?= asset('js/detail-ui.js') ?>"></script>
<script src="<?= asset('js/detail-weather.js') ?>"></script>

<!-- Podobné trasy -->
<script>
(function() {
    const trackId = <?= (int)$track['id'] ?>;

    // HTML escape for user-controlled fields rendered via innerHTML
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[m]));
    }

    function formatDuration(s) {
        if (!s || s <= 0) return '–';
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        return h + ':' + String(m).padStart(2, '0');
    }
    function formatDate(d) {
        if (!d) return '–';
        const dt = new Date(d);
        if (isNaN(dt.getTime())) return d;
        return dt.toLocaleDateString('cs-CZ');
    }
    function difficultyDots(level) {
        if (!level || level < 1) return '–';
        const colors = ['', '#5b8a75', '#84ad94', '#e89455', '#c97b3f', '#a8443a'];
        const filled = '●'.repeat(level);
        const empty = '○'.repeat(5 - level);
        return '<span style="color:' + (colors[level] || '#999') + '">' + filled + empty + '</span>';
    }

    // Přes detail.php, ne přímo na includes/detail_data.php — soubory v includes/
    // nejsou veřejné vstupní body a nemají vlastní auth/bootstrap.
    fetch('detail.php?ajax=similar&id=' + trackId)
        .then(r => r.json())
        .then(data => {
            const tracks = data.tracks || [];
            if (tracks.length === 0) return;

            const wrap = document.getElementById('similar-wrap');
            const grid = document.getElementById('similar-tracks');
            if (!wrap || !grid) return;

            grid.innerHTML = '';
            tracks.forEach(t => {
                const thumbName = t.filename.replace(/\.[^.]+$/, '') + '.png';
                const card = document.createElement('a');
                card.href = 'detail.php?id=' + t.id;
                card.className = 'card-outdoor block p-3 group';
                card.innerHTML =
                    '<div class="aspect-[16/9] rounded-md overflow-hidden bg-gradient-to-br from-forest-400 to-forest-700 mb-3 relative">' +
                        '<img src="<?= h(uploads_url('thumbs/')) ?>' + encodeURIComponent(thumbName) + '" class="w-full h-full object-cover" alt="" onerror="this.style.display=\'none\'">' +
                    '</div>' +
                    '<div class="font-[Manrope] font-semibold text-forest-700 dark:text-sand-100 group-hover:text-terracotta-500 transition-colors line-clamp-1">' + escHtml(t.track_name || t.filename) + '</div>' +
                    '<div class="mt-1 text-sm text-forest-700/70 dark:text-sand-100/70">' +
                        '<span class="stat-num">' + escHtml(t.distance_km) + '</span> km · ↑<span class="stat-num">' + escHtml(t.ascent) + '</span> m · ' + escHtml(formatDuration(t.duration)) +
                    '</div>' +
                    '<div class="mt-0.5 text-xs text-forest-700/60 dark:text-sand-100/60">' +
                        formatDate(t.date_start) + ' · ' + difficultyDots(t.difficulty) +
                    '</div>';
                grid.appendChild(card);
            });

            wrap.classList.remove('hidden');
            if (window.lucide) lucide.createIcons();
        })
        .catch(err => console.error('Podobné trasy:', err));
})();
</script>

<?php require __DIR__ . '/layout_footer.php'; ?>
