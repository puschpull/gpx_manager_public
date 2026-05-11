<?php
require_once __DIR__ . '/includes/auth.php';

if (($_GET['ajax'] ?? '') !== '1') {
    render_admin_banner();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/gpx_parser.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/generate_thumb.php';

/* ===========================================================
   Odstranění diakritiky a nevhodných znaků z názvu souboru
   =========================================================== */
function sanitizeFileName($name) {
    $map = [
        'á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z',
        'Á'=>'A','Č'=>'C','Ď'=>'D','É'=>'E','Ě'=>'E','Í'=>'I','Ň'=>'N','Ó'=>'O','Ř'=>'R','Š'=>'S','Ť'=>'T','Ú'=>'U','Ů'=>'U','Ý'=>'Y','Ž'=>'Z'
    ];
    $name = strtr($name, $map);
    $name = preg_replace('/[^\w.-]+/u', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    return trim($name, '_');
}

/* ===========================================================
   Přísná validace GPX souboru
   =========================================================== */
function validate_gpx_file(string $path) {
    if (!is_file($path) || filesize($path) < 20) {
        return 'Soubor je prázdný nebo neexistuje.';
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path) ?: '';
            finfo_close($finfo);
            if (!in_array($mime, ['application/xml', 'text/xml', 'application/gpx+xml', 'text/plain'], true)) {
                return "Soubor nemá očekávaný XML typ ($mime).";
            }
        }
    }

    $head = file_get_contents($path, false, null, 0, 4096);
    if ($head === false || stripos($head, '<gpx') === false) {
        return 'Soubor neobsahuje GPX značky.';
    }

    libxml_use_internal_errors(true);
    $xmlText = @file_get_contents($path);
    if ($xmlText === false) return 'Soubor se nepodařilo načíst.';
    $xml = @simplexml_load_string($xmlText, 'SimpleXMLElement', LIBXML_NONET);
    if (!$xml) return 'Soubor není platný XML.';
    if (strtolower($xml->getName()) !== 'gpx') return 'Kořenový element není <gpx>.';

    $nsGPX = 'http://www.topografix.com/GPX/1/1';
    $xml->registerXPathNamespace('g', $nsGPX);
    $trkpts = $xml->xpath('//g:trk/g:trkseg/g:trkpt');
    if (!$trkpts || count($trkpts) === 0) return 'Soubor neobsahuje žádné body trasy (<trkpt>).';

    return true;
}

