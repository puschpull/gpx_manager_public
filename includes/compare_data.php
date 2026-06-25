<?php
require_once __DIR__ . '/../includes/helpers.php';

$allowedLangs = available_langs();

// ====== Načtení vybraných tras ======
$ids = [];
$tracks = [];

// Podpora pro GET parametr ids (čárkami oddělená ID) i pole id[]
if (!empty($_GET['ids'])) {
    $ids = array_map('intval', explode(',', $_GET['ids']));
} elseif (!empty($_GET['id']) && is_array($_GET['id'])) {
    $ids = array_map('intval', $_GET['id']);
}

// Odstranit nulové a duplicitní
$ids = array_unique(array_filter($ids, fn($id) => $id > 0));

if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT id, filename, track_name, alt_title, color, device,
               date_start, date_end, duration, moving_time, stopped_time,
               distance_km, ascent, descent, elevation_min, elevation_max,
               speed_max, speed_avg, speed_avg_total,
               difficulty, activity_type, bounds
        FROM tracks
        WHERE id IN ($placeholders)
        ORDER BY FIELD(id, $placeholders)
    ");
    // FIELD() potřebuje ID dvakrát — jednou pro IN, jednou pro řazení
    $stmt->execute(array_merge($ids, $ids));
    $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$trackCount = count($tracks);
