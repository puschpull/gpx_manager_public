
<?php
$page_title = t('h1_my_tracks');
$show_admin_banner = false;
require __DIR__ . '/layout_header.php';
?>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/index.css">

<!-- Okamžité načtení cookie visible_cols -->
<script>
(function() {
    const match = document.cookie.match(/visible_cols=([^;]+)/);
    if (!match) return;
    const cols = decodeURIComponent(match[1]).split(',').filter(Boolean);
    if (!cols.length) return;
    cols.forEach(col => { document.body?.classList.add('hide-col-' + col); });
})();
</script>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
            <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
                <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
                Zpět na novou homepage
            </a>
            <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
                <i data-lucide="table-2" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
                <?= htmlspecialchars(t('h1_my_tracks')) ?>
            </h1>
            <p class="mt-1 text-forest-700/70 dark:text-sand-100/70 text-sm">Detailní tabulkový pohled — všechny sloupce, filtry, hromadné akce</p>
        </div>
    </div>
</section>


<?php
$_visiblePages = get_app_config('visible_pages', ['stats','calendar','heatmap','map_search','nearby','filter','compare','settings']);
$_isAdmin = !empty($_SESSION['is_admin']);
?>
<!-- Topbar -->
<div class="topbar">
    <button id="sidebarToggle"
            class="sidebar-toggle active"
            aria-expanded="true"
            aria-controls="indexSidebar"
            aria-label="<?= htmlspecialchars(t('toggle_filters', 'Přepnout panel filtrů')) ?>">
        <?= t('btn_filter') ?>
    </button>
    <?php if ($_isAdmin): ?><a class="btn" href="import.php"><?= t('btn_import') ?></a><?php endif; ?>
    <?php if ($_isAdmin || in_array('photos', $_visiblePages)): ?><a class="btn" href="photos.php">📸 Fotografie</a><?php endif; ?>
    <?php if ($_isAdmin || in_array('stats', $_visiblePages)): ?><a class="btn" href="stats.php"><?= t('btn_stats') ?></a><?php endif; ?>
    <?php if ($_isAdmin || in_array('calendar', $_visiblePages)): ?><a class="btn" href="calendar.php"><?= t('btn_calendar') ?></a><?php endif; ?>
    <?php if ($_isAdmin || in_array('heatmap', $_visiblePages)): ?><a class="btn" href="heatmap.php"><?= t('btn_heatmap') ?></a><?php endif; ?>
    <?php if ($_isAdmin || in_array('map_search', $_visiblePages)): ?><a class="btn" href="map_search.php"><?= t('btn_map_search') ?></a><?php endif; ?>
    <?php if ($_isAdmin || in_array('nearby', $_visiblePages)): ?><a class="btn" href="nearby.php"><?= t('btn_nearby') ?></a><?php endif; ?>
    <?php if ($_isAdmin || in_array('compare', $_visiblePages)): ?>
    <button class="btn" id="compare-btn" onclick="openCompare()" disabled title="<?= t('title_compare_hint') ?>"><?= t('btn_compare') ?> (<span id="compare-count">0</span>)</button>
    <?php endif; ?>
    <span id="bulk-actions" class="bulk-actions" style="display:none;">
        <select id="bulk-action-select" class="select">
            <option value=""><?= t('bulk_placeholder') ?></option>
            <option value="set_favorite"><?= t('bulk_set_fav') ?></option>
            <option value="unset_favorite"><?= t('bulk_unset_fav') ?></option>
            <option value="set_category"><?= t('bulk_set_category') ?></option>
            <option value="remove_category"><?= t('bulk_remove_category') ?></option>
            <option value="set_color"><?= t('bulk_set_color') ?></option>
            <option value="delete"><?= t('bulk_delete') ?></option>
        </select>
        <button class="btn btn-bulk" id="bulk-run-btn" onclick="runBulkAction()"><?= t('btn_bulk_run') ?></button>
    </span>
    <?php if ($_isAdmin || in_array('filter', $_visiblePages)): ?><a class="btn" href="filter.php"><?= t('btn_gpx_cleaner') ?></a><?php endif; ?>
    <a class="btn" href="index-legacy.php"><?= t('btn_reset_filter') ?></a>
    <?php if ($_isAdmin): ?>
    <a href="export.php?<?= build_query() ?>" class="btn"><?= t('btn_export_csv') ?></a>
    <a href="zip_export.php?<?= build_query() ?>" class="btn"><?= t('btn_export_zip') ?></a>
    <?php endif; ?>
    <?php if ($_isAdmin || in_array('settings', $_visiblePages)): ?><a class="btn" href="settings.php"><?= t('settings') ?></a><?php endif; ?>
    <?php if ($_isAdmin): ?><a class="btn" href="admin.php"><?= t('admin') ?></a><?php endif; ?>
</div>

<!-- Hlavní layout -->
<div class="index-layout" id="indexLayout">

    <!-- SIDEBAR — filtry -->
    <div class="index-sidebar" id="indexSidebar"
         role="region"
         aria-label="<?= htmlspecialchars(t('filters_panel', 'Panel filtrů')) ?>">

        <form id="filter-form" class="filters" action="index-legacy.php" method="get" data-endpoint="../includes/index_data.php">

            <!-- Viditelnost sloupců — mimo sidebar-grid -->
            <?php
            $colList = [
                t('th_id')               => "id",
                t('th_filename')         => "filename",
                t('th_track_name')       => "track_name",
                t('th_alt_title')        => "alt_title",
                t('th_note')             => "note",
                t('th_color')            => "color",
                t('th_device')           => "device",
                t('th_date_start')       => "date_start",
                t('th_date_end')         => "date_end",
                t('th_duration')         => "duration",
                t('th_moving_time')      => "moving_time",
                t('th_stopped_time')     => "stopped_time",
                t('th_distance')         => "distance_km",
                t('th_ascent')           => "ascent",
                t('th_descent')          => "descent",
                t('th_elev_min')         => "elevation_min",
                t('th_elev_max')         => "elevation_max",
                t('th_speed_max')        => "speed_max",
                t('th_speed_avg')        => "speed_avg",
                t('th_speed_avg_total')  => "speed_avg_total",
                "avg↑"                   => "avg_ascent_rate",
                "avg↓"                   => "avg_descent_rate",
                "max↑"                   => "max_ascent_rate",
                "max↓"                   => "max_descent_rate",
                t('th_bounds')           => "bounds",
                t('th_trackpoints')      => "trackpoints_count",
                t('th_thumb')            => "thumb",
                t('th_difficulty')       => "difficulty",
                t('th_activity')         => "activity",
                t('th_categories')       => "categories",
                "⭐"                     => "favorite",
                t('th_gpx')              => "gpx",
                t('th_map')              => "map",
                "📸"                     => "photos",
                t('th_action')           => "action"
            ];
            ?>

            <div class="column-toggle-panel">
                <details>
                    <summary><?= t('col_panel_title') ?></summary>
                    <div class="col-buttons">
                        <button type="button" id="col-show-all" class="btn small"><?= t('btn_show_all_cols') ?></button>
                        <button type="button" id="col-hide-all" class="btn small"><?= t('btn_hide_all_cols') ?></button>
                    </div>
                    <div class="col-grid">
                        <?php foreach ($colList as $label => $key): ?>
                            <label class="col-toggle-item">
                                <input type="checkbox" class="col-toggle" data-col="<?= $key ?>" checked>
                                <?= h($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>

            <!-- Filtry -->
            <div class="sidebar-grid">

                <!-- Tlačítko filtrovat — nahoře -->
                <div>
                    <button class="btn" type="submit" name="filter_submit" value="1"><?= t('btn_filter') ?></button>
                </div>

                <!-- Oblíbené -->
                <div>
                    <label class="fav-filter-label">
                        <input type="checkbox" name="fav_only" value="1" <?= (!empty($_GET['fav_only']) ? 'checked' : '') ?>>
                        <?= t('filter_fav_only') ?>
                    </label>
                </div>

                <!-- Obtížnost -->
                <div class="sidebar-section">
                    <div class="sidebar-section-title"><?= t('filter_difficulty') ?></div>
                    <div class="sidebar-range">
                        <div>
                            <label><?= t('filter_min') ?></label>
                            <select name="diff_min">
                                <option value="">—</option>
                                <?php for ($d = 1; $d <= 5; $d++): ?>
                                    <option value="<?= $d ?>" <?= (($diff_min ?? '') == $d ? 'selected' : '') ?>><?= $d ?> – <?= difficultyLabel($d) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label><?= t('filter_max') ?></label>
                            <select name="diff_max">
                                <option value="">—</option>
                                <?php for ($d = 1; $d <= 5; $d++): ?>
                                    <option value="<?= $d ?>" <?= (($diff_max ?? '') == $d ? 'selected' : '') ?>><?= $d ?> – <?= difficultyLabel($d) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Aktivita -->
                <div>
                    <label><?= t('filter_activity') ?></label>
                    <select name="activity">
                        <option value=""><?= t('select_any') ?></option>
                        <?php
                        $activityTypes = ['Pěšky', 'Turistika', 'Běh', 'Kolo', 'E-bike', 'Auto'];
                        $activityIcons = ['Pěšky'=>'🚶','Turistika'=>'🥾','Běh'=>'🏃','Kolo'=>'🚴','E-bike'=>'⚡🚴','Auto'=>'🚗'];
                        $activityCounts = [];
                        try {
                            $acStmt = $pdo->query("SELECT activity_type, COUNT(*) AS cnt FROM tracks WHERE activity_type IS NOT NULL AND activity_type != '' GROUP BY activity_type ORDER BY activity_type");
                            while ($acRow = $acStmt->fetch(PDO::FETCH_ASSOC)) {
                                $activityCounts[$acRow['activity_type']] = (int)$acRow['cnt'];
                            }
                        } catch (Throwable $e) { error_log("index_view.php activity counts: " . $e->getMessage()); }
                        foreach ($activityTypes as $at):
                            $atCount = $activityCounts[$at] ?? 0;
                        ?>
                            <option value="<?= h($at) ?>" <?= (($_GET['activity'] ?? '') === $at ? 'selected' : '') ?>><?= $activityIcons[$at] ?? '' ?> <?= h($at) ?> (<?= $atCount ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Hledání -->
                <div>
                    <label><?= t('filter_search') ?></label>
                    <input type="text" name="q" value="<?= h($q) ?>" placeholder="<?= t('placeholder_search') ?>">
                </div>

                <!-- Barva -->
                <div>
                    <label><?= t('filter_color') ?></label>
                    <select name="color">
                        <option value=""><?= t('select_any') ?></option>
                        <?php foreach ($colors as $c): ?>
                            <option value="<?= h($c) ?>" <?= ($color === $c ? 'selected' : '') ?>><?= h($c) ?> (<?= $colorCounts[$c] ?? 0 ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Kategorie -->
                <div>
                    <label><?= t('filter_category') ?></label>
                    <select name="cat[]" multiple size="16">
                        <option value=""><?= t('select_any') ?></option>
                        <?php foreach ($allCats as $cn): ?>
                            <option value="<?= h($cn) ?>" <?= (in_array($cn, (array)$cat, true) ? 'selected' : '') ?>><?= h($cn) ?> (<?= $catCounts[$cn] ?? 0 ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="cat-mode">
                        <label class="cat-mode-label">
                            <input type="radio" name="cat_mode" value="or" <?= (($_GET['cat_mode'] ?? 'or') === 'or' ? 'checked' : '') ?>>
                            <?= t('cat_mode_or') ?> <small><?= t('cat_mode_or_hint') ?></small>
                        </label>
                        <label class="cat-mode-label">
                            <input type="radio" name="cat_mode" value="and" <?= (($_GET['cat_mode'] ?? 'or') === 'and' ? 'checked' : '') ?>>
                            <?= t('cat_mode_and') ?> <small><?= t('cat_mode_and_hint') ?></small>
                        </label>
                    </div>
                </div>

                <!-- Datum -->
                <div class="sidebar-section">
                    <div class="sidebar-section-title">
                        <?= t('filter_date') ?>
                        <label class="inv-label"><input type="checkbox" name="date_inv" value="1" <?= !empty($_GET['date_inv']) ? 'checked' : '' ?>> <?= t('filter_inv') ?></label>
                    </div>
                    <div class="sidebar-range">
                        <div>
                            <label><?= t('filter_from') ?></label>
                            <input type="datetime-local" name="date_from" value="<?= h($date_from) ?>">
                        </div>
                        <div>
                            <label><?= t('filter_to') ?></label>
                            <input type="datetime-local" name="date_to" value="<?= h($date_to) ?>">
                        </div>
                    </div>
                </div>

                <!-- Vzdálenost -->
                <div class="sidebar-section">
                    <div class="sidebar-section-title">
                        <?= t('filter_distance') ?>
                        <label class="inv-label"><input type="checkbox" name="dist_inv" value="1" <?= !empty($_GET['dist_inv']) ? 'checked' : '' ?>> <?= t('filter_inv') ?></label>
                    </div>
                    <div class="sidebar-range">
                        <div>
                            <label><?= t('filter_min') ?></label>
                            <input type="number" step="0.001" name="dist_min" value="<?= h($dist_min) ?>">
                        </div>
                        <div>
                            <label><?= t('filter_max') ?></label>
                            <input type="number" step="0.001" name="dist_max" value="<?= h($dist_max) ?>">
                        </div>
                    </div>
                </div>

                <!-- Stoupání -->
                <div class="sidebar-section">
                    <div class="sidebar-section-title">
                        <?= t('filter_ascent') ?>
                        <label class="inv-label"><input type="checkbox" name="ascent_inv" value="1" <?= !empty($_GET['ascent_inv']) ? 'checked' : '' ?>> <?= t('filter_inv') ?></label>
                    </div>
                    <div class="sidebar-range">
                        <div>
                            <label><?= t('filter_min') ?></label>
                            <input type="number" step="0.1" name="ascent_min" value="<?= h($ascent_min) ?>">
                        </div>
                        <div>
                            <label><?= t('filter_max') ?></label>
                            <input type="number" step="0.1" name="ascent_max" value="<?= h($ascent_max) ?>">
                        </div>
                    </div>
                </div>

                <!-- Klesání -->
                <div class="sidebar-section">
                    <div class="sidebar-section-title">
                        <?= t('filter_descent') ?>
                        <label class="inv-label"><input type="checkbox" name="descent_inv" value="1" <?= !empty($_GET['descent_inv']) ? 'checked' : '' ?>> <?= t('filter_inv') ?></label>
                    </div>
                    <div class="sidebar-range">
                        <div>
                            <label><?= t('filter_min') ?></label>
                            <input type="number" step="0.1" name="descent_min" value="<?= h($_GET['descent_min'] ?? '') ?>">
                        </div>
                        <div>
                            <label><?= t('filter_max') ?></label>
                            <input type="number" step="0.1" name="descent_max" value="<?= h($_GET['descent_max'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- Průměrná rychlost -->
                <div class="sidebar-section">
                    <div class="sidebar-section-title">
                        <?= t('filter_speed_avg') ?>
                        <label class="inv-label"><input type="checkbox" name="savg_inv" value="1" <?= !empty($_GET['savg_inv']) ? 'checked' : '' ?>> <?= t('filter_inv') ?></label>
                    </div>
                    <div class="sidebar-range">
                        <div>
                            <label><?= t('filter_min') ?></label>
                            <input type="number" step="0.01" name="savg_min" value="<?= h($savg_min) ?>">
                        </div>
                        <div>
                            <label><?= t('filter_max') ?></label>
                            <input type="number" step="0.01" name="savg_max" value="<?= h($savg_max) ?>">
                        </div>
                    </div>
                </div>

                <!-- Průměrná rychlost celková -->
                <div class="sidebar-section">
                    <div class="sidebar-section-title">
                        <?= t('filter_speed_total') ?>
                        <label class="inv-label"><input type="checkbox" name="savg_total_inv" value="1" <?= !empty($_GET['savg_total_inv']) ? 'checked' : '' ?>> <?= t('filter_inv') ?></label>
                    </div>
                    <div class="sidebar-range">
                        <div>
                            <label><?= t('filter_min') ?></label>
                            <input type="number" step="0.01" name="savg_total_min" value="<?= h($savg_total_min) ?>">
                        </div>
                        <div>
                            <label><?= t('filter_max') ?></label>
                            <input type="number" step="0.01" name="savg_total_max" value="<?= h($savg_total_max) ?>">
                        </div>
                    </div>
                </div>

                <!-- Max rychlost -->
                <div class="sidebar-section">
                    <div class="sidebar-section-title">
                        <?= t('filter_speed_max') ?>
                        <label class="inv-label"><input type="checkbox" name="smax_inv" value="1" <?= !empty($_GET['smax_inv']) ? 'checked' : '' ?>> <?= t('filter_inv') ?></label>
                    </div>
                    <div class="sidebar-range">
                        <div>
                            <label><?= t('filter_min') ?></label>
                            <input type="number" step="0.01" name="smax_min" value="<?= h($smax_min) ?>">
                        </div>
                        <div>
                            <label><?= t('filter_max') ?></label>
                            <input type="number" step="0.01" name="smax_max" value="<?= h($smax_max) ?>">
                        </div>
                    </div>
                </div>

                <!-- Min výška -->
                <div class="sidebar-section">
                    <div class="sidebar-section-title">
                        <?= t('filter_elev_min') ?>
                        <label class="inv-label"><input type="checkbox" name="elevation_min_inv" value="1" <?= !empty($_GET['elevation_min_inv']) ? 'checked' : '' ?>> <?= t('filter_inv') ?></label>
                    </div>
                    <div class="sidebar-range">
                        <div>
                            <label><?= t('filter_from') ?></label>
                            <input type="number" step="0.1" name="elevation_min_val" value="<?= h($_GET['elevation_min_val'] ?? '') ?>">
                        </div>
                        <div>
                            <label><?= t('filter_to') ?></label>
                            <input type="number" step="0.1" name="elevation_min_max" value="<?= h($_GET['elevation_min_max'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- Max výška -->
                <div class="sidebar-section">
                    <div class="sidebar-section-title">
                        <?= t('filter_elev_max') ?>
                        <label class="inv-label"><input type="checkbox" name="elevation_max_inv" value="1" <?= !empty($_GET['elevation_max_inv']) ? 'checked' : '' ?>> <?= t('filter_inv') ?></label>
                    </div>
                    <div class="sidebar-range">
                        <div>
                            <label><?= t('filter_from') ?></label>
                            <input type="number" step="0.1" name="elevation_max_val" value="<?= h($_GET['elevation_max_val'] ?? '') ?>">
                        </div>
                        <div>
                            <label><?= t('filter_to') ?></label>
                            <input type="number" step="0.1" name="elevation_max_max" value="<?= h($_GET['elevation_max_max'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- Stránkování a řazení -->
                <div class="sidebar-section">
                    <div class="sidebar-section-title"><?= t('filter_display') ?></div>
                    <div>
                        <label><?= t('filter_per_page') ?></label>
                        <select name="per_page">
                            <?php foreach ([5,10,25,50,100,200,500] as $pp): ?>
                                <option value="<?= $pp ?>" <?= ($per_page === $pp ? 'selected' : '') ?>><?= $pp ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label><?= t('filter_sort_by') ?></label>
                        <select name="sort_by">
                            <?php foreach ($sortOptions as $label => $col): ?>
                                <option value="<?= h($col) ?>" <?= ($sort_by === $col ? 'selected' : '') ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label><?= t('filter_sort_dir') ?></label>
                        <select name="sort_dir">
                            <option value="ASC"  <?= ($sort_dir === 'ASC'  ? 'selected' : '') ?>>ASC</option>
                            <option value="DESC" <?= ($sort_dir === 'DESC' ? 'selected' : '') ?>>DESC</option>
                        </select>
                    </div>
                </div>

                <!-- Tlačítko filtrovat — dole -->
                <div>
                    <button class="btn" type="submit" name="filter_submit" value="1"><?= t('btn_filter') ?></button>
                </div>

            </div><!-- /sidebar-grid -->

        </form>

    </div><!-- /index-sidebar -->

    <!-- MAIN — tabulka a výsledky -->
    <div class="index-main" id="indexMain">

        <?php if ($rangeActive): ?>
        <div class="filters-active">
            <span class="chip">
                <b><?= t('range_label') ?></b>
                <?= $date_from !== '' ? h($date_from) : '—' ?> &nbsp;→&nbsp; <?= $date_to !== '' ? h($date_to) : '—' ?>
                <a class="btn" href="index-legacy.php"><?= t('range_cancel') ?></a>
            </span>
        </div>
        <?php endif; ?>

        <div class="count">
            <?= t('summary_records') ?> <strong><?= $total_rows ?></strong>,
            <?= t('summary_page') ?> <strong><?= $page ?></strong> / <?= $total_pages ?>
            <?php include 'pager_top.php'; ?>
        </div>

        <!-- Souhrnné statistiky -->
        <?php if (!empty($filtered_stats)): ?>
            <div class="summary-panel">
                <span>📊 <strong><?= t('summary_records') ?></strong> <?= (int)$filtered_stats['total_tracks'] ?></span>
                <span>🛣️ <strong><?= t('summary_total_km') ?></strong> <?= fmtDist($filtered_stats['total_km']) ?></span>
                <span>🧗 <strong><?= t('summary_total_ascent') ?></strong> <?= fmtElev($filtered_stats['total_ascent']) ?></span>
                <span>⏱️ <strong><?= t('summary_avg_speed') ?></strong> <?= fmtSpeed($filtered_stats['avg_speed']) ?></span>
                <?php if ($filtered_stats['total_tracks'] < $stats['total_tracks']): ?>
                    <span class="text-muted"><?= t('summary_filtered') ?> <?= (int)$stats['total_tracks'] ?> <?= t('summary_tracks') ?></span>
                <?php endif; ?>
                <button id="toggleChartBtn" class="btn" style="margin-left:10px;"><?= t('btn_show_chart') ?></button>
            </div>
        <?php endif; ?>

        <!-- Přehledový graf -->
        <?php if (!empty($chart_labels)): ?>
            <div id="chartSection" style="display:none;">
                <div class="chart-container"
                     style="margin:20px 0; background:var(--card-bg,#fafafa);
                            border:1px solid var(--border-color,#ccc);
                            border-radius:8px; padding:10px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <strong><?= t('chart_title') ?></strong>
                        <select id="metricSelector" class="select" style="width:auto;">
                            <option value="distance" selected><?= t('metric_distance') ?></option>
                            <option value="count"><?= t('metric_count') ?></option>
                            <option value="ascent"><?= t('metric_ascent') ?></option>
                            <option value="avg_speed"><?= t('metric_avg_speed') ?></option>
                        </select>
                    </div>
                    <canvas id="tracksChart" style="height:220px; max-height:240px; width:100%;"></canvas>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tabulka -->
        <div class="table-scroll-top"></div>
        <div class="table-responsive">
            <?php include 'table_tracks.php'; ?>
        </div>

        <!-- Pager bottom -->
        <?php include 'pager_bottom.php'; ?>

    </div><!-- /index-main -->

</div><!-- /index-layout -->

<!-- JS knihovny -->
<!-- TODO: SRI hash via openssl dgst -sha384 -binary <url> | openssl base64 -A -->
<script defer
        src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
        integrity=""
        crossorigin="anonymous"></script>

<!-- Data pro graf -->
<script>
window.gpxChartData = {
    labels:   <?= js_safe_json($chart_labels   ?? []) ?>,
    distance:  <?= js_safe_json($chart_distance  ?? []) ?>,
    count:     <?= js_safe_json($chart_count     ?? []) ?>,
    ascent:    <?= js_safe_json($chart_ascent    ?? []) ?>,
    avg_speed: <?= js_safe_json($chart_avg_speed ?? []) ?>
};
</script>

<!-- JS moduly -->
<script src="js/index-chart.js" defer></script>
<script src="js/index-ui.js" defer></script>
<script src="js/index-ajax.js" defer></script>
<script src="js/index-columns.js" defer></script>
<script src="js/mobile-init.js" defer></script>

<!-- Oblíbené — AJAX toggle -->
<script>
document.addEventListener('click', e => {
    const btn = e.target.closest('.fav-btn');
    if (!btn) return;
    const id = btn.dataset.id;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fetch('api_toggle_favorite.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: 'id=' + encodeURIComponent(id)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.textContent = data.is_favorite ? '⭐' : '☆';
        }
    });
});
</script>

<!-- Porovnání tras + hromadné operace -->
<script>
const csrfToken = <?= js_safe_json(csrf_token()) ?>;

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.compare-cb:checked')).map(cb => cb.value);
}

function updateSelectionUI() {
    const ids     = getSelectedIds();
    const countEl = document.getElementById('compare-count');
    const compBtn = document.getElementById('compare-btn');
    const bulkEl  = document.getElementById('bulk-actions');

    if (countEl) countEl.textContent = ids.length;
    if (compBtn) compBtn.disabled = ids.length < 2;
    if (bulkEl)  bulkEl.style.display = ids.length > 0 ? 'inline-flex' : 'none';
}

function openCompare() {
    const ids = getSelectedIds();
    if (ids.length < 2) return;
    window.open('compare.php?ids=' + ids.join(','), '_blank');
}

function runBulkAction() {
    const ids    = getSelectedIds();
    const action = document.getElementById('bulk-action-select').value;
    if (!action || ids.length === 0) return;

    let value = '';

    if (action === 'set_category' || action === 'remove_category') {
        const label = action === 'set_category' ? <?= js_safe_json(t('prompt_category')) ?> : <?= js_safe_json(t('prompt_remove_cat')) ?>;
        value = prompt(label);
        if (value === null || value.trim() === '') return;
        value = value.trim();
    }

    if (action === 'set_color') {
        value = prompt(<?= js_safe_json(t('prompt_color')) ?>);
        if (value === null || value.trim() === '') return;
        value = value.trim();
    }

    if (action === 'delete') {
        if (!confirm(<?= js_safe_json(t('confirm_bulk_delete')) ?> + ' ' + ids.length + ' ' + <?= js_safe_json(t('confirm_bulk_suffix')) ?>)) return;
    }

    const body = new URLSearchParams();
    body.append('action', action);
    body.append('value', value);
    body.append('_csrf_token', csrfToken);
    ids.forEach(id => body.append('ids[]', id));

    fetch('api_bulk_action.php', {
        method: 'POST',
        body: body,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(<?= js_safe_json(t('msg_error')) ?> + (data.error || <?= js_safe_json(t('msg_unknown_error')) ?>));
        }
    })
    .catch(err => {
        alert(<?= js_safe_json(t('msg_comm_error')) ?> + err.message);
    });
}

document.addEventListener('change', e => {
    if (e.target.classList.contains('compare-cb') || e.target.id === 'compare-select-all') {
        if (e.target.id === 'compare-select-all') {
            const checked = e.target.checked;
            document.querySelectorAll('.compare-cb').forEach(cb => cb.checked = checked);
        }
        updateSelectionUI();
    }
});
</script>

<?php require __DIR__ . '/layout_footer.php'; ?>