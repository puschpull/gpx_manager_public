<?php
require_once __DIR__ . '/../includes/helpers.php';
?>

<?php
$page_title = t('h1_compare');
require __DIR__ . '/layout_header.php';
?>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/compare.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css" integrity="sha384-weDCJ80JNrg6W2Dha8CBrQyz5PZVPOZ39Lw7vWOzm65zqKvZZfSq/3rR77RY5TWm" crossorigin="anonymous">

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars(t('back_to_list')) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="git-compare" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h1_compare')) ?>
    </h1>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12">


<?php if ($trackCount < 2): ?>
    <div class="compare-empty">
        <p><?= t('compare_no_tracks') ?></p>
        <p><?= t('compare_hint_1') ?> <a href="index.php"><?= t('compare_hint_link') ?></a> <?= t('compare_hint_2') ?></p>
    </div>
<?php else: ?>

<!-- Mapa — A11Y-021: skip link + role="img" + aria-label -->
<a href="#compare-legend" class="sr-only-focusable">
    <?= htmlspecialchars(t('skip_to_data', 'Přejít na data trasy')) ?>
</a>
<div id="map"
     role="img"
     aria-label="<?= htmlspecialchars(t('map_aria_generic', 'Interaktivní mapa')) ?>"></div>

<!-- Legenda -->
<div id="compare-legend" class="compare-legend">
    <?php foreach ($tracks as $i => $t): ?>
        <span class="compare-legend-item" data-index="<?= $i ?>">
            <span class="compare-color-swatch" id="swatch-<?= $i ?>"></span>
            <a href="detail.php?id=<?= $t['id'] ?>"><?= h($t['track_name'] ?: $t['filename']) ?></a>
        </span>
    <?php endforeach; ?>
</div>

