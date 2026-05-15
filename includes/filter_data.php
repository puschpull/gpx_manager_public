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

    http_response_code(400);
    echo json_encode(['error' => 'Unknown ajax action']);
    exit;
}

// ====== Stylování ======
init_theme_cookie();
$theme = active_theme();
$available_themes = available_themes();
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
