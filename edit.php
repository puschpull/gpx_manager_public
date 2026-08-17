<?php
require_once __DIR__ . '/includes/auth.php';

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

$allowedLangs = available_langs();

/* ===== ID záznamu ===== */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die(t('err_invalid_id'));

$stmt = $pdo->prepare("SELECT * FROM tracks WHERE id = ?");
$stmt->execute([$id]);
$track = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$track) die(t('err_track_not_found'));

/* ===== Kategorie ===== */
$catStmt = $pdo->prepare("
    SELECT c.name
    FROM track_categories tc
    JOIN categories c ON c.id = tc.category_id
    WHERE tc.track_id = ?
    ORDER BY c.name
");
$catStmt->execute([$id]);
$currentCats = $catStmt->fetchAll(PDO::FETCH_COLUMN);

$allCats = $pdo->query("SELECT name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

/* ===== Pomocné funkce pro datetime ===== */
function dt_to_local_value($dt) {
    if (empty($dt) || $dt === '0000-00-00 00:00:00') return '';
    $ts = strtotime($dt);
    if ($ts === false) return '';
    return date('Y-m-d\TH:i', $ts);
}

function local_value_to_dt($val) {
    $val = trim((string)$val);
    if ($val === '') return null;
    $val = str_replace('T', ' ', $val);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $val)) $val .= ':00';
    $ts = strtotime($val);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
}

/* ===== Navigace — předchozí / následující ===== */
$filterActive = isset($_GET['filter_submit']);

$sortOptions = [
    'id', 'filename', 'track_name', 'alt_title', 'note', 'color', 'device',
    'date_start', 'date_end', 'duration', 'moving_time', 'stopped_time',
    'distance_km', 'ascent', 'descent', 'elevation_min', 'elevation_max',
    'speed_max', 'speed_avg', 'speed_avg_total',
    'avg_ascent_rate', 'avg_descent_rate', 'max_ascent_rate', 'max_descent_rate',
    'trackpoints_count', 'difficulty', 'activity_type', 'is_favorite', 'created_at',
];

$sort_by  = $_GET['sort_by']  ?? 'date_start';
$sort_dir = strtoupper($_GET['sort_dir'] ?? 'DESC');
if (!in_array($sort_by, $sortOptions, true)) $sort_by = 'date_start';
if (!in_array($sort_dir, ['ASC', 'DESC']))   $sort_dir = 'DESC';

$_filterNav = buildFilterSQL($filterActive ? null : [], 't.');
$params_nav = $_filterNav['params'];
$whereNav   = $_filterNav['where'] ? ('WHERE ' . $_filterNav['where']) : '';

// Aktuální hodnota řazeného sloupce
$currentVal = $track[$sort_by] ?? null;

// ====== Navigace — 2 cílené queries místo fetchAll všech ID (PERF-2, BE-15) ======
$prevId    = null;
$nextId    = null;
$position  = null;
$total_nav = null; // není potřeba; odstraněno (bylo O(n))

$filterAnd = $whereNav ? 'AND' : 'WHERE';

