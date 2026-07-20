<?php
/**
 * Statistický dashboard — Outdoor redesign.
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
check_page_access('stats');

$allowedLangs = available_langs();

/* ===== Celkové statistiky ===== */
$totals = $pdo->query("
    SELECT
        COUNT(*)           AS total_tracks,
        COALESCE(SUM(distance_km), 0)  AS total_km,
        COALESCE(SUM(ascent), 0)       AS total_ascent,
        COALESCE(SUM(descent), 0)      AS total_descent,
        COALESCE(AVG(speed_avg), 0)    AS avg_speed,
        COALESCE(SUM(duration), 0)     AS total_duration,
        COALESCE(SUM(moving_time), 0)  AS total_moving,
        COALESCE(MIN(date_start), '')  AS first_date,
        COALESCE(MAX(date_start), '')  AS last_date,
        COUNT(CASE WHEN is_favorite = 1 THEN 1 END) AS fav_count
    FROM tracks
")->fetch(PDO::FETCH_ASSOC);

/* ===== Rekordy ===== */
$records = [];
$r = $pdo->query("SELECT id, track_name, filename, distance_km FROM tracks ORDER BY distance_km DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($r) $records['longest'] = $r;
$r = $pdo->query("SELECT id, track_name, filename, ascent FROM tracks ORDER BY ascent DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($r) $records['most_ascent'] = $r;
$r = $pdo->query("SELECT id, track_name, filename, elevation_max FROM tracks ORDER BY elevation_max DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($r) $records['highest'] = $r;
$r = $pdo->query("SELECT id, track_name, filename, speed_max FROM tracks WHERE speed_max IS NOT NULL ORDER BY speed_max DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($r) $records['fastest'] = $r;
$r = $pdo->query("SELECT id, track_name, filename, duration FROM tracks WHERE duration IS NOT NULL ORDER BY duration DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($r) $records['longest_time'] = $r;
$r = $pdo->query("SELECT id, track_name, filename, difficulty FROM tracks WHERE difficulty IS NOT NULL ORDER BY difficulty DESC, ascent DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($r) $records['hardest'] = $r;

/* ===== Statistiky po letech ===== */
$yearly = $pdo->query("
    SELECT YEAR(date_start) AS yr, COUNT(*) AS tracks, SUM(distance_km) AS km,
           SUM(ascent) AS ascent, SUM(descent) AS descent, AVG(speed_avg) AS avg_speed, SUM(duration) AS duration
    FROM tracks WHERE date_start IS NOT NULL
    GROUP BY YEAR(date_start) ORDER BY yr DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ===== Statistiky po měsících ===== */
$monthly = $pdo->query("
    SELECT DATE_FORMAT(date_start, '%Y-%m') AS month, COUNT(*) AS tracks,
           SUM(distance_km) AS km, SUM(ascent) AS ascent, AVG(speed_avg) AS avg_speed
    FROM tracks WHERE date_start IS NOT NULL
    GROUP BY DATE_FORMAT(date_start, '%Y-%m') ORDER BY month ASC
")->fetchAll(PDO::FETCH_ASSOC);

$chart_labels = []; $chart_km = []; $chart_count = []; $chart_ascent = []; $chart_avg_speed = [];
foreach ($monthly as $m) {
    $chart_labels[] = $m['month'];
    $chart_km[] = round((float)$m['km'], 1);
    $chart_count[] = (int)$m['tracks'];
    $chart_ascent[] = round((float)$m['ascent'], 0);
    $chart_avg_speed[] = round((float)$m['avg_speed'], 1);
}

/* ===== Rozložení obtížnosti ===== */
$diffDist = $pdo->query("
    SELECT difficulty, COUNT(*) AS cnt FROM tracks
    WHERE difficulty IS NOT NULL GROUP BY difficulty ORDER BY difficulty
")->fetchAll(PDO::FETCH_ASSOC);
$diffLabels = []; $diffValues = [];
// Outdoor paleta pro doughnut: světlá zelená → tmavá zelená → terracotta → tmavá červená
$diffColors = ['#84ad94', '#5b8a75', '#3f6b57', '#e89455', '#a8443a'];
foreach ($diffDist as $dd) {
    $diffLabels[] = difficultyLabel((int)$dd['difficulty']);
    $diffValues[] = (int)$dd['cnt'];
}

/* ===== Top 10 kategorií ===== */
$topCats = $pdo->query("
    SELECT c.name, COUNT(tc.track_id) AS cnt FROM categories c
    JOIN track_categories tc ON tc.category_id = c.id
    GROUP BY c.id, c.name ORDER BY cnt DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$_isAdmin = !empty($_SESSION['is_admin']);
$page_title = t('h1_stats');
require __DIR__ . '/includes/layout_header.php';
?>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars(t('back_to_list')) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="bar-chart-2" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h1_stats')) ?>
    </h1>
    <p class="mt-1 text-forest-700/70 dark:text-sand-100/70 text-sm">
        <?= htmlspecialchars(t('stats_subtitle')) ?>
    </p>
</section>

<!-- ===== CELKOVÉ STATISTIKY ===== -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <h2 class="font-[Manrope] text-xs uppercase tracking-widest text-forest-700/60 dark:text-sand-100/60 mb-3">
        <?= htmlspecialchars(t('section_overall')) ?>
    </h2>
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
        <?php
        $kpis = [
            ['route',       (int)$totals['total_tracks'],                                    t('stat_total_tracks'),         'forest-700'],
            ['ruler',       fmtDist((float)$totals['total_km'], 0),                          t('stat_total_dist'),     'terracotta-500'],
            ['mountain',    fmtElev((float)$totals['total_ascent']),                         t('stat_total_ascent'),     'forest-600'],
            ['arrow-down',  fmtElev((float)$totals['total_descent']),                        t('stat_total_descent'),     'bark-700'],
            ['clock',       number_format((float)$totals['total_duration'] / 3600, 0, ',', ' '), t('stat_total_hours'),     'forest-700'],
            ['gauge',       fmtSpeed((float)$totals['avg_speed']),                           t('stat_avg_speed'),  'forest-600'],
            ['star',        (int)$totals['fav_count'],                                       t('stat_favorites'),        'terracotta-500'],
            ['calendar',    ($totals['first_date'] ? substr($totals['first_date'], 0, 4) : '—') . '–' . ($totals['last_date'] ? substr($totals['last_date'], 0, 4) : '—'), t('stat_period'), 'forest-700'],
        ];
        foreach ($kpis as [$icon, $val, $lbl, $color]): ?>
            <div class="card-outdoor p-4">
                <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                    <i data-lucide="<?= $icon ?>" class="w-3.5 h-3.5" aria-hidden="true"></i>
                </div>
                <div class="mt-1.5 stat-num text-2xl font-semibold text-<?= $color ?> dark:text-sand-100"><?= $val ?></div>
                <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5"><?= htmlspecialchars($lbl) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ===== REKORDY ===== -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-10">
    <h2 class="font-[Manrope] text-xs uppercase tracking-widest text-forest-700/60 dark:text-sand-100/60 mb-3">
        <?= htmlspecialchars(t('section_records')) ?>
    </h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php
        $recordCards = [
            'longest'      => ['ruler',         t('record_longest'),       isset($records['longest'])      ? fmtDist((float)$records['longest']['distance_km']) : null],
            'most_ascent'  => ['mountain',      t('record_most_ascent'), isset($records['most_ascent'])  ? fmtElev((float)$records['most_ascent']['ascent']) : null],
            'highest'      => ['mountain-snow', t('record_highest'),         isset($records['highest'])      ? fmtElev((float)$records['highest']['elevation_max']) : null],
            'fastest'      => ['zap',           t('record_fastest'),         isset($records['fastest'])      ? fmtSpeed((float)$records['fastest']['speed_max']) : null],
            'longest_time' => ['hourglass',     t('record_longest_time'),    isset($records['longest_time']) ? formatSecondsToHMS((int)$records['longest_time']['duration']) : null],
            'hardest'      => ['flame',         t('record_hardest'),       isset($records['hardest'])      ? difficultyBadge((int)$records['hardest']['difficulty']) : null],
        ];
        foreach ($recordCards as $key => [$icon, $title, $value]):
            if (!isset($records[$key])) continue;
            $r = $records[$key];
        ?>
            <div class="card-outdoor p-5">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 inline-flex items-center justify-center rounded-xl bg-gradient-to-br from-forest-100 to-forest-200 dark:from-forest-700 dark:to-forest-800 text-forest-700 dark:text-terracotta-300 shrink-0">
                        <i data-lucide="<?= $icon ?>" class="w-6 h-6" aria-hidden="true"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60"><?= htmlspecialchars($title) ?></div>
                        <div class="mt-1 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100"><?= $value ?></div>
                        <a href="detail.php?id=<?= (int)$r['id'] ?>" class="mt-1 inline-flex items-center gap-1 text-sm text-forest-600 hover:text-terracotta-500 transition-colors line-clamp-1">
                            <span class="line-clamp-1"><?= h($r['track_name'] ?: $r['filename']) ?></span>
                            <i data-lucide="arrow-up-right" class="w-3 h-3 shrink-0" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ===== GRAFY ===== -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-10">
    <h2 class="font-[Manrope] text-xs uppercase tracking-widest text-forest-700/60 dark:text-sand-100/60 mb-3">
        <?= htmlspecialchars(t('section_trends')) ?>
    </h2>
    <div class="grid lg:grid-cols-3 gap-4">
        <div class="card-outdoor p-5 lg:col-span-2">
            <div class="flex items-center justify-between mb-3 gap-2 flex-wrap">
                <h3 class="font-[Manrope] font-semibold text-forest-700 dark:text-sand-100 flex items-center gap-2">
                    <i data-lucide="line-chart" class="w-4 h-4" aria-hidden="true"></i>
                    <?= htmlspecialchars(t('chart_monthly')) ?>
                </h3>
                <select id="metricSelector" class="px-3 py-1.5 rounded-md bg-white dark:bg-forest-700 border border-sand-200 dark:border-forest-600 text-sm focus:border-forest-500 focus:outline-none">
                    <option value="km" selected><?= htmlspecialchars(t('metric_distance')) ?></option>
                    <option value="count"><?= htmlspecialchars(t('metric_count')) ?></option>
                    <option value="ascent"><?= htmlspecialchars(t('metric_ascent')) ?></option>
                    <option value="avg_speed"><?= htmlspecialchars(t('metric_avg_speed')) ?></option>
                </select>
            </div>
            <div style="height: 280px;">
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>

        <div class="card-outdoor p-5">
            <h3 class="font-[Manrope] font-semibold text-forest-700 dark:text-sand-100 mb-3 flex items-center gap-2">
                <i data-lucide="pie-chart" class="w-4 h-4" aria-hidden="true"></i>
                <?= htmlspecialchars(t('chart_difficulty')) ?>
            </h3>
            <div style="height: 200px;">
                <canvas id="diffChart"></canvas>
            </div>

            <?php if (!empty($topCats)): ?>
            <div class="mt-5 pt-4 border-t border-sand-200 dark:border-forest-700">
                <h3 class="font-[Manrope] font-semibold text-forest-700 dark:text-sand-100 mb-2 flex items-center gap-2 text-sm">
                    <i data-lucide="tags" class="w-4 h-4" aria-hidden="true"></i>
                    <?= htmlspecialchars(t('chart_top_cats')) ?>
                </h3>
                <ul class="space-y-1.5">
                    <?php foreach ($topCats as $tc): ?>
                        <li class="flex items-center justify-between text-sm">
                            <span class="text-forest-700/80 dark:text-sand-100/80 truncate"><?= h($tc['name']) ?></span>
                            <span class="stat-num inline-flex items-center justify-center min-w-[28px] px-2 py-0.5 rounded-full bg-forest-100 dark:bg-forest-700 text-forest-700 dark:text-sand-100 text-xs font-medium"><?= (int)$tc['cnt'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ===== ROČNÍ TABULKA ===== -->
<?php if (!empty($yearly)): ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-10 mb-12">
    <h2 class="font-[Manrope] text-xs uppercase tracking-widest text-forest-700/60 dark:text-sand-100/60 mb-3">
        <?= htmlspecialchars(t('section_yearly')) ?>
    </h2>
    <div class="card-outdoor overflow-hidden">
        <div class="overflow-x-auto">
            <?php $du = app_units() === 'imperial' ? 'mi' : 'km'; $eu = app_units() === 'imperial' ? 'ft' : 'm'; $su = app_units() === 'imperial' ? 'mph' : 'km/h'; ?>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60 border-b border-sand-200 dark:border-forest-700">
                        <th class="px-4 py-3"><?= htmlspecialchars(t('th_year')) ?></th>
                        <th class="px-4 py-3 text-right"><?= htmlspecialchars(t('th_tracks_count')) ?></th>
                        <th class="px-4 py-3 text-right"><?= htmlspecialchars(t('th_distance')) ?> (<?= $du ?>)</th>
                        <th class="px-4 py-3 text-right"><?= htmlspecialchars(t('th_ascent')) ?> (<?= $eu ?>)</th>
                        <th class="px-4 py-3 text-right"><?= htmlspecialchars(t('th_descent')) ?> (<?= $eu ?>)</th>
                        <th class="px-4 py-3 text-right"><?= htmlspecialchars(t('th_speed_avg')) ?> (<?= $su ?>)</th>
                        <th class="px-4 py-3 text-right"><?= htmlspecialchars(t('th_total_time')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand-100 dark:divide-forest-700">
                    <?php foreach ($yearly as $y): ?>
                        <tr class="hover:bg-sand-50 dark:hover:bg-forest-800/50 transition-colors">
                            <td class="px-4 py-2.5 stat-num font-semibold text-forest-700 dark:text-sand-100"><?= (int)$y['yr'] ?></td>
                            <td class="px-4 py-2.5 text-right stat-num"><?= (int)$y['tracks'] ?></td>
                            <td class="px-4 py-2.5 text-right stat-num"><?= fmtDist((float)$y['km']) ?></td>
                            <td class="px-4 py-2.5 text-right stat-num text-forest-600"><?= fmtElev((float)$y['ascent']) ?></td>
                            <td class="px-4 py-2.5 text-right stat-num text-terracotta-500"><?= fmtElev((float)$y['descent']) ?></td>
                            <td class="px-4 py-2.5 text-right stat-num"><?= fmtSpeed((float)$y['avg_speed']) ?></td>
                            <td class="px-4 py-2.5 text-right stat-num"><?= formatSecondsToHMS((int)$y['duration']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<style>.line-clamp-1 { display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }</style>

<!-- Chart.js + outdoor theme -->
<script defer
        src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
        integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4"
        crossorigin="anonymous"></script>
<script src="<?= asset('js/chart-theme.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const labels = <?= js_safe_json($chart_labels) ?>;
    const datasets = {
        km:        <?= js_safe_json($chart_km) ?>,
        count:     <?= js_safe_json($chart_count) ?>,
        ascent:    <?= js_safe_json($chart_ascent) ?>,
        avg_speed: <?= js_safe_json($chart_avg_speed) ?>
    };
    const labelMap = {
        km:        <?= js_safe_json(t('metric_distance')) ?>,
        count:     <?= js_safe_json(t('metric_count')) ?>,
        ascent:    <?= js_safe_json(t('metric_ascent')) ?>,
        avg_speed: <?= js_safe_json(t('metric_avg_speed')) ?>
    };

    const palette = window.OutdoorChartTheme || { forest700: '#2d4a3e', forest500: '#3f6b57', terracotta500: '#c97b3f', textMuted: '#5b7568', gridSoft: 'rgba(45,74,62,.08)' };

    const ctx = document.getElementById('monthlyChart').getContext('2d');
    const chart = new Chart(ctx, {
        type: 'bar',
        data: { labels, datasets: [{
            label: labelMap.km,
            data: datasets.km,
            backgroundColor: palette.forest500 + 'CC',
            borderColor: palette.forest700,
            borderWidth: 1,
            borderRadius: 4
        }]},
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: palette.textMuted, maxRotation: 60, font: { family: 'Inter', size: 11 } }, grid: { color: palette.gridSoft } },
                y: { ticks: { color: palette.textMuted, font: { family: 'JetBrains Mono', size: 11 } }, grid: { color: palette.gridSoft }, beginAtZero: true }
            }
        }
    });

    document.getElementById('metricSelector').addEventListener('change', function() {
        const key = this.value;
        chart.data.datasets[0].data = datasets[key];
        chart.data.datasets[0].label = labelMap[key];
        if (key === 'avg_speed') {
            chart.config.type = 'line';
            chart.data.datasets[0].borderColor = palette.terracotta500;
            chart.data.datasets[0].backgroundColor = palette.terracotta500 + '40';
            chart.data.datasets[0].fill = true;
            chart.data.datasets[0].tension = 0.35;
            chart.data.datasets[0].pointRadius = 3;
            chart.data.datasets[0].pointBackgroundColor = palette.terracotta500;
        } else {
            chart.config.type = 'bar';
            chart.data.datasets[0].borderColor = palette.forest700;
            chart.data.datasets[0].backgroundColor = palette.forest500 + 'CC';
            chart.data.datasets[0].fill = false;
            chart.data.datasets[0].tension = 0;
            chart.data.datasets[0].pointRadius = 0;
        }
        chart.update();
    });

    // Doughnut graf — obtížnost
    const diffLabels = <?= js_safe_json($diffLabels) ?>;
    const diffValues = <?= js_safe_json($diffValues) ?>;
    const diffColors = <?= js_safe_json(array_slice($diffColors, 0, count($diffLabels))) ?>;
    if (diffLabels.length > 0) {
        const ctx2 = document.getElementById('diffChart').getContext('2d');
        new Chart(ctx2, {
            type: 'doughnut',
            data: { labels: diffLabels, datasets: [{
                data: diffValues, backgroundColor: diffColors, borderWidth: 3,
                borderColor: document.documentElement.classList.contains('dark') ? '#243028' : '#ffffff'
            }]},
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '60%',
                plugins: {
                    legend: { position: 'bottom', labels: { color: palette.textMuted, font: { family: 'Inter', size: 11 }, padding: 10, usePointStyle: true, pointStyle: 'circle' } }
                }
            }
        });
    }
});
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