/* ===========================================================
   AJAX endpoint: nahrání 1 souboru
   =========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');

    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Neplatný CSRF token.']);
        exit;
    }

    $mode = $_POST['dup_mode'] ?? 'skip';

    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Soubor nebyl přijat.']);
        exit;
    }

    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Chyba při uploadu (UPLOAD_ERR).']);
        exit;
    }

    $origName = $f['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'gpx') {
        http_response_code(415);
        echo json_encode(['ok'=>false,'error'=>'Povolené jsou pouze .gpx soubory.']);
        exit;
    }

    $cleanName = sanitizeFileName($origName);
    if (!str_ends_with(strtolower($cleanName), '.gpx')) {
        $cleanName .= '.gpx';
    }

    $tmpPath = $f['tmp_name'];

    $hash = sha1_file($tmpPath);
    if ($hash === false) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Nepodařilo se spočítat otisk (SHA-1).']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, filename FROM tracks WHERE file_hash = :h LIMIT 1");
    $stmt->execute([':h' => $hash]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $valid = validate_gpx_file($tmpPath);
    if ($valid !== true) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>$valid]);
        exit;
    }

    $gpx = parse_gpx($tmpPath);
    if (!$gpx) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Soubor není platný GPX nebo se nepodařilo načíst.']);
        exit;
    }

    $uploadDir = __DIR__ . '/uploads/';
    $thumbDir  = __DIR__ . '/uploads/thumbs/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    if (!is_dir($thumbDir))  mkdir($thumbDir,  0755, true);

    $finalName = $cleanName;
    $destPath  = $uploadDir . $finalName;
    $i = 2;
    while (file_exists($destPath)) {
        $finalName = pathinfo($cleanName, PATHINFO_FILENAME) . "_$i." . $ext;
        $destPath  = $uploadDir . $finalName;
        $i++;
    }

    /* ===========================================================
       DUPLICITA – režimy skip / overwrite
       =========================================================== */
    if ($existing) {
        if ($mode === 'skip') {
            echo json_encode([
                'ok'       => true,
                'status'   => 'skipped',
                'id'       => (int)$existing['id'],
                'filename' => $existing['filename'],
                'message'  => '⚠️ Duplicitní obsah – přeskočeno.'
            ]);
            exit;
        }

        if ($mode === 'overwrite') {
            $sql = "
                UPDATE tracks SET
                  track_name=:track_name, color=:color, device=:device,
                  date_start=:date_start, date_end=:date_end,
                  duration=:duration, moving_time=:moving_time, stopped_time=:stopped_time,
                  distance_km=:distance_km, ascent=:ascent, descent=:descent,
                  elevation_min=:elevation_min, elevation_max=:elevation_max,
                  speed_max=:speed_max, speed_avg=:speed_avg, speed_avg_total=:speed_avg_total,
                  avg_ascent_rate=:avg_ascent_rate, avg_descent_rate=:avg_descent_rate,
                  max_ascent_rate=:max_ascent_rate, max_descent_rate=:max_descent_rate,
                  bounds=:bounds, trackpoints_count=:trackpoints_count
                WHERE id=:id
            ";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':track_name'        => $gpx['track_name']        ?? null,
                    ':color'             => $gpx['color']             ?? null,
                    ':device'            => $gpx['device']            ?? null,
                    ':date_start'        => $gpx['date_start']        ?? null,
                    ':date_end'          => $gpx['date_end']          ?? null,
                    ':duration'          => $gpx['duration']          ?? null,
                    ':moving_time'       => $gpx['moving_time']       ?? null,
                    ':stopped_time'      => $gpx['stopped_time']      ?? null,
                    ':distance_km'       => $gpx['distance_km']       ?? null,
                    ':ascent'            => $gpx['ascent']            ?? null,
                    ':descent'           => $gpx['descent']           ?? null,
                    ':elevation_min'     => $gpx['elevation_min']     ?? null,
                    ':elevation_max'     => $gpx['elevation_max']     ?? null,
                    ':speed_max'         => $gpx['speed_max']         ?? null,
                    ':speed_avg'         => $gpx['speed_avg']         ?? null,
                    ':speed_avg_total'   => $gpx['speed_avg_total']   ?? null,
                    ':avg_ascent_rate'   => $gpx['avg_ascent_rate']   ?? null,
                    ':avg_descent_rate'  => $gpx['avg_descent_rate']  ?? null,
                    ':max_ascent_rate'   => $gpx['max_ascent_rate']   ?? null,
                    ':max_descent_rate'  => $gpx['max_descent_rate']  ?? null,
                    ':bounds'            => $gpx['bounds']            ?? null,
                    ':trackpoints_count' => $gpx['trackpoints_count'] ?? null,
                    ':id'                => (int)$existing['id'],
                ]);

                // Přegenerování náhledu
                $existingGpxPath = $uploadDir . $existing['filename'];
                $thumbName = pathinfo($existing['filename'], PATHINFO_FILENAME) . '.png';
                generate_thumb($existingGpxPath, $thumbDir . $thumbName);

            } catch (Throwable $e) {
                error_log("import.php UPDATE: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['ok'=>false,'error'=>'DB UPDATE chyba.' . (APP_ENV === 'local' ? ' ' . $e->getMessage() : '')]);
                exit;
            }

            echo json_encode([
                'ok'       => true,
                'status'   => 'overwritten',
                'id'       => (int)$existing['id'],
                'filename' => $existing['filename'],
                'message'  => '🟨 Duplicitní obsah – metadata přepsána.'
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Neplatný dup_mode.']);
        exit;
    }

    /* ===========================================================
       Nový záznam – fyzické uložení a INSERT do DB
       =========================================================== */
    if (!move_uploaded_file($tmpPath, $destPath)) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Nelze uložit nahraný soubor.']);
        exit;
    }

    // Výpočet obtížnosti
    $difficulty = calculateDifficulty(
        isset($gpx['distance_km']) ? (float)$gpx['distance_km'] : null,
        isset($gpx['ascent']) ? (float)$gpx['ascent'] : null,
        isset($gpx['elevation_max']) ? (float)$gpx['elevation_max'] : null,
        isset($gpx['elevation_min']) ? (float)$gpx['elevation_min'] : null
    );

    // Detekce typu aktivity
    $activityTypeImport = detectActivityType(
        isset($gpx['speed_avg']) ? (float)$gpx['speed_avg'] : null,
        isset($gpx['speed_max']) ? (float)$gpx['speed_max'] : null,
        isset($gpx['distance_km']) ? (float)$gpx['distance_km'] : null,
        isset($gpx['ascent']) ? (float)$gpx['ascent'] : null
    );

    $sql = "
        INSERT INTO tracks
        (filename, file_hash, track_name, color, device,
         date_start, date_end, duration, moving_time, stopped_time,
         distance_km, ascent, descent, elevation_min, elevation_max,
         speed_max, speed_avg, speed_avg_total,
         avg_ascent_rate, avg_descent_rate, max_ascent_rate, max_descent_rate,
         bounds, trackpoints_count, difficulty, activity_type)
        VALUES
        (:filename, :file_hash, :track_name, :color, :device,
         :date_start, :date_end, :duration, :moving_time, :stopped_time,
         :distance_km, :ascent, :descent, :elevation_min, :elevation_max,
         :speed_max, :speed_avg, :speed_avg_total,
         :avg_ascent_rate, :avg_descent_rate, :max_ascent_rate, :max_descent_rate,
         :bounds, :trackpoints_count, :difficulty, :activity_type)
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':filename'          => $finalName,
            ':file_hash'         => $hash,
            ':track_name'        => $gpx['track_name']        ?? null,
            ':color'             => $gpx['color']             ?? null,
            ':device'            => $gpx['device']            ?? null,
            ':date_start'        => $gpx['date_start']        ?? null,
            ':date_end'          => $gpx['date_end']          ?? null,
            ':duration'          => $gpx['duration']          ?? null,
            ':moving_time'       => $gpx['moving_time']       ?? null,
            ':stopped_time'      => $gpx['stopped_time']      ?? null,
            ':distance_km'       => $gpx['distance_km']       ?? null,
            ':ascent'            => $gpx['ascent']            ?? null,
            ':descent'           => $gpx['descent']           ?? null,
            ':elevation_min'     => $gpx['elevation_min']     ?? null,
            ':elevation_max'     => $gpx['elevation_max']     ?? null,
            ':speed_max'         => $gpx['speed_max']         ?? null,
            ':speed_avg'         => $gpx['speed_avg']         ?? null,
            ':speed_avg_total'   => $gpx['speed_avg_total']   ?? null,
            ':avg_ascent_rate'   => $gpx['avg_ascent_rate']   ?? null,
            ':avg_descent_rate'  => $gpx['avg_descent_rate']  ?? null,
            ':max_ascent_rate'   => $gpx['max_ascent_rate']   ?? null,
            ':max_descent_rate'  => $gpx['max_descent_rate']  ?? null,
            ':bounds'            => $gpx['bounds']            ?? null,
            ':trackpoints_count' => $gpx['trackpoints_count'] ?? null,
            ':difficulty'        => $difficulty,
            ':activity_type'     => $activityTypeImport,
        ]);

        $newTrackId = (int)$pdo->lastInsertId();

        // Generování náhledu
        $thumbName = pathinfo($finalName, PATHINFO_FILENAME) . '.png';
        generate_thumb($destPath, $thumbDir . $thumbName);

        // Auto-detekce typu aktivity → přiřazení kategorie
        $activityType = detectActivityType(
            isset($gpx['speed_avg']) ? (float)$gpx['speed_avg'] : null,
            isset($gpx['speed_max']) ? (float)$gpx['speed_max'] : null,
            isset($gpx['distance_km']) ? (float)$gpx['distance_km'] : null,
            isset($gpx['ascent']) ? (float)$gpx['ascent'] : null
        );
        $activityMsg = '';
        if ($activityType !== null) {
            // Vytvořit kategorii pokud neexistuje
            $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (:name)")->execute([':name' => $activityType]);
            $catId = (int)$pdo->query("SELECT id FROM categories WHERE name = " . $pdo->quote($activityType))->fetchColumn();
            if ($catId > 0) {
                $pdo->prepare("INSERT IGNORE INTO track_categories (track_id, category_id) VALUES (:tid, :cid)")
                    ->execute([':tid' => $newTrackId, ':cid' => $catId]);
            }
            $activityMsg = " | Aktivita: {$activityType}";
        }

    } catch (Throwable $e) {
        error_log("import.php INSERT: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'DB INSERT chyba.' . (APP_ENV === 'local' ? ' ' . $e->getMessage() : '')]);
        exit;
    }

    echo json_encode([
        'ok'       => true,
        'status'   => 'inserted',
        'id'       => $newTrackId,
        'filename' => $finalName,
        'activity' => $activityType,
        'message'  => '✅ Hotovo – soubor uložen.' . $activityMsg
    ]);
    exit;
}
?>

