<?php
declare(strict_types=1);

/**
 * year_view.php — HTML of the year summary (year.php).
 * Expects: $yr (year_build()), $years (year_list()), $showPhotos, $_isAdmin.
 * All values passed to h() are strings (strict_types).
 */

$y        = (int)$yr['year'];
$tot      = $yr['totals'];
$prev     = $yr['prev'];
$monthsS  = t_arr('months_short');
$monthsF  = t_arr('months_full');
$idx      = array_search($y, $years, true);
$newer    = ($idx !== false && $idx > 0) ? $years[$idx - 1] : null;
$older    = ($idx !== false && $idx < count($years) - 1) ? $years[$idx + 1] : null;

/** Change against the previous year as "+12 %" (null when not comparable). */
$delta = static function (float $now, float $before): ?string {
    if ($before <= 0) {
        return null;
    }
    $p = (int)round(($now - $before) / $before * 100);
    return ($p > 0 ? '+' : '') . $p . ' %';
};
// Same title as on the track detail; place only from the DB (no geocoding here)
$trackTitle = static function (array $r) use ($pdo): string {
    $r['place_name'] = $r['place_name'] ?? '';
    return track_display_title($pdo, $r);
};
$fmtDate = static fn(?string $d): string => $d ? date('j. n.', strtotime($d)) : '';
?>
<style>
    .yr-hero { background: linear-gradient(135deg, rgba(63,107,87,.12), rgba(201,123,63,.10)); }
    .yr-kpi-delta { font-size: 11px; margin-top: 2px; }
    .yr-kpi-delta.up { color: #2f7a4f; }
    .yr-kpi-delta.down { color: #a8443a; }
    html.dark .yr-kpi-delta.up { color: #8fd1a8; }
    html.dark .yr-kpi-delta.down { color: #f0a39a; }
    .yr-photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
    .yr-photos a { display: block; aspect-ratio: 4 / 3; overflow: hidden; border-radius: 8px; background: rgba(0,0,0,.05); }
    .yr-photos img { width: 100%; height: 100%; object-fit: cover; display: block; }
    #yrMap { height: 460px; border-radius: 12px; z-index: 0; }
    .yr-chip { display: inline-block; padding: 3px 10px; margin: 0 6px 6px 0; border-radius: 999px; font-size: 13px;
               background: rgba(63,107,87,.10); color: inherit; }
    html.dark .yr-chip { background: rgba(255,255,255,.08); }
    .yr-list li { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; border-bottom: 1px dashed rgba(0,0,0,.08); }
    html.dark .yr-list li { border-color: rgba(255,255,255,.08); }
    .yr-list a { color: inherit; text-decoration: none; }
    .yr-list a:hover { color: #c97b3f; }
    .yr-nav a, .yr-nav span { display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; border-radius: 8px; font-size: 14px; }
    .yr-nav a { border: 1px solid rgba(63,107,87,.25); color: inherit; text-decoration: none; }
    .yr-nav a:hover { border-color: #c97b3f; color: #c97b3f; }
    .yr-nav select { padding: 6px 10px; border-radius: 8px; border: 1px solid rgba(63,107,87,.25); background: transparent; color: inherit; }
    html.dark .yr-nav select option { background: #243028; }
</style>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="stats.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i> <?= h(t('nav_stats', 'Statistiky')) ?>
    </a>
    <div class="yr-hero card-outdoor p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
                    <i data-lucide="calendar-range" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
                    <?= h(str_replace('{y}', (string)$y, t('yr_title', 'Rok {y}'))) ?>
                </h1>
                <p class="mt-1 text-forest-700/70 dark:text-sand-100/70 text-sm"><?= h(t('yr_subtitle', 'Souhrn výletů za celý rok')) ?></p>
            </div>
            <nav class="yr-nav flex flex-wrap items-center gap-2" aria-label="<?= h(t('yr_nav', 'Jiný rok')) ?>">
                <?php if ($older !== null): ?>
                    <a href="year.php?y=<?= $older ?>"><i data-lucide="chevron-left" class="w-4 h-4" aria-hidden="true"></i><?= $older ?></a>
                <?php endif; ?>
                <form method="get" action="year.php" data-autosubmit>
                    <label class="sr-only" for="yrSelect"><?= h(t('yr_nav', 'Jiný rok')) ?></label>
                    <select id="yrSelect" name="y">
                        <?php foreach ($years as $yy): ?>
                            <option value="<?= $yy ?>"<?= $yy === $y ? ' selected' : '' ?>><?= $yy ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit">OK</button></noscript>
                </form>
                <?php if ($newer !== null): ?>
                    <a href="year.php?y=<?= $newer ?>"><?= $newer ?><i data-lucide="chevron-right" class="w-4 h-4" aria-hidden="true"></i></a>
                <?php endif; ?>
            </nav>
        </div>
    </div>
</section>

<?php if ((int)$tot['tracks'] === 0): ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 mb-12">
    <div class="card-outdoor p-6 text-forest-700/70 dark:text-sand-100/70"><?= h(t('yr_empty', 'V tomto roce nejsou žádné trasy.')) ?></div>
</section>
<?php else: ?>

<!-- ===== KPI ===== -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <?php
        $kpis = [
            ['route',     (string)(int)$tot['tracks'],               t('yr_kpi_tracks', 'výletů'),          $delta((float)$tot['tracks'], (float)$prev['tracks'])],
            ['ruler',     fmtDist((float)$tot['km'], 0),             t('yr_kpi_km', 'celkem ušlo/ujeto'),   $delta((float)$tot['km'], (float)$prev['km'])],
            ['mountain',  fmtElev((float)$tot['ascent']),            t('yr_kpi_ascent', 'nastoupáno'),      $delta((float)$tot['ascent'], (float)$prev['ascent'])],
            ['sun',       (string)(int)$tot['days'],                 t('yr_kpi_days', 'dní venku'),         $delta((float)$tot['days'], (float)$prev['days'])],
            ['clock',     number_format((float)$tot['duration'] / 3600, 0, ',', ' '), t('yr_kpi_hours', 'hodin na cestě'), $delta((float)$tot['duration'], (float)$prev['duration'])],
            ['mountain-snow', fmtElev((float)$tot['top_elev']),      t('yr_kpi_top', 'nejvýš'),             null],
            ['star',      (string)(int)$tot['favs'],                 t('yr_kpi_favs', 'oblíbených'),        null],
        ];
        if ($showPhotos) {
            $kpis[] = ['camera', number_format((float)$yr['photo_count'], 0, ',', ' '), t('yr_kpi_photos', 'fotografií'), null];
        }
        foreach ($kpis as [$icon, $val, $lbl, $d]): ?>
            <div class="card-outdoor p-4">
                <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                    <i data-lucide="<?= $icon ?>" class="w-3.5 h-3.5" aria-hidden="true"></i>
                </div>
                <div class="mt-1.5 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100"><?= h($val) ?></div>
                <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5"><?= h($lbl) ?></div>
                <?php if ($d !== null): ?>
                    <div class="yr-kpi-delta <?= $d[0] === '+' ? 'up' : ($d[0] === '-' ? 'down' : '') ?>"
                         title="<?= h(str_replace('{y}', (string)($y - 1), t('yr_vs_prev', 'proti roku {y}'))) ?>">
                        <?= h($d) ?> <span class="opacity-70"><?= h(str_replace('{y}', (string)($y - 1), t('yr_vs_prev', 'proti roku {y}'))) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($yr['best_month'] !== null): ?>
        <p class="mt-3 text-sm text-forest-700/70 dark:text-sand-100/70">
            <?= h(str_replace(['{m}', '{km}'], [(string)($monthsF[$yr['best_month']] ?? ''), fmtDist((float)$yr['months']['km'][$yr['best_month'] - 1], 0)],
                t('yr_best_month', 'Nejsilnější měsíc: {m} ({km}).'))) ?>
        </p>
    <?php endif; ?>
</section>

<!-- ===== MAPA ===== -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-10">
    <h2 class="font-[Manrope] text-xs uppercase tracking-widest text-forest-700/60 dark:text-sand-100/60 mb-3"><?= h(t('yr_map', 'Všechny trasy roku na mapě')) ?></h2>
    <div class="card-outdoor p-2">
        <div id="yrMap" role="region" aria-label="<?= h(t('yr_map', 'Všechny trasy roku na mapě')) ?>"></div>
    </div>
</section>

<!-- ===== MĚSÍCE ===== -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-10">
    <h2 class="font-[Manrope] text-xs uppercase tracking-widest text-forest-700/60 dark:text-sand-100/60 mb-3"><?= h(t('yr_by_month', 'Po měsících')) ?></h2>
    <div class="card-outdoor p-4">
        <div style="height:280px;"><canvas id="yrMonthChart" aria-label="<?= h(t('yr_by_month', 'Po měsících')) ?>" role="img"></canvas></div>
    </div>
</section>

<!-- ===== VÝBĚR ===== -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-10">
    <div class="grid lg:grid-cols-3 gap-4">
        <div class="card-outdoor p-5 lg:col-span-2">
            <h2 class="font-[Manrope] text-xs uppercase tracking-widest text-forest-700/60 dark:text-sand-100/60 mb-3">
                <?= h(t('yr_longest', 'Nejdelší výlety')) ?>
                <span class="normal-case tracking-normal opacity-70">— <?= h(t('records_foot_only', 'jen pěší aktivity')) ?></span>
            </h2>
            <?php if ($yr['longest']): ?>
            <ol class="yr-list text-sm">
                <?php foreach ($yr['longest'] as $r): ?>
                    <li>
                        <a href="detail.php?id=<?= (int)$r['id'] ?>"><?= h($trackTitle($r)) ?></a>
                        <span class="stat-num whitespace-nowrap opacity-80"><?= h(fmtDist((float)$r['distance_km'])) ?> · ↑ <?= h(fmtElev((float)$r['ascent'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php else: ?>
                <p class="text-sm opacity-70">—</p>
            <?php endif; ?>
        </div>
        <div class="card-outdoor p-5">
            <h2 class="font-[Manrope] text-xs uppercase tracking-widest text-forest-700/60 dark:text-sand-100/60 mb-3"><?= h(t('yr_records', 'Rekordy roku')) ?></h2>
            <ul class="yr-list text-sm">
                <?php foreach ([
                    ['most_ascent', t('record_most_ascent'), static fn($r) => fmtElev((float)$r['ascent'])],
                    ['highest',     t('record_highest'),     static fn($r) => fmtElev((float)$r['elevation_max'])],
                ] as [$k, $label, $fmt]):
                    $r = $yr[$k];
                    if (!$r) continue; ?>
                    <li>
                        <span><?= h($label) ?><br><a href="detail.php?id=<?= (int)$r['id'] ?>" class="opacity-80"><?= h($trackTitle($r)) ?></a></span>
                        <span class="stat-num font-semibold whitespace-nowrap"><?= h($fmt($r)) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($yr['activities']): ?>
            <h3 class="font-[Manrope] text-xs uppercase tracking-widest text-forest-700/60 dark:text-sand-100/60 mt-5 mb-2"><?= h(t('th_activity')) ?></h3>
            <ul class="yr-list text-sm">
                <?php foreach ($yr['activities'] as $a): ?>
                    <li><span><?= activityBadge((string)$a['activity_type']) ?></span>
                        <span class="stat-num whitespace-nowrap"><?= (int)$a['cnt'] ?> × · <?= h(fmtDist((float)$a['km'], 0)) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($yr['favorites']): ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-10">
    <h2 class="font-[Manrope] text-xs uppercase tracking-widest text-forest-700/60 dark:text-sand-100/60 mb-3"><?= h(t('yr_favorites', 'Oblíbené výlety roku')) ?></h2>
    <div class="card-outdoor p-5">
        <ul class="yr-list text-sm">
            <?php foreach ($yr['favorites'] as $r): ?>
                <li><a href="detail.php?id=<?= (int)$r['id'] ?>">★ <?= h($trackTitle($r)) ?></a>
                    <span class="stat-num whitespace-nowrap opacity-80"><?= h(fmtDist((float)$r['distance_km'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php if ($yr['new_places']): ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-10">
    <h2 class="font-[Manrope] text-xs uppercase tracking-widest text-forest-700/60 dark:text-sand-100/60 mb-3">
        <?= h(str_replace('{n}', (string)count($yr['new_places']), t('yr_new_places', 'Poprvé navštívená místa ({n})'))) ?>
    </h2>
    <div class="card-outdoor p-5">
        <?php foreach (array_slice($yr['new_places'], 0, 60) as $p): ?>
            <a class="yr-chip" href="index.php?q=<?= rawurlencode((string)$p['place_name']) ?>"><?= h((string)$p['place_name']) ?></a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($showPhotos && $yr['photos']): ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-10">
    <h2 class="font-[Manrope] text-xs uppercase tracking-widest text-forest-700/60 dark:text-sand-100/60 mb-3"><?= h(t('yr_photos', 'Rok ve fotkách')) ?></h2>
    <div class="yr-photos">
        <?php foreach ($yr['photos'] as $p):
            $href = $p['track_id'] ? 'detail.php?id=' . (int)$p['track_id'] : photo_full_url((string)$p['filename']); ?>
            <a href="<?= h($href) ?>" title="<?= h(trim((string)($p['caption'] ?? '')) ?: $fmtDate($p['taken_at'])) ?>">
                <img src="<?= h(photo_thumb_url((string)$p['filename'])) ?>" alt="<?= h(trim((string)($p['caption'] ?? ''))) ?>" loading="lazy">
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="mb-12"></div>

<link rel="stylesheet" href="assets/vendor/leaflet-1.9.4/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<script defer src="assets/vendor/leaflet-1.9.4/leaflet.js" integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin="anonymous"></script>
<script defer src="<?= asset('js/lib/map-factory.js') ?>"></script>
<script defer src="assets/vendor/chart.js-4.4.1/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous"></script>
<script defer src="<?= asset('js/chart-theme.js') ?>"></script>
<script defer src="<?= asset('js/track-set-map.js') ?>"></script>
<script defer src="<?= asset('js/year-page.js') ?>"></script>
<script nonce="<?= csp_nonce() ?>">
window.gpxYearData = <?= js_safe_json([
    'year'       => $y,
    'linesUrl'   => 'api/tracks/lines_set.php?year=' . $y,
    'apiKeys'    => ['tf' => TF_API_KEY, 'mapycom' => MAPYCOM_API_KEY, 'mapillary' => MAPILLARY_TOKEN],
    'months'     => array_slice($monthsS, 1),
    'km'         => $yr['months']['km'],
    'kmPrev'     => $yr['months_prev']['km'],
    'i18n'       => [
        'km'         => str_replace('{y}', (string)$y, t('yr_chart_km', 'km v roce {y}')),
        'kmPrev'     => str_replace('{y}', (string)($y - 1), t('yr_chart_km', 'km v roce {y}')),
        'trackDetail'=> t('mp_track_detail', 'Detail trasy'),
        'loading'    => t('yr_map_loading', 'Načítám trasy…'),
    ],
]) ?>;
</script>
<?php endif; ?>
