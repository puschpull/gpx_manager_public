<?php
/**
 * Hromadné rozpoznání typu aktivity u existujících tras
 * Aktualizuje sloupec activity_type u VŠECH tras.
 * Přiřadí kategorii (Pěšky/Turistika/Běh/Kolo/E-bike/Auto) pokud ještě nemají žádnou z nich.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

$activityCategories = ['Pěšky', 'Turistika', 'Běh', 'Kolo', 'E-bike', 'Auto'];

$tracks = $pdo->query("
    SELECT id, speed_avg, speed_max, distance_km, ascent
    FROM tracks
")->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$catAssigned = 0;
$catSkipped = 0;

foreach ($tracks as $t) {
    $id = (int)$t['id'];

    $activity = detectActivityType(
        $t['speed_avg'] !== null ? (float)$t['speed_avg'] : null,
        $t['speed_max'] !== null ? (float)$t['speed_max'] : null,
        $t['distance_km'] !== null ? (float)$t['distance_km'] : null,
        $t['ascent'] !== null ? (float)$t['ascent'] : null
    );

    // Vždy aktualizovat sloupec activity_type
    $pdo->prepare("UPDATE tracks SET activity_type = :at WHERE id = :id")
        ->execute([':at' => $activity, ':id' => $id]);
    $updated++;

    // Přidat kategorii jen pokud ještě nemá žádnou aktivitní
    if ($activity !== null) {
        $existingCats = $pdo->prepare("
            SELECT c.name FROM track_categories tc
            JOIN categories c ON c.id = tc.category_id
            WHERE tc.track_id = ? AND c.name IN ('" . implode("','", $activityCategories) . "')
        ");
        $existingCats->execute([$id]);
        if ($existingCats->fetchColumn()) {
            $catSkipped++;
        } else {
            $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (:name)")->execute([':name' => $activity]);
            $catId = (int)$pdo->query("SELECT id FROM categories WHERE name = " . $pdo->quote($activity))->fetchColumn();
            if ($catId > 0) {
                $pdo->prepare("INSERT IGNORE INTO track_categories (track_id, category_id) VALUES (:tid, :cid)")
                    ->execute([':tid' => $id, ':cid' => $catId]);
                $catAssigned++;
            }
        }
    }
}

echo "Aktualizováno activity_type: {$updated} tras<br>\n";
echo "Nových kategorií přiřazeno: {$catAssigned} | Přeskočeno (již měly kategorii): {$catSkipped}\n";
echo "<br><a href='index.php'>← Zpět na přehled</a>";
echo " | <a href='stats.php'>📊 Statistiky</a>";