<?php
$page_title = t('h1_import');
require __DIR__ . '/includes/layout_header.php';
?>
<link rel="stylesheet" href="css/style.css">

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4"></i>
        <?= htmlspecialchars(t('back_to_list')) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="upload" class="w-8 h-8 text-terracotta-500"></i>
        <?= htmlspecialchars(t('h1_import')) ?>
    </h1>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12">


<div class="controls">
    <select id="dupMode" class="select" title="Jak naložit s duplicitami">
        <option value="skip" selected><?= t('dup_mode_skip') ?></option>
        <option value="overwrite"><?= t('dup_mode_overwrite') ?></option>
    </select>
    <button id="pickBtn" class="btn-outdoor btn-outdoor-ghost"><?= t('btn_select_files') ?></button>
    <button id="startBtn" class="btn-outdoor btn-outdoor-primary"><?= t('btn_start_import') ?></button>
    <?php
    $backQuery = $_GET;
    $backUrl = 'index.php' . (!empty($backQuery) ? '?' . build_query($backQuery) : '');
    ?>
    <a href="<?= h($backUrl) ?>" class="btn-outdoor btn-outdoor-ghost"><?= t('back_to_list') ?></a>
</div>

<div id="dropzone">
    <?= t('dropzone_text') ?>
    <div class="note"><?= t('dropzone_note') ?> <strong>SHA-1</strong> <?= t('dropzone_note2') ?></div>
