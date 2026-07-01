<?php
require_once __DIR__ . '/../includes/helpers.php';

// ====== AJAX endpointy pro presety ======
$ajax = $_GET['ajax'] ?? '';
if ($ajax !== '') {
    header('Content-Type: application/json; charset=utf-8');

    // Vytvoření tabulky pokud neexistuje
    $pdo->exec("CREATE TABLE IF NOT EXISTS filter_presets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT DEFAULT NULL,
        settings JSON NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($ajax === 'presets' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query('SELECT id, name, description, settings FROM filter_presets ORDER BY name');
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($ajax === 'preset_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token invalid']);
            exit;
        }
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Přístup odepřen']);
            exit;
        }
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $settings = $_POST['settings'] ?? '{}';
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Name required']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE filter_presets SET name = ?, description = ?, settings = ? WHERE id = ?');
            $stmt->execute([$name, $description, $settings, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO filter_presets (name, description, settings) VALUES (?, ?, ?)');
            $stmt->execute([$name, $description, $settings]);
            $id = (int)$pdo->lastInsertId();
        }
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($ajax === 'preset_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token invalid']);
            exit;
        }
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Přístup odepřen']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM filter_presets WHERE id = ?');
            $stmt->execute([$id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($ajax === 'save_cleaned' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token invalid']);
            exit;
        }
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Přístup odepřen']);
            exit;
        }
        $gpxContent = $_POST['gpx_content'] ?? '';
        if ($gpxContent === '') {
            http_response_code(400);
            echo json_encode(['error' => 'No GPX content']);
            exit;
        }

        // Size guard: reject payloads over 50 MB before any further processing
        if (strlen($gpxContent) > 50 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['error' => 'GPX too large (max 50 MB)']);
            exit;
        }

        // DOCTYPE/ENTITY pre-check on the first 4 KB to block XXE attempts
        $head = substr($gpxContent, 0, 4096);
        if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
            http_response_code(400);
            echo json_encode(['error' => 'GPX contains DTD/ENTITY declarations']);
            exit;
        }

        // Sanitize original_name: keep only safe characters for the filename stem
        $rawName  = $_POST['original_name'] ?? 'cleaned.gpx';
        $baseName = preg_replace('/[^\w\-]+/', '_', pathinfo(basename($rawName), PATHINFO_FILENAME));
        if ($baseName === '' || $baseName === '_') {
            $baseName = 'cleaned';
        }

        $newName = $baseName . '_cleaned.gpx';
        $targetPath = uploads_fs($newName);
        // Pokud existuje, přidej číslo
        $counter = 1;
        while (file_exists($targetPath)) {
            $newName = $baseName . '_cleaned_' . $counter . '.gpx';
            $targetPath = uploads_fs($newName);
            $counter++;
        }
        file_put_contents($targetPath, $gpxContent);

        // Parsování a uložení do DB
        require_once __DIR__ . '/gpx_parser.php';
        $parsed = parse_gpx($targetPath);
        if ($parsed === null) {
            unlink($targetPath);
            http_response_code(400);
            echo json_encode(['error' => 'Failed to parse cleaned GPX']);
            exit;
        }
        $fileHash = sha1_file($targetPath);
        $stmt = $pdo->prepare('INSERT INTO tracks (
            filename, track_name, color, device, date_start, date_end,
            duration, moving_time, stopped_time, distance_km,
            ascent, descent, elevation_min, elevation_max,
            speed_max, speed_avg, speed_avg_total,
            avg_ascent_rate, avg_descent_rate, max_ascent_rate, max_descent_rate,
            bounds, trackpoints_count, file_hash
        ) VALUES (
            :filename, :track_name, :color, :device, :date_start, :date_end,
            :duration, :moving_time, :stopped_time, :distance_km,
            :ascent, :descent, :elevation_min, :elevation_max,
            :speed_max, :speed_avg, :speed_avg_total,
            :avg_ascent_rate, :avg_descent_rate, :max_ascent_rate, :max_descent_rate,
            :bounds, :trackpoints_count, :file_hash
        )');
        $stmt->execute([
            ':filename'       => $newName,
            ':track_name'     => $parsed['track_name'] ? $parsed['track_name'] . ' (cleaned)' : $newName,
            ':color'          => $parsed['color'] ?? '',
            ':device'         => $parsed['device'] ?? '',
            ':date_start'     => $parsed['date_start'],
            ':date_end'       => $parsed['date_end'],
            ':duration'       => $parsed['duration'],
            ':moving_time'    => $parsed['moving_time'],
            ':stopped_time'   => $parsed['stopped_time'],
            ':distance_km'    => $parsed['distance_km'],
            ':ascent'         => $parsed['ascent'],
            ':descent'        => $parsed['descent'],
            ':elevation_min'  => $parsed['elevation_min'],
            ':elevation_max'  => $parsed['elevation_max'],
            ':speed_max'      => $parsed['speed_max'],
            ':speed_avg'      => $parsed['speed_avg'],
            ':speed_avg_total'=> $parsed['speed_avg_total'],
            ':avg_ascent_rate' => $parsed['avg_ascent_rate'] ?? 0,
            ':avg_descent_rate'=> $parsed['avg_descent_rate'] ?? 0,
            ':max_ascent_rate' => $parsed['max_ascent_rate'] ?? 0,
            ':max_descent_rate'=> $parsed['max_descent_rate'] ?? 0,
            ':bounds'         => $parsed['bounds'],
            ':trackpoints_count' => $parsed['trackpoints_count'],
            ':file_hash'      => $fileHash,
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Generování náhledu
        require_once __DIR__ . '/generate_thumb.php';
        generate_thumb($targetPath, uploads_fs('thumbs/' . pathinfo($newName, PATHINFO_FILENAME) . '.png'));

        echo json_encode(['ok' => true, 'id' => $newId, 'filename' => $newName]);
        exit;
    }

    // ---- Aplikace vyčištěné verze PŘÍMO na existující trasu (in-place, stejné id) ----
    // Přepíše GPX soubor + přepočítá statistiky trasy. id se NEMĚNÍ, takže
    // navázané fotky (track_photos.track_id) i kategorie (track_categories) zůstanou.
    // Uživatelská metadata (název, poznámka, aktivita, obtížnost, oblíbená) se NEMĚNÍ.
    // Originál GPX se jednorázově zazálohuje jako <soubor>.bak.
    if ($ajax === 'apply_cleaned' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token invalid']);
            exit;
        }
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Přístup odepřen']);
            exit;
        }
        $trackId = (int)($_POST['track_id'] ?? 0);
        if ($trackId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Chybí track_id']);
            exit;
        }
        $gpxContent = $_POST['gpx_content'] ?? '';
        if ($gpxContent === '') {
            http_response_code(400);
            echo json_encode(['error' => 'No GPX content']);
            exit;
        }
        if (strlen($gpxContent) > 50 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['error' => 'GPX too large (max 50 MB)']);
            exit;
        }
        $head = substr($gpxContent, 0, 4096);
        if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
            http_response_code(400);
            echo json_encode(['error' => 'GPX contains DTD/ENTITY declarations']);
            exit;
        }

        // Cílová trasa musí existovat
        $trStmt = $pdo->prepare('SELECT id, filename FROM tracks WHERE id = ?');
        $trStmt->execute([$trackId]);
        $tr = $trStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tr) {
            http_response_code(404);
            echo json_encode(['error' => 'Trasa nenalezena']);
            exit;
        }
        $filename   = (string)$tr['filename'];
        $targetPath = uploads_fs($filename);

        // Jednorázová záloha originálu (.bak drží nejstarší verzi)
        $backupPath = $targetPath . '.bak';
        if (is_file($targetPath) && !is_file($backupPath)) {
            @copy($targetPath, $backupPath);
        }

        if (file_put_contents($targetPath, $gpxContent) === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Zápis GPX selhal']);
            exit;
        }

        require_once __DIR__ . '/gpx_parser.php';
        $parsed = parse_gpx($targetPath);
        if ($parsed === null) {
            if (is_file($backupPath)) { @copy($backupPath, $targetPath); }  // obnovit, ať nezůstane rozbitý soubor
            http_response_code(400);
            echo json_encode(['error' => 'Failed to parse cleaned GPX']);
            exit;
        }
        $fileHash = sha1_file($targetPath);

        // Centroid z bounds (pro geo dotazy)
        $centroidLat = null; $centroidLon = null;
        $b = $parsed['bounds'] ? json_decode((string)$parsed['bounds'], true) : null;
        if (is_array($b) && isset($b['minlat'], $b['maxlat'], $b['minlon'], $b['maxlon'])) {
            $centroidLat = ((float)$b['minlat'] + (float)$b['maxlat']) / 2;
            $centroidLon = ((float)$b['minlon'] + (float)$b['maxlon']) / 2;
        }

        // UPDATE STEJNÉHO řádku — jen geometrie a přepočítané statistiky.
        $upd = $pdo->prepare('UPDATE tracks SET
            date_start=:date_start, date_end=:date_end,
            duration=:duration, moving_time=:moving_time, stopped_time=:stopped_time,
            distance_km=:distance_km, ascent=:ascent, descent=:descent,
            elevation_min=:elevation_min, elevation_max=:elevation_max,
            speed_max=:speed_max, speed_avg=:speed_avg, speed_avg_total=:speed_avg_total,
            avg_ascent_rate=:avg_ascent_rate, avg_descent_rate=:avg_descent_rate,
            max_ascent_rate=:max_ascent_rate, max_descent_rate=:max_descent_rate,
            bounds=:bounds, trackpoints_count=:trackpoints_count, file_hash=:file_hash,
            centroid_lat=:centroid_lat, centroid_lon=:centroid_lon
            WHERE id=:id');
        $upd->execute([
            ':date_start'       => $parsed['date_start'],
            ':date_end'         => $parsed['date_end'],
            ':duration'         => $parsed['duration'],
            ':moving_time'      => $parsed['moving_time'],
            ':stopped_time'     => $parsed['stopped_time'],
            ':distance_km'      => $parsed['distance_km'],
            ':ascent'           => $parsed['ascent'],
            ':descent'          => $parsed['descent'],
            ':elevation_min'    => $parsed['elevation_min'],
            ':elevation_max'    => $parsed['elevation_max'],
            ':speed_max'        => $parsed['speed_max'],
            ':speed_avg'        => $parsed['speed_avg'],
            ':speed_avg_total'  => $parsed['speed_avg_total'],
            ':avg_ascent_rate'  => $parsed['avg_ascent_rate'] ?? 0,
            ':avg_descent_rate' => $parsed['avg_descent_rate'] ?? 0,
            ':max_ascent_rate'  => $parsed['max_ascent_rate'] ?? 0,
            ':max_descent_rate' => $parsed['max_descent_rate'] ?? 0,
            ':bounds'           => $parsed['bounds'],
            ':trackpoints_count'=> $parsed['trackpoints_count'],
            ':file_hash'        => $fileHash,
            ':centroid_lat'     => $centroidLat,
            ':centroid_lon'     => $centroidLon,
            ':id'               => $trackId,
        ]);

        // Přegeneruj náhled (stejný název souboru → přepíše starý thumb)
        require_once __DIR__ . '/generate_thumb.php';
        @generate_thumb($targetPath, uploads_fs('thumbs/' . pathinfo($filename, PATHINFO_FILENAME) . '.png'));

        echo json_encode(['ok' => true, 'id' => $trackId]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown ajax action']);
    exit;
}

$allowedLangs = available_langs();

// ====== Načtení trasy (volitelné) ======
$track = null;
$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM tracks WHERE id = ?');
    $stmt->execute([$id]);
    $track = $stmt->fetch(PDO::FETCH_ASSOC);
}

$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
