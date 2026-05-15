<?php
$_isAdmin = !empty($_SESSION['is_admin']);
?>
<table class="track-table" aria-label="<?= htmlspecialchars(t('table_tracks_caption', 'Seznam tras')) ?>">
    <caption class="sr-only"><?= htmlspecialchars(t('table_tracks_caption', 'Seznam tras')) ?></caption>

    <thead>
    <tr>
        <th scope="col" class="col-compare">
            <input type="checkbox" id="compare-select-all"
                   aria-label="<?= htmlspecialchars(t('th_select_all')) ?>">
        </th>
        <?= sort_th(t('th_id'), 'id', $sort_by, $sort_dir, 'col-id') ?>
        <?= sort_th(t('th_filename'), 'filename', $sort_by, $sort_dir, 'col-filename') ?>
        <?= sort_th(t('th_track_name'), 'track_name', $sort_by, $sort_dir, 'col-track_name') ?>
        <?= sort_th(t('th_alt_title'), 'alt_title', $sort_by, $sort_dir, 'col-alt_title') ?>
        <?= sort_th(t('th_note'), 'note', $sort_by, $sort_dir, 'col-note') ?>
        <?= sort_th(t('th_color'), 'color', $sort_by, $sort_dir, 'col-color') ?>
        <?= sort_th(t('th_device'), 'device', $sort_by, $sort_dir, 'col-device') ?>

        <?= sort_th(t('th_date_start'), 'date_start', $sort_by, $sort_dir, 'col-date_start ' . ($rangeActive ? 'range-active nowrap' : 'nowrap')) ?>
        <?= sort_th(t('th_date_end'), 'date_end', $sort_by, $sort_dir, 'col-date_end ' . ($rangeActive ? 'range-active nowrap' : 'nowrap')) ?>

        <?= sort_th(t('th_duration'), 'duration', $sort_by, $sort_dir, 'col-duration') ?>
        <?= sort_th(t('th_moving_time'), 'moving_time', $sort_by, $sort_dir, 'col-moving_time') ?>
        <?= sort_th(t('th_stopped_time'), 'stopped_time', $sort_by, $sort_dir, 'col-stopped_time') ?>

        <?php $du = app_units() === 'imperial' ? 'mi' : 'km'; $eu = app_units() === 'imperial' ? 'ft' : 'm'; $su = app_units() === 'imperial' ? 'mph' : 'km/h'; ?>
        <?= sort_th(t('th_distance') . " ($du)", 'distance_km', $sort_by, $sort_dir, 'col-distance_km') ?>
        <?= sort_th(t('th_ascent') . " ($eu)", 'ascent', $sort_by, $sort_dir, 'col-ascent') ?>
        <?= sort_th(t('th_descent') . " ($eu)", 'descent', $sort_by, $sort_dir, 'col-descent') ?>

        <?= sort_th(t('th_elev_min') . " ($eu)", 'elevation_min', $sort_by, $sort_dir, 'col-elevation_min') ?>
        <?= sort_th(t('th_elev_max') . " ($eu)", 'elevation_max', $sort_by, $sort_dir, 'col-elevation_max') ?>

        <?= sort_th(t('th_speed_max') . " ($su)", 'speed_max', $sort_by, $sort_dir, 'col-speed_max') ?>
        <?= sort_th(t('th_speed_avg') . " ($su)", 'speed_avg', $sort_by, $sort_dir, 'col-speed_avg') ?>
        <?= sort_th(t('th_speed_avg_total') . " ($su)", 'speed_avg_total', $sort_by, $sort_dir, 'col-speed_avg_total') ?>

        <?= sort_th(t('th_avg_ascent_rate'), 'avg_ascent_rate', $sort_by, $sort_dir, 'col-avg_ascent_rate') ?>
        <?= sort_th(t('th_avg_descent_rate'), 'avg_descent_rate', $sort_by, $sort_dir, 'col-avg_descent_rate') ?>
        <?= sort_th(t('th_max_ascent_rate'), 'max_ascent_rate', $sort_by, $sort_dir, 'col-max_ascent_rate') ?>
        <?= sort_th(t('th_max_descent_rate'), 'max_descent_rate', $sort_by, $sort_dir, 'col-max_descent_rate') ?>

        <?= sort_th(t('th_bounds'), 'bounds', $sort_by, $sort_dir, 'col-bounds') ?>
        <?= sort_th(t('th_trackpoints'), 'trackpoints_count', $sort_by, $sort_dir, 'col-trackpoints_count') ?>

        <?= sort_th(t('th_difficulty'), 'difficulty', $sort_by, $sort_dir, 'col-difficulty') ?>
        <?= sort_th(t('th_activity'), 'activity_type', $sort_by, $sort_dir, 'col-activity') ?>
        <th scope="col" class="col-thumb"><?= htmlspecialchars(t('th_thumb')) ?></th>
        <th scope="col" class="col-categories"><?= htmlspecialchars(t('th_categories')) ?></th>
        <th scope="col" class="col-gpx"><?= htmlspecialchars(t('th_gpx')) ?></th>
        <th scope="col" class="col-map"><?= htmlspecialchars(t('th_map')) ?></th>
        <th scope="col" class="col-photos"
            aria-label="<?= htmlspecialchars(t('th_photos', 'Fotky')) ?>"
            title="<?= htmlspecialchars(t('th_photos', 'Fotky')) ?>">
            <span aria-hidden="true">📸</span>
        </th>
        <?= sort_th(
            '<span aria-hidden="true">⭐</span><span class="sr-only">' . htmlspecialchars(t('th_favorite', 'Oblíbené')) . '</span>',
            'is_favorite',
            $sort_by,
            $sort_dir,
            'col-favorite'
        ) ?>
        <?php if ($_isAdmin): ?>
        <th scope="col" class="col-action"><?= htmlspecialchars(t('th_action')) ?></th>
        <?php endif; ?>
    </tr>
    </thead>

    <tbody>
    <?php foreach ($tracks as $t): ?>

        <?php
        $start_ts = !empty($t['date_start']) ? strtotime($t['date_start']) : null;
        $end_ts   = !empty($t['date_end'])   ? strtotime($t['date_end'])   : null;
        $inRange  = true;
        if ($df_ts !== null && $start_ts !== null) { $inRange = $inRange && ($start_ts >= $df_ts); }
        if ($dt_ts !== null && $end_ts   !== null) { $inRange = $inRange && ($end_ts   <= $dt_ts); }

        $thumbName  = pathinfo($t['filename'], PATHINFO_FILENAME) . '.png';
        $thumbFile  = uploads_fs('thumbs/' . $thumbName);
        $thumbUrl   = thumb_url($thumbName);
        $thumbExists = is_file($thumbFile);
        ?>

        <tr class="<?= $rangeActive && $inRange ? 'in-range-row' : '' ?>">

            <td class="col-compare"><input type="checkbox" class="compare-cb" value="<?= (int)$t['id'] ?>"></td>
            <td class="col-id" data-label="ID"><?= (int)$t['id'] ?></td>
            <td class="col-filename" data-label="Soubor"><?= h($t['filename']) ?></td>
            <td class="col-track_name" data-label="Název trasy"><?= h($t['track_name']) ?></td>
            <td class="col-alt_title" data-label="Alt název"><?= h($t['alt_title']) ?></td>
            <td class="col-note" data-label="Poznámka"><?= h($t['note']) ?></td>
            <td class="col-color" data-label="Barva"><?= h($t['color']) ?></td>
            <td class="col-device" data-label="Zařízení"><?= h($t['device']) ?></td>

            <td class="col-date_start <?= $rangeActive && $inRange ? 'nowrap in-range' : 'nowrap' ?>"
                data-label="Start"
                title="<?= h($t['date_start']) ?>">
                <?= h($t['date_start']) ?>
            </td>
            <td class="col-date_end <?= $rangeActive && $inRange ? 'nowrap in-range' : 'nowrap' ?>"
                data-label="Konec"
                title="<?= h($t['date_end']) ?>">
                <?= h($t['date_end']) ?>
            </td>

            <td class="col-duration" data-label="Doba"><?= formatSecondsToHMS((int)$t['duration']) ?></td>
            <td class="col-moving_time" data-label="Pohyb"><?= formatSecondsToHMS((int)$t['moving_time']) ?></td>
            <td class="col-stopped_time" data-label="Stání"><?= formatSecondsToHMS((int)$t['stopped_time']) ?></td>

            <td class="col-distance_km" data-label="Vzdálenost"><?= fmtDist((float)$t['distance_km']) ?></td>
            <td class="col-ascent" data-label="Stoupání"><?= fmtElev((float)$t['ascent']) ?></td>
            <td class="col-descent" data-label="Klesání"><?= fmtElev((float)$t['descent']) ?></td>

            <td class="col-elevation_min" data-label="Min výška"><?= fmtElev((float)$t['elevation_min']) ?></td>
            <td class="col-elevation_max" data-label="Max výška"><?= fmtElev((float)$t['elevation_max']) ?></td>

            <td class="col-speed_max" data-label="Max rychl."><?= fmtSpeed((float)$t['speed_max']) ?></td>
            <td class="col-speed_avg" data-label="Prům. rychl."><?= fmtSpeed((float)$t['speed_avg']) ?></td>
            <td class="col-speed_avg_total" data-label="Prům. rychl. celk."><?= fmtSpeed((float)$t['speed_avg_total']) ?></td>

            <td class="col-avg_ascent_rate" data-label="avg↑ (m/s)"><?= h($t['avg_ascent_rate']) ?></td>
            <td class="col-avg_descent_rate" data-label="avg↓ (m/s)"><?= h($t['avg_descent_rate']) ?></td>
            <td class="col-max_ascent_rate" data-label="max↑ (m/s)"><?= h($t['max_ascent_rate']) ?></td>
            <td class="col-max_descent_rate" data-label="max↓ (m/s)"><?= h($t['max_descent_rate']) ?></td>

            <td class="col-bounds bounds-cell" data-label="Souřadnice" title="<?= h($t['bounds']) ?>">
                <?php
                $boundsRaw = trim($t['bounds'] ?? '');
                if ($boundsRaw === '' || strtolower($boundsRaw) === 'null') {
                    echo '<span class="bounds-unknown">' . t('bounds_unknown') . '</span>';
                } else {
                    $decoded = json_decode($boundsRaw, true);
                    if (is_array($decoded)) {
                        $parts = [];
                        foreach ($decoded as $key => $val) {
                            $parts[] = sprintf('%s: %.5f', htmlspecialchars($key), $val);
                        }
                        echo implode(' | ', $parts);
                    } else {
                        echo htmlspecialchars(trim($boundsRaw));
                    }
                }
                ?>
            </td>

            <td class="col-trackpoints_count" data-label="Počet bodů"><?= (int)$t['trackpoints_count'] ?></td>

            <td class="col-difficulty" data-label="Obtížnost">
                <?= difficultyBadge($t['difficulty'] ?? null) ?>
            </td>

            <td class="col-activity" data-label="Aktivita">
                <?= activityBadge($t['activity_type'] ?? null) ?>
            </td>

            <td class="col-thumb" data-label="Náhled">
                <?php if ($thumbExists): ?>
                    <a href="<?= h('detail.php?' . http_build_query(array_merge($_GET, ['id' => $t['id'], 'from' => 'legacy']))) ?>"
                       title="<?= t('title_show_track') ?> <?= (int)$t['id'] ?>">
                        <img src="<?= h($thumbUrl) ?>"
                             width="240" height="120"
                             alt="<?= t('thumb_alt') ?> <?= h($t['track_name'] ?: $t['filename']) ?>"
                             class="track-thumb">
                    </a>
                <?php else: ?>
                    <span class="thumb-missing">—</span>
                <?php endif; ?>
            </td>

            <td class="col-categories" data-label="Kategorie">
                <?= isset($catsByTrack[(int)$t['id']]) ? h(implode(', ', $catsByTrack[(int)$t['id']])) : '' ?>
            </td>

            <td class="col-gpx" data-label="GPX" title="<?= h(gpx_url($t['filename'])) ?>">
                <a href="<?= h(gpx_url($t['filename'])) ?>" target="_blank"><?= t('link_download') ?></a>
            </td>

            <td class="col-map" data-label="Mapa">
                <a href="<?= h('detail.php?' . http_build_query(array_merge($_GET, ['id' => $t['id'], 'from' => 'legacy']))) ?>"
                   title="<?= t('title_show_track') ?> <?= (int)$t['id'] ?>">
                    <?= t('link_show') ?>
                </a>
            </td>

            <td class="col-photos" data-label="Fotky">
                <?php $pc = (int)($t['photo_count'] ?? 0); ?>
                <?php if ($pc > 0): ?>
                    <a href="photos.php?track_id=<?= (int)$t['id'] ?>"
                       title="Zobrazit fotky trasy"
                       style="font-weight:600; color:var(--accent-color);"><?= $pc ?></a>
                <?php else: ?>
                    <span style="color:var(--text-muted);">—</span>
                <?php endif; ?>
            </td>
            <td class="col-favorite" data-label="Oblíbené">
                <?php if ($_isAdmin): ?>
                <button class="fav-btn" data-id="<?= (int)$t['id'] ?>" title="<?= t('title_toggle_fav') ?>">
                    <?= !empty($t['is_favorite']) ? '⭐' : '☆' ?>
                </button>
                <?php else: ?>
                    <?= !empty($t['is_favorite']) ? '⭐' : '' ?>
                <?php endif; ?>
            </td>

            <?php if ($_isAdmin): ?>
            <td class="col-action" data-label="Akce">
                <a href="edit.php?id=<?= $t['id'] ?>&from=legacy&<?= h(http_build_query($_GET)) ?>"
                   title="<?= t('title_edit_track') ?> <?= (int)$t['id'] ?>">
                    <?= t('link_edit') ?>
                </a>
            </td>
            <?php endif; ?>

        </tr>

    <?php endforeach; ?>
    </tbody>

</table>
