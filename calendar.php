<?php
/**
 * Kalendářová heatmapa aktivity — Outdoor redesign (GitHub-style mřížka).
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
check_page_access('calendar');

$allowedLangs = available_langs();

/* ===== Dostupné roky ===== */
$yearsStmt = $pdo->query("SELECT DISTINCT YEAR(date_start) AS yr FROM tracks WHERE date_start IS NOT NULL ORDER BY yr DESC");
$availableYears = array_column($yearsStmt->fetchAll(PDO::FETCH_ASSOC), 'yr');
$selectedYear = (int)($_GET['year'] ?? ($availableYears[0] ?? date('Y')));
if (!in_array($selectedYear, $availableYears)) $selectedYear = $availableYears[0] ?? date('Y');

/* ===== Data po dnech ===== */
$stmt = $pdo->prepare("
    SELECT DATE(date_start) AS day, COUNT(*) AS tracks,
           SUM(distance_km) AS km, SUM(ascent) AS ascent, SUM(duration) AS duration
    FROM tracks WHERE YEAR(date_start) = :yr
    GROUP BY DATE(date_start) ORDER BY day ASC
");
$stmt->execute([':yr' => $selectedYear]);
$dailyData = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dailyData[$row['day']] = [
        'tracks'   => (int)$row['tracks'],
        'km'       => round((float)$row['km'], 1),
        'ascent'   => round((float)$row['ascent'], 0),
        'duration' => (int)$row['duration'],
    ];
}

$totalTracks   = array_sum(array_column($dailyData, 'tracks'));
$totalKm       = array_sum(array_column($dailyData, 'km'));
$totalAscent   = array_sum(array_column($dailyData, 'ascent'));
$totalDuration = array_sum(array_column($dailyData, 'duration'));
$activeDays    = count($dailyData);

$metric = $_GET['metric'] ?? 'tracks';
if (!in_array($metric, ['tracks', 'km', 'ascent'])) $metric = 'tracks';

/* ===== Sestavení mřížky ===== */
$startDate = new DateTime("$selectedYear-01-01");
$endDate   = new DateTime("$selectedYear-12-31");
$startDow  = (int)$startDate->format('N');

$cells = [];
$current = clone $startDate;
$weekNum = 0;

for ($d = 1; $d < $startDow; $d++) {
    $cells[] = ['date' => null, 'dow' => $d - 1, 'week' => 0, 'value' => 0, 'data' => null];
}
while ($current <= $endDate) {
    $dateStr = $current->format('Y-m-d');
    $dow = (int)$current->format('N') - 1;
    if ($dow === 0 && !empty($cells) && $cells[count($cells) - 1]['date'] !== null) {
        $weekNum++;
    }
    $dayData = $dailyData[$dateStr] ?? null;
    $value = $dayData ? $dayData[$metric] : 0;
    $cells[] = ['date'=>$dateStr, 'dow'=>$dow, 'week'=>$weekNum, 'value'=>$value, 'data'=>$dayData];
    $current->modify('+1 day');
}
$totalWeeks = $weekNum + 1;

$maxValue = 1;
foreach ($cells as $c) { if ($c['value'] > $maxValue) $maxValue = $c['value']; }

$metricLabels = ['tracks' => 'Počet tras', 'km' => 'Vzdálenost (km)', 'ascent' => 'Stoupání (m)'];

$_isAdmin = !empty($_SESSION['is_admin']);
$page_title = t('h1_calendar') . ' ' . $selectedYear;
require __DIR__ . '/includes/layout_header.php';
?>

<style>
    .cal-grid {
        display: grid;
        grid-template-columns: 32px repeat(<?= $totalWeeks ?>, 14px);
        grid-template-rows: 22px repeat(7, 14px);
        gap: 3px;
        width: fit-content;
    }
    .cal-month-label {
        font-size: 10px;
        font-family: "Inter", sans-serif;
        color: rgb(45 74 62 / 0.55);
        text-align: left;
        line-height: 22px;
        white-space: nowrap;
    }
    .dark .cal-month-label { color: rgb(245 241 234 / 0.55); }
    .cal-day-label {
        font-size: 10px;
        font-family: "Inter", sans-serif;
        color: rgb(45 74 62 / 0.55);
        text-align: right;
        padding-right: 6px;
        line-height: 14px;
    }
    .dark .cal-day-label { color: rgb(245 241 234 / 0.55); }

    /* Light heatmap (forest greens) */
    .cal-cell { width: 14px; height: 14px; border-radius: 3px; transition: transform .12s; }
    .cal-cell.level-0 { background: #f0ebe0; }
    .cal-cell.level-1 { background: #d6e6dc; }
    .cal-cell.level-2 { background: #84ad94; }
    .cal-cell.level-3 { background: #3f6b57; }
    .cal-cell.level-4 { background: #2d4a3e; }
    .cal-cell[data-value] { cursor: pointer; }
    .cal-cell[data-value]:hover { transform: scale(1.4); z-index: 2; outline: 1.5px solid #c97b3f; }

    /* Dark heatmap */
    .dark .cal-cell.level-0 { background: #2f3d34; }
    .dark .cal-cell.level-1 { background: #3f6b57; }
    .dark .cal-cell.level-2 { background: #5b8a75; }
    .dark .cal-cell.level-3 { background: #84ad94; }
    .dark .cal-cell.level-4 { background: #d6e6dc; }

    .cal-tooltip {
        display: none;
        position: fixed;
        z-index: 999;
        background: white;
        border: 1px solid #e8e0d2;
        border-radius: 8px;
        padding: 10px 12px;
        font-family: "Inter", sans-serif;
        font-size: 12px;
        line-height: 1.6;
        box-shadow: 0 8px 24px rgba(45,74,62,0.15);
        pointer-events: none;
        max-width: 240px;
        color: #2d4a3e;
    }
    .dark .cal-tooltip { background: #243028; border-color: #2d4a3e; color: #f5f1ea; }
    .cal-tooltip strong { color: #c97b3f; font-family: "Manrope", sans-serif; }
    .cal-legend-cell { width: 12px; height: 12px; border-radius: 2px; }

    @media (max-width: 700px) {
        .cal-grid { grid-template-columns: 24px repeat(<?= $totalWeeks ?>, 11px); grid-template-rows: 18px repeat(7, 11px); }
        .cal-cell { width: 11px; height: 11px; }
    }
</style>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars(t('back_to_list')) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="calendar-heart" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h1_calendar')) ?>
        <span class="stat-num text-forest-600"><?= $selectedYear ?></span>
    </h1>
    <p class="mt-1 text-forest-700/70 dark:text-sand-100/70 text-sm">
        <?= htmlspecialchars(t('cal_subtitle')) ?>
    </p>
</section>

<!-- Ovládací prvky -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <div class="flex flex-wrap items-end gap-3">
        <label class="block">
            <span class="block text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60 mb-1">
                <?= htmlspecialchars(t('label_year')) ?>
            </span>
            <select id="yearSelect" class="px-3 py-2 rounded-md bg-white dark:bg-forest-800 border border-sand-200 dark:border-forest-700 text-sm focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500/30">
                <?php foreach ($availableYears as $y): ?>
                    <option value="<?= $y ?>" <?= $y == $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="block text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60 mb-1">
                <?= htmlspecialchars(t('label_metric')) ?>
            </span>
            <select id="metricSelect" class="px-3 py-2 rounded-md bg-white dark:bg-forest-800 border border-sand-200 dark:border-forest-700 text-sm focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500/30">
                <option value="tracks" <?= $metric === 'tracks' ? 'selected' : '' ?>><?= htmlspecialchars(t('metric_count')) ?></option>
                <option value="km" <?= $metric === 'km' ? 'selected' : '' ?>><?= htmlspecialchars(t('metric_distance')) ?></option>
                <option value="ascent" <?= $metric === 'ascent' ? 'selected' : '' ?>><?= htmlspecialchars(t('metric_ascent')) ?></option>
            </select>
        </label>
    </div>
</section>

<!-- Roční souhrn -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        <?php
        $summary = [
            ['route',         (int)$totalTracks,                  t('cal_total_tracks'),       'forest-700'],
            ['calendar-check',(int)$activeDays,                   t('cal_active_days'), 'terracotta-500'],
            ['ruler',         fmtDist($totalKm, 0),               t('cal_total_km'),             'forest-600'],
            ['mountain',      fmtElev($totalAscent),              t('cal_total_ascent'),   'forest-700'],
            ['clock',         formatSecondsToHMS($totalDuration), t('cal_total_hours'), 'forest-700'],
        ];
        foreach ($summary as [$icon, $val, $lbl, $color]): ?>
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

<!-- Heatmapa -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 mb-12">
    <div class="card-outdoor p-5 overflow-x-auto">
        <div class="cal-grid">
            <?php
            echo '<div></div>';

            $monthStarts = [];
            foreach ($cells as $c) {
                if ($c['date'] === null) continue;
                $m = (int)substr($c['date'], 5, 2);
                if (!isset($monthStarts[$m])) $monthStarts[$m] = $c['week'];
            }
            $monthNames = t_arr('months_short');
            if (empty($monthNames)) $monthNames = ['','Led','Úno','Bře','Dub','Kvě','Čvn','Čvc','Srp','Zář','Říj','Lis','Pro'];

            for ($w = 0; $w < $totalWeeks; $w++) {
                $label = '';
                foreach ($monthStarts as $m => $startW) {
                    if ($startW === $w) { $label = $monthNames[$m]; break; }
                }
                echo '<div class="cal-month-label">' . htmlspecialchars($label) . '</div>';
            }

            $dayLabels = t_arr('days_short');
            if (empty($dayLabels)) $dayLabels = ['Po','Út','St','Čt','Pá','So','Ne'];

            for ($dow = 0; $dow < 7; $dow++) {
                echo '<div class="cal-day-label">' . htmlspecialchars($dayLabels[$dow]) . '</div>';
                for ($w = 0; $w < $totalWeeks; $w++) {
                    $found = null;
                    foreach ($cells as $c) {
                        if ($c['dow'] === $dow && $c['week'] === $w) { $found = $c; break; }
                    }
                    if ($found === null || $found['date'] === null) {
                        echo '<div class="cal-cell level-0"></div>';
                    } else {
                        $val = $found['value'];
                        if ($val === 0) $level = 0;
                        elseif ($val <= $maxValue * 0.25) $level = 1;
                        elseif ($val <= $maxValue * 0.50) $level = 2;
                        elseif ($val <= $maxValue * 0.75) $level = 3;
                        else $level = 4;

                        $d = $found['data'];
                        $dataAttr = $d
                            ? ' data-value="1"'
                              . ' data-date="' . h($found['date']) . '"'
                              . ' data-tracks="' . $d['tracks'] . '"'
                              . ' data-km="' . $d['km'] . '"'
                              . ' data-ascent="' . $d['ascent'] . '"'
                              . ' data-duration="' . $d['duration'] . '"'
                            : ' data-date="' . h($found['date']) . '"';
                        echo '<div class="cal-cell level-' . $level . '"' . $dataAttr . '></div>';
                    }
                }
            }
            ?>
        </div>

        <div class="mt-4 flex items-center gap-1.5 text-xs text-forest-700/60 dark:text-sand-100/60">
            <span><?= htmlspecialchars(t('cal_less')) ?></span>
            <div class="cal-legend-cell cal-cell level-0"></div>
            <div class="cal-legend-cell cal-cell level-1"></div>
            <div class="cal-legend-cell cal-cell level-2"></div>
            <div class="cal-legend-cell cal-cell level-3"></div>
            <div class="cal-legend-cell cal-cell level-4"></div>
            <span><?= htmlspecialchars(t('cal_more')) ?></span>
            <span class="ml-3 inline-flex items-center gap-1">
                <i data-lucide="info" class="w-3 h-3" aria-hidden="true"></i>
                <?= htmlspecialchars($metricLabels[$metric]) ?>
            </span>
        </div>
    </div>
</section>

<div id="calTooltip" class="cal-tooltip"></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const tooltip = document.getElementById('calTooltip');
    const grid = document.querySelector('.cal-grid');
    if (!grid) return;

    const i18n = {
        days: <?= js_safe_json(t_arr('days_full')) ?>,
        noActivity: <?= js_safe_json(t('tooltip_no_activity')) ?>,
        singular: <?= js_safe_json(t('track_singular')) ?>,
        few: <?= js_safe_json(t('track_few')) ?>,
        plural: <?= js_safe_json(t('track_plural')) ?>
    };
    function formatDate(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        const days = Array.isArray(i18n.days) ? i18n.days : ['Ne','Po','Út','St','Čt','Pá','So'];
        return days[d.getDay()] + ' ' + d.getDate() + '. ' + (d.getMonth() + 1) + '. ' + d.getFullYear();
    }
    function formatDuration(sec) {
        if (!sec) return '0 min';
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        return h > 0 ? h + 'h ' + m + 'min' : m + 'min';
    }

    grid.addEventListener('mouseover', (e) => {
        const cell = e.target.closest('.cal-cell');
        if (!cell || !cell.dataset.date) return;
        let html = '<strong>' + formatDate(cell.dataset.date) + '</strong>';
        if (cell.dataset.value) {
            const n = parseInt(cell.dataset.tracks);
            const word = n === 1 ? i18n.singular : (n <= 4 ? i18n.few : i18n.plural);
            html += '<br>' + n + ' ' + word;
            html += '<br>' + cell.dataset.km + ' km';
            html += '<br>↑ ' + cell.dataset.ascent + ' m';
            html += '<br>⏱ ' + formatDuration(parseInt(cell.dataset.duration));
        } else {
            html += '<br><em>' + i18n.noActivity + '</em>';
        }
        tooltip.innerHTML = html;
        tooltip.style.display = 'block';
    });
    grid.addEventListener('mousemove', (e) => {
        if (tooltip.style.display !== 'block') return;
        const x = e.clientX + 14, y = e.clientY - 10;
        const maxX = window.innerWidth - tooltip.offsetWidth - 8;
        tooltip.style.left = Math.min(x, maxX) + 'px';
        tooltip.style.top = y + 'px';
    });
    grid.addEventListener('mouseout', (e) => {
        if (e.target.closest('.cal-cell')) tooltip.style.display = 'none';
    });
    grid.addEventListener('click', (e) => {
        const cell = e.target.closest('.cal-cell');
        if (cell && cell.dataset.value) {
            const d = cell.dataset.date;
            window.location.href = 'index.php?date_from=' + d + '&date_to=' + d + '&filter_submit=1';
        }
    });

    document.getElementById('yearSelect').addEventListener('change', function() {
        const params = new URLSearchParams(window.location.search);
        params.set('year', this.value);
        window.location.search = params.toString();
    });
    document.getElementById('metricSelect').addEventListener('change', function() {
        const params = new URLSearchParams(window.location.search);
        params.set('metric', this.value);
        window.location.search = params.toString();
    });
});
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