</div>
<input id="fileInput" type="file" accept=".gpx" multiple>

<div id="list" class="list"></div>

<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('fileInput');
const pickBtn   = document.getElementById('pickBtn');
const startBtn  = document.getElementById('startBtn');
const list      = document.getElementById('list');
const dupMode   = document.getElementById('dupMode');
let queue = [];

pickBtn.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', e => addFiles([...e.target.files]));

['dragenter','dragover'].forEach(ev => dropzone.addEventListener(ev, e => {
    e.preventDefault(); e.stopPropagation(); dropzone.classList.add('dragover');
}));
['dragleave','drop'].forEach(ev => dropzone.addEventListener(ev, e => {
    e.preventDefault(); e.stopPropagation(); dropzone.classList.remove('dragover');
}));
dropzone.addEventListener('drop', e => {
    const files = [...e.dataTransfer.files].filter(f => f.name.toLowerCase().endsWith('.gpx'));
    addFiles(files);
});

function addFiles(files) {
    files.forEach(f => {
        queue.push(f);
        list.appendChild(renderRow(f));
    });
}

function renderRow(file) {
    const div = document.createElement('div');
    div.className = 'item';
    div.innerHTML = `
        <div class="name">${file.name}</div>
        <div class="bar"><span></span></div>
        <div class="status"><?= t('import_waiting') ?></div>`;
    div.file = file;
    return div;
}

startBtn.addEventListener('click', async () => {
    startBtn.disabled = true;
    pickBtn.disabled  = true;
    const mode = dupMode.value;

    for (const row of [...document.querySelectorAll('.item')]) {
        const file = row.file;
        if (!file) continue;

        const bar    = row.querySelector('.bar>span');
        const status = row.querySelector('.status');

        try {
            const res = await uploadOne(file, mode, pct => bar.style.width = pct + '%');
            const msg = (res && res.message) ? res.message
                      : (res && res.status)  ? res.status
                      : (res.error || 'Hotovo');

            status.textContent = msg;
            if      (res.status === 'inserted')    row.classList.add('status-inserted');
            else if (res.status === 'overwritten') row.classList.add('status-overwritten');
            else if (res.status === 'skipped')     row.classList.add('status-skipped');
            else if (res.ok === false)             row.classList.add('status-error');

        } catch (err) {
            status.textContent = <?= json_encode(t('msg_error')) ?> + (err.message || err);
            row.classList.add('status-error');
        }
    }

    startBtn.disabled = false;
    pickBtn.disabled  = false;
});

function uploadOne(file, mode, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'import.php?ajax=1');
        xhr.responseType = 'json';

        xhr.upload.onprogress = e => {
            if (e.lengthComputable) {
                onProgress(Math.round((e.loaded / e.total) * 100));
            }
        };

        xhr.onload = () => {
            let data = xhr.response;
            if (!data || typeof data !== 'object') {
                try { data = JSON.parse(xhr.responseText); } catch (e) { data = {}; }
            }
            if (xhr.status >= 200 && xhr.status < 300) resolve(data);
            else reject(new Error((data && data.error) ? data.error : 'HTTP ' + xhr.status));
        };

        xhr.onerror = () => reject(new Error(<?= json_encode(t('import_network_err')) ?>));

        const fd = new FormData();
        fd.append('_csrf_token', CSRF_TOKEN);
        fd.append('dup_mode', mode);
        fd.append('file', file, file.name);
        xhr.send(fd);
    });
}
</script>

<a href="<?= h($backUrl) ?>" class="btn"><?= t('back_to_list') ?></a>

</div><?php require __DIR__ . '/includes/layout_footer.php'; ?>