<!-- Porovnávací tabulka -->
<div class="compare-results">
    <h2><?= t('h2_compare_table') ?></h2>
    <div class="compare-table-wrap">
        <table class="compare-table">
            <thead>
                <tr>
                    <th><?= t('th_parameter') ?></th>
                    <?php foreach ($tracks as $i => $t): ?>
                        <th>
                            <span class="compare-color-swatch" id="th-swatch-<?= $i ?>"></span>
                            <a href="detail.php?id=<?= $t['id'] ?>"><?= h($t['track_name'] ?: $t['filename']) ?></a>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="param-label"><?= t('param_distance') ?></td>
                    <?php
                    $distances = array_column($tracks, 'distance_km');
                    $maxDist = max(array_map('floatval', $distances));
                    foreach ($tracks as $t):
                        $val = round((float)$t['distance_km'], 2);
                        $isMax = ($val == $maxDist && $val > 0);
                    ?>
                        <td class="<?= $isMax ? 'val-max' : '' ?>"><?= $val ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="param-label"><?= t('param_ascent') ?></td>
                    <?php
                    $ascents = array_column($tracks, 'ascent');
                    $maxAsc = max(array_map('floatval', $ascents));
                    foreach ($tracks as $t):
                        $val = round((float)$t['ascent'], 0);
                        $isMax = ($val == $maxAsc && $val > 0);
                    ?>
                        <td class="<?= $isMax ? 'val-max' : '' ?>"><?= $val ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="param-label"><?= t('param_descent') ?></td>
                    <?php
                    $descents = array_column($tracks, 'descent');
                    $maxDesc = max(array_map('floatval', $descents));
                    foreach ($tracks as $t):
                        $val = round((float)$t['descent'], 0);
                        $isMax = ($val == $maxDesc && $val > 0);
                    ?>
                        <td class="<?= $isMax ? 'val-max' : '' ?>"><?= $val ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="param-label"><?= t('param_duration') ?></td>
                    <?php
                    $durations = array_column($tracks, 'duration');
                    $maxDur = max(array_map('intval', $durations));
                    foreach ($tracks as $t):
                        $val = (int)$t['duration'];
                        $isMax = ($val == $maxDur && $val > 0);
                    ?>
                        <td class="<?= $isMax ? 'val-max' : '' ?>"><?= formatSecondsToHMS($val) ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="param-label"><?= t('param_moving_time') ?></td>
                    <?php foreach ($tracks as $t): ?>
                        <td><?= formatSecondsToHMS((int)$t['moving_time']) ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="param-label"><?= t('param_elev_min') ?></td>
                    <?php
                    $elevMins = array_filter(array_column($tracks, 'elevation_min'), fn($v) => $v !== null);
                    $minElev = !empty($elevMins) ? min(array_map('floatval', $elevMins)) : 0;
                    foreach ($tracks as $t):
                        $val = round((float)$t['elevation_min'], 0);
                        $isMin = ($val == $minElev && $t['elevation_min'] !== null);
                    ?>
                        <td class="<?= $isMin ? 'val-min' : '' ?>"><?= $t['elevation_min'] !== null ? $val : '–' ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="param-label"><?= t('param_elev_max') ?></td>
                    <?php
                    $elevMaxs = array_column($tracks, 'elevation_max');
                    $maxElev = max(array_map('floatval', $elevMaxs));
                    foreach ($tracks as $t):
                        $val = round((float)$t['elevation_max'], 0);
                        $isMax = ($val == $maxElev && $val > 0);
                    ?>
                        <td class="<?= $isMax ? 'val-max' : '' ?>"><?= $val ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="param-label"><?= t('param_speed_max') ?></td>
                    <?php
                    $speeds = array_column($tracks, 'speed_max');
                    $maxSpeed = max(array_map('floatval', $speeds));
                    foreach ($tracks as $t):
                        $val = round((float)$t['speed_max'], 1);
                        $isMax = ($val == $maxSpeed && $val > 0);
                    ?>
                        <td class="<?= $isMax ? 'val-max' : '' ?>"><?= $val ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="param-label"><?= t('param_speed_avg') ?></td>
                    <?php foreach ($tracks as $t): ?>
                        <td><?= round((float)$t['speed_avg'], 1) ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="param-label"><?= t('param_difficulty') ?></td>
                    <?php foreach ($tracks as $t): ?>
                        <td><?= difficultyBadge($t['difficulty'] ?? null) ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="param-label"><?= t('param_activity') ?></td>
                    <?php foreach ($tracks as $t): ?>
                        <td><?= activityBadge($t['activity_type'] ?? null) ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="param-label"><?= t('param_date') ?></td>
                    <?php foreach ($tracks as $t): ?>
                        <td><?= $t['date_start'] ? date('d.m.Y', strtotime($t['date_start'])) : '–' ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td class="param-label"><?= t('param_device') ?></td>
                    <?php foreach ($tracks as $t): ?>
                        <td><?= h($t['device'] ?: '–') ?></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>
</div>

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

<!-- Data z PHP pro JS -->
<script>
window.gpxCompareData = {
    tracks: <?= js_safe_json(array_map(function($t, $i) {
        return [
            'id'       => (int)$t['id'],
            'filename' => $t['filename'],
            'name'     => $t['track_name'] ?: $t['filename'],
        ];
    }, $tracks, array_keys($tracks))) ?>,
    apiKeys: {
        tf:        <?= js_safe_json(TF_API_KEY) ?>,
        mapycom:   <?= js_safe_json(MAPYCOM_API_KEY) ?>,
        mapillary: <?= js_safe_json(MAPILLARY_TOKEN) ?>
    }
};
</script>

<!-- Sdílené lib moduly — musí být načteny jako první -->
<script src="js/lib/event-bus.js"></script>
<script src="js/lib/geo-utils.js"></script>
<script src="js/lib/format-utils.js"></script>
<script src="js/lib/map-factory.js"></script>

<!-- JS moduly -->
<script src="js/compare-map.js"></script>

<?php endif; ?>

</div><?php require __DIR__ . '/layout_footer.php'; ?>