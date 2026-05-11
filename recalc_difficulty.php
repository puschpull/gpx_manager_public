<?php
/**
 * Přepočet obtížnosti u všech tras
 * Spouští se jednorázově nebo po importu
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

$tracks = $pdo->query("SELECT id, distance_km, ascent, elevation_max, elevation_min FROM tracks")->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$stmt = $pdo->prepare("UPDATE tracks SET difficulty = :diff WHERE id = :id");

foreach ($tracks as $t) {
    $diff = calculateDifficulty(
        (float)($t['distance_km'] ?? 0),
        (float)($t['ascent'] ?? 0),
        (float)($t['elevation_max'] ?? 0),
        (float)($t['elevation_min'] ?? 0)
    );
    $stmt->execute([':diff' => $diff, ':id' => $t['id']]);
    $updated++;
}

echo "Přepočteno: {$updated} tras.\n";
echo "<br><a href='index.php'>← Zpět na přehled</a>";