if ($sort_dir === 'ASC') {
    // Previous: (col < cur) OR (col = cur AND id < cur_id), ORDER col DESC, id DESC
    $paramsPrev = $params_nav;
    $paramsPrev[':nav_cur_val']  = $currentVal;
    $paramsPrev[':nav_cur_val2'] = $currentVal;
    $paramsPrev[':nav_cur_id']   = $id;
    $stmtPrev = $pdo->prepare("
        SELECT t.id FROM tracks t
        $whereNav
        $filterAnd (t.`$sort_by` < :nav_cur_val OR (t.`$sort_by` = :nav_cur_val2 AND t.id < :nav_cur_id))
        ORDER BY t.`$sort_by` DESC, t.id DESC
        LIMIT 1
    ");
    $stmtPrev->execute($paramsPrev);
    $prevId = $stmtPrev->fetchColumn() ?: null;

    // Next: (col > cur) OR (col = cur AND id > cur_id), ORDER col ASC, id ASC
    $paramsNext = $params_nav;
    $paramsNext[':nav_cur_val']  = $currentVal;
    $paramsNext[':nav_cur_val2'] = $currentVal;
    $paramsNext[':nav_cur_id']   = $id;
    $stmtNext = $pdo->prepare("
        SELECT t.id FROM tracks t
        $whereNav
        $filterAnd (t.`$sort_by` > :nav_cur_val OR (t.`$sort_by` = :nav_cur_val2 AND t.id > :nav_cur_id))
        ORDER BY t.`$sort_by` ASC, t.id ASC
        LIMIT 1
    ");
    $stmtNext->execute($paramsNext);
    $nextId = $stmtNext->fetchColumn() ?: null;

} else {
    // DESC primary sort, tie-break by id ASC
    // Previous in display order = higher col value (or same col, smaller id)
    $paramsPrev = $params_nav;
    $paramsPrev[':nav_cur_val']  = $currentVal;
    $paramsPrev[':nav_cur_val2'] = $currentVal;
    $paramsPrev[':nav_cur_id']   = $id;
    $stmtPrev = $pdo->prepare("
        SELECT t.id FROM tracks t
        $whereNav
        $filterAnd (t.`$sort_by` > :nav_cur_val OR (t.`$sort_by` = :nav_cur_val2 AND t.id < :nav_cur_id))
        ORDER BY t.`$sort_by` ASC, t.id DESC
        LIMIT 1
    ");
    $stmtPrev->execute($paramsPrev);
    $prevId = $stmtPrev->fetchColumn() ?: null;

    // Next in display order = lower col value (or same col, larger id)
    $paramsNext = $params_nav;
    $paramsNext[':nav_cur_val']  = $currentVal;
    $paramsNext[':nav_cur_val2'] = $currentVal;
    $paramsNext[':nav_cur_id']   = $id;
    $stmtNext = $pdo->prepare("
        SELECT t.id FROM tracks t
        $whereNav
        $filterAnd (t.`$sort_by` < :nav_cur_val OR (t.`$sort_by` = :nav_cur_val2 AND t.id > :nav_cur_id))
        ORDER BY t.`$sort_by` DESC, t.id ASC
        LIMIT 1
    ");
    $stmtNext->execute($paramsNext);
    $nextId = $stmtNext->fetchColumn() ?: null;
}

/* ===== POST zpracování ===== */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = t('err_invalid_csrf');
        goto render;
    }
    $data = [];

    $data['filename']   = trim($_POST['filename']   ?? '');
    $data['track_name'] = trim($_POST['track_name'] ?? '');
    $data['alt_title']  = trim($_POST['alt_title']  ?? '');
    $data['note']       = trim($_POST['note']       ?? '');
    $data['color']      = trim($_POST['color']      ?? '');
    $data['device']     = trim($_POST['device']     ?? '');
    $data['place_name'] = trim($_POST['place_name'] ?? '');
    $data['bounds']     = trim($_POST['bounds']     ?? '');
    $data['file_hash']  = trim($_POST['file_hash']  ?? '');

    $data['date_start'] = local_value_to_dt($_POST['date_start'] ?? '');
    $data['date_end']   = local_value_to_dt($_POST['date_end']   ?? '');
    $data['created_at'] = local_value_to_dt($_POST['created_at'] ?? '');

    $data['duration']          = isset($_POST['duration'])          && $_POST['duration']          !== '' ? (int)$_POST['duration']          : null;
    $data['moving_time']       = isset($_POST['moving_time'])       && $_POST['moving_time']       !== '' ? (int)$_POST['moving_time']       : null;
    $data['stopped_time']      = isset($_POST['stopped_time'])      && $_POST['stopped_time']      !== '' ? (int)$_POST['stopped_time']      : null;
    $data['trackpoints_count'] = isset($_POST['trackpoints_count']) && $_POST['trackpoints_count'] !== '' ? (int)$_POST['trackpoints_count'] : null;

    $float_keys = [
        'distance_km','ascent','descent','elevation_min','elevation_max',
        'speed_max','speed_avg','speed_avg_total',
        'avg_ascent_rate','avg_descent_rate','max_ascent_rate','max_descent_rate'
    ];
    foreach ($float_keys as $k) {
        $val = trim((string)($_POST[$k] ?? ''));
        $val = $val === '' ? null : str_replace(',', '.', $val);
        if ($val !== null && !is_numeric($val)) $val = null;
        $data[$k] = $val;
    }

    foreach (['filename','track_name','alt_title','note','color','device','place_name','bounds','file_hash'] as $k) {
        if ($data[$k] === '') $data[$k] = null;
    }

    // Filename validation: must be <word chars + hyphens>.gpx or null/empty
    // Reject traversal attempts (../config.php) and arbitrary extensions.
    if ($data['filename'] !== null) {
        if (!preg_match('/^[\w\-]+\.gpx$/i', $data['filename'])) {
            $flash = t('err_invalid_filename') ?: 'Invalid filename — only alphanumeric characters, hyphens, and .gpx extension are allowed.';
            // Keep existing DB value unchanged; jump straight to render
            goto render;
        }
    }

    $catsLine = trim($_POST['categories'] ?? '');
    $newCats  = [];
    if ($catsLine !== '') {
        $parts = preg_split('/[,\n;]+/u', $catsLine);
        foreach ($parts as $p) {
            $name = trim($p);
            if ($name !== '') $newCats[$name] = true;
        }
    }
    $newCats = array_keys($newCats);

    // Přepočet obtížnosti
    $data['difficulty'] = calculateDifficulty(
        $data['distance_km'] !== null ? (float)$data['distance_km'] : null,
        $data['ascent'] !== null ? (float)$data['ascent'] : null,
        $data['elevation_max'] !== null ? (float)$data['elevation_max'] : null,
        $data['elevation_min'] !== null ? (float)$data['elevation_min'] : null
    );

    // Přepočet typu aktivity
    $data['activity_type'] = detectActivityType(
        $data['speed_avg'] !== null ? (float)$data['speed_avg'] : null,
        $data['speed_max'] !== null ? (float)$data['speed_max'] : null,
        $data['distance_km'] !== null ? (float)$data['distance_km'] : null,
        $data['ascent'] !== null ? (float)$data['ascent'] : null
    );

    $fields = [
        'filename','track_name','alt_title','note','color','device','place_name',
        'date_start','date_end','duration','moving_time','stopped_time',
        'distance_km','ascent','descent','elevation_min','elevation_max',
        'speed_max','speed_avg','speed_avg_total',
        'avg_ascent_rate','avg_descent_rate','max_ascent_rate','max_descent_rate',
        'bounds','trackpoints_count','difficulty','activity_type','created_at','file_hash'
    ];

    $setParts = [];
    $params   = [':id' => $id];
    foreach ($fields as $f) {
        $setParts[]    = "`$f` = :$f";
        $params[":$f"] = $data[$f] ?? null;
    }
    $sql = "UPDATE tracks SET " . implode(', ', $setParts) . " WHERE id = :id";

    $pdo->beginTransaction();
    try {
        $pdo->prepare($sql)->execute($params);

        $pdo->prepare("DELETE FROM track_categories WHERE track_id = ?")->execute([$id]);
        if (!empty($newCats)) {
            $insCat = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (:name)");
            $selCat = $pdo->prepare("SELECT id FROM categories WHERE name = :name");
            $insTC  = $pdo->prepare("INSERT INTO track_categories (track_id, category_id) VALUES (:tid, :cid)");
            foreach ($newCats as $cname) {
                $insCat->execute([':name' => $cname]);
                $selCat->execute([':name' => $cname]);
                $cid = (int)$selCat->fetchColumn();
                if ($cid > 0) $insTC->execute([':tid' => $id, ':cid' => $cid]);
            }
        }

        $pdo->commit();
        $flash = t('flash_saved');

        $stmt->execute([$id]);
        $track = $stmt->fetch(PDO::FETCH_ASSOC);

        $catStmt->execute([$id]);
        $currentCats = $catStmt->fetchAll(PDO::FETCH_COLUMN);

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("edit.php save: " . $e->getMessage());
        $flash = t('flash_save_error') . (APP_ENV === 'local' ? ' Detail: ' . h($e->getMessage()) : '');
    }
}

render:
$catsText = !empty($currentCats) ? implode(', ', $currentCats) : '';

/* ===== Query string pro navigaci ===== */
$navQuery = $_GET;
unset($navQuery['id']);
$navQs = !empty($navQuery) ? '&' . http_build_query($navQuery) : '';

// Back URL: zachovat filtry + respektovat původ (legacy tabulka vs kartový pohled)
$_editBackQuery = $navQuery; // navQuery již neobsahuje 'id'
$_fromLegacy    = ($_editBackQuery['from'] ?? '') === 'legacy';
unset($_editBackQuery['from']); // 'from' je interní, do return URL nepatří
$editBackUrl    = ($_fromLegacy ? 'index-legacy.php' : 'index.php') .
                  (!empty($_editBackQuery) ? '?' . http_build_query($_editBackQuery) : '');
$editBackLabel  = t($_fromLegacy ? 'back_to_table' : 'back_to_list');
?>

<?php
$page_title = t('h1_edit_track');
require __DIR__ . '/includes/layout_header.php';
?>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="<?= h($editBackUrl) ?>" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars($editBackLabel) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="pencil" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h1_edit_track')) ?>
    </h1>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12">


    <!-- Navigace mezi trasami -->
    <div class="track-nav">
        <?php if ($prevId): ?>
            <a class="btn-outdoor btn-outdoor-ghost" href="edit.php?id=<?= $prevId ?><?= h($navQs) ?>">
                <i data-lucide="chevron-left" class="w-4 h-4" aria-hidden="true"></i><?= t('nav_prev') ?>
            </a>
        <?php else: ?>
            <span class="btn-outdoor btn-outdoor-ghost opacity-40 pointer-events-none">
                <i data-lucide="chevron-left" class="w-4 h-4" aria-hidden="true"></i><?= t('nav_prev') ?>
            </span>
        <?php endif; ?>

        <span class="track-nav-info">
            <?php if ($position !== null): ?>
                <?= t('track_position') ?> <strong><?= $position ?></strong> / <?= $total_nav ?>
            <?php endif; ?>
        </span>

        <?php if ($nextId): ?>
            <a class="btn-outdoor btn-outdoor-ghost" href="edit.php?id=<?= $nextId ?><?= h($navQs) ?>">
                <?= t('nav_next') ?><i data-lucide="chevron-right" class="w-4 h-4" aria-hidden="true"></i>
            </a>
        <?php else: ?>
            <span class="btn-outdoor btn-outdoor-ghost opacity-40 pointer-events-none">
                <?= t('nav_next') ?><i data-lucide="chevron-right" class="w-4 h-4" aria-hidden="true"></i>
            </span>
        <?php endif; ?>
    </div>

<div class="meta">
        <strong><?= t('meta_file') ?></strong> <?= h($track['filename']) ?> &nbsp;|&nbsp;
        <strong><?= t('meta_gpx_name') ?></strong> <?= h($track['track_name']) ?>
        <?php
        $thumbName  = pathinfo($track['filename'], PATHINFO_FILENAME) . '.png';
        $thumbFile  = uploads_fs('thumbs/' . $thumbName);
        $thumbUrl   = thumb_url($thumbName);
        ?>
        <?php if (is_file($thumbFile)): ?>
            <a href="detail.php?id=<?= (int)$track['id'] ?>&<?= h($navQs) ?>">
                <img src="<?= h($thumbUrl) ?>"
                     width="240" height="120"
                     alt="<?= t('thumb_alt') ?>"
                     class="track-thumb"
                     style="vertical-align:middle; margin-left:16px;">
            </a>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
        <?php $isFlashError = str_starts_with($flash, 'Chyba') || str_starts_with($flash, t('flash_save_error')); ?>
        <div class="flash <?= $isFlashError ? 'flash-error' : 'flash-ok' ?>"
             <?= $isFlashError ? 'role="alert"' : 'role="status" aria-live="polite"' ?>>
            <?= h($flash) ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>

        <div class="row">
            <div class="col">
                <label for="filename"><?= t('label_filename') ?></label>
                <input type="text" id="filename" name="filename" value="<?= h($track['filename'] ?? '') ?>">
            </div>
            <div class="col">
                <label for="track_name"><?= t('label_track_name') ?></label>
                <input type="text" id="track_name" name="track_name" value="<?= h($track['track_name'] ?? '') ?>">
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label for="alt_title"><?= t('label_alt_title') ?></label>
                <input type="text" id="alt_title" name="alt_title" value="<?= h($track['alt_title'] ?? '') ?>">
            </div>
            <div class="col">
                <label for="color"><?= t('label_color') ?></label>
                <input type="text" id="color" name="color" value="<?= h($track['color'] ?? '') ?>" placeholder="např. Cyan, Red, Blue">
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label for="device"><?= t('label_device') ?></label>
                <input type="text" id="device" name="device" value="<?= h($track['device'] ?? '') ?>">

                <label for="place_name"><?= t('label_place_name') ?></label>
                <input type="text" id="place_name" name="place_name" maxlength="120"
                       value="<?= h($track['place_name'] ?? '') ?>">
                <div class="field-hint"><?= t('hint_place_name') ?></div>
            </div>
            <div class="col">
                <label for="bounds"><?= t('label_bounds') ?></label>
                <input type="text" id="bounds" name="bounds" value="<?= h($track['bounds'] ?? '') ?>" placeholder='{"minlat":..,"minlon":..,"maxlat":..,"maxlon":..}'>
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label for="date_start"><?= t('label_date_start') ?></label>
                <input type="datetime-local" id="date_start" name="date_start" value="<?= h(dt_to_local_value($track['date_start'] ?? '')) ?>">
            </div>
            <div class="col">
                <label for="date_end"><?= t('label_date_end') ?></label>
                <input type="datetime-local" id="date_end" name="date_end" value="<?= h(dt_to_local_value($track['date_end'] ?? '')) ?>">
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label for="duration"><?= t('label_duration') ?></label>
                <input type="number" id="duration" name="duration" value="<?= h($track['duration'] ?? '') ?>" step="1" min="0">
            </div>
            <div class="col">
                <label for="moving_time"><?= t('label_moving_time') ?></label>
                <input type="number" id="moving_time" name="moving_time" value="<?= h($track['moving_time'] ?? '') ?>" step="1" min="0">
            </div>
            <div class="col">
                <label for="stopped_time"><?= t('label_stopped_time') ?></label>
                <input type="number" id="stopped_time" name="stopped_time" value="<?= h($track['stopped_time'] ?? '') ?>" step="1" min="0">
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label for="distance_km"><?= t('label_distance_km') ?></label>
                <input type="text" id="distance_km" name="distance_km" value="<?= h($track['distance_km'] ?? '') ?>" placeholder="např. 12.345">
            </div>
            <div class="col">
                <label for="ascent"><?= t('label_ascent') ?></label>
                <input type="text" id="ascent" name="ascent" value="<?= h($track['ascent'] ?? '') ?>" placeholder="např. 123.4">
            </div>
            <div class="col">
                <label for="descent"><?= t('label_descent') ?></label>
                <input type="text" id="descent" name="descent" value="<?= h($track['descent'] ?? '') ?>" placeholder="např. 123.4">
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label for="elevation_min"><?= t('label_elev_min') ?></label>
                <input type="text" id="elevation_min" name="elevation_min" value="<?= h($track['elevation_min'] ?? '') ?>" placeholder="např. 12.3">
            </div>
            <div class="col">
                <label for="elevation_max"><?= t('label_elev_max') ?></label>
                <input type="text" id="elevation_max" name="elevation_max" value="<?= h($track['elevation_max'] ?? '') ?>" placeholder="např. 123.4">
            </div>
            <div class="col">
                <label for="trackpoints_count"><?= t('label_trackpoints') ?></label>
                <input type="number" id="trackpoints_count" name="trackpoints_count" value="<?= h($track['trackpoints_count'] ?? '') ?>" step="1" min="0">
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label for="speed_max"><?= t('label_speed_max') ?></label>
                <input type="text" id="speed_max" name="speed_max" value="<?= h($track['speed_max'] ?? '') ?>" placeholder="např. 12.345">
            </div>
            <div class="col">
                <label for="speed_avg"><?= t('label_speed_avg') ?></label>
                <input type="text" id="speed_avg" name="speed_avg" value="<?= h($track['speed_avg'] ?? '') ?>" placeholder="např. 3.456">
            </div>
            <div class="col">
                <label for="speed_avg_total"><?= t('label_speed_avg_t') ?></label>
                <input type="text" id="speed_avg_total" name="speed_avg_total" value="<?= h($track['speed_avg_total'] ?? '') ?>" placeholder="např. 2.345">
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label for="avg_ascent_rate">avg↑ (m/s)</label>
                <input type="text" id="avg_ascent_rate" name="avg_ascent_rate" value="<?= h($track['avg_ascent_rate'] ?? '') ?>" placeholder="např. 0.123">
            </div>
            <div class="col">
                <label for="avg_descent_rate">avg↓ (m/s)</label>
                <input type="text" id="avg_descent_rate" name="avg_descent_rate" value="<?= h($track['avg_descent_rate'] ?? '') ?>" placeholder="např. 0.123">
            </div>
            <div class="col">
                <label for="max_ascent_rate">max↑ (m/s)</label>
                <input type="text" id="max_ascent_rate" name="max_ascent_rate" value="<?= h($track['max_ascent_rate'] ?? '') ?>" placeholder="např. 0.456">
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label for="max_descent_rate">max↓ (m/s)</label>
                <input type="text" id="max_descent_rate" name="max_descent_rate" value="<?= h($track['max_descent_rate'] ?? '') ?>" placeholder="např. 0.456">
            </div>
            <div class="col">
                <label for="file_hash"><?= t('label_file_hash') ?></label>
                <input type="text" id="file_hash" name="file_hash" value="<?= h($track['file_hash'] ?? '') ?>">
            </div>
            <div class="col">
                <label for="created_at"><?= t('label_created_at') ?></label>
                <input type="datetime-local" id="created_at" name="created_at" value="<?= h(dt_to_local_value($track['created_at'] ?? '')) ?>">
            </div>
        </div>

        <div class="row">
            <div class="col" style="flex-basis:100%;">
                <label for="note"><?= t('label_note') ?></label>
                <textarea id="note" name="note"><?= h($track['note'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label for="categories"><?= t('label_categories') ?></label>
                <input type="text" id="categories" name="categories" value="<?= h($catsText) ?>" placeholder="např. Krušné, Výhledy">
                <?php if (!empty($allCats)): ?>
                    <div class="hint"><?= t('meta_avail_cats') ?>
                        <span class="taglist">
                            <?php foreach ($allCats as $c): ?>
                                <span class="tag"><?= h($c) ?></span>
                            <?php endforeach; ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <div class="col">
                <button class="btn-outdoor btn-outdoor-primary" type="submit">
                    <i data-lucide="save" class="w-4 h-4" aria-hidden="true"></i><?= t('save') ?>
                </button>
                <a class="btn-outdoor btn-outdoor-ghost" href="<?= h($editBackUrl) ?>">
                    <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i><?= htmlspecialchars($editBackLabel) ?>
                </a>
                <a class="btn-outdoor btn-outdoor-ghost" href="detail.php?id=<?= (int)$track['id'] ?>&<?= h($navQs) ?>">
                    <i data-lucide="map" class="w-4 h-4" aria-hidden="true"></i><?= t('btn_show_on_map') ?>
                </a>
            </div>
        </div>

    </form>

    <!-- Smazání trasy -->
    <form method="post" action="delete.php"
          data-confirm-text="<?= htmlspecialchars(t('confirm_delete') . ' \'' . ($track['track_name'] ?: $track['filename']) . '\'' . t('confirm_delete_end'), ENT_QUOTES, 'UTF-8') ?>"
          onsubmit="return confirm(this.dataset.confirmText);"
          style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border-color);">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$track['id'] ?>">
        <?php
        // Předáme filtry pro redirect zpět
        $filterKeys = ['filter_submit','q','color','cat','cat_mode','sort_by','sort_dir','per_page','page'];
        foreach ($filterKeys as $fk):
            $fv = $_GET[$fk] ?? null;
            if ($fv === null) continue;
            if (is_array($fv)):
                foreach ($fv as $item): ?>
                    <input type="hidden" name="<?= h($fk) ?>[]" value="<?= h($item) ?>">
                <?php endforeach;
            else: ?>
                <input type="hidden" name="<?= h($fk) ?>" value="<?= h($fv) ?>">
            <?php endif;
        endforeach; ?>
        <button type="submit" class="delete-btn"><?= t('btn_delete_track') ?></button>
    </form>

    <!-- JS pro klikatelné tagy kategorií -->
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const input = document.getElementById('categories');
        const tags  = document.querySelectorAll('.tag');

        function syncActiveTags() {
            const current = input.value.split(',').map(s => s.trim()).filter(Boolean);
            tags.forEach(tag => {
                tag.classList.toggle('tag-active', current.includes(tag.textContent.trim()));
            });
        }

        tags.forEach(tag => {
            tag.style.cursor = 'pointer';
            tag.addEventListener('click', () => {
                const name    = tag.textContent.trim();
                let current   = input.value.split(',').map(s => s.trim()).filter(Boolean);
                const idx     = current.indexOf(name);
                if (idx === -1) current.push(name);
                else current.splice(idx, 1);
                input.value = current.join(', ');
                syncActiveTags();
            });
        });

        syncActiveTags();
    });
    </script>

</div>

</div><?php require __DIR__ . '/includes/layout_footer.php'; ?>