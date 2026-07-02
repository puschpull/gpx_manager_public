<?php
/**
 * Hromadné rozpoznání typu aktivity u existujících tras
 * Aktualizuje sloupec activity_type a přiřadí kategorii
 * (Pěšky/Turistika/Běh/Kolo/E-bike/Auto) pokud ještě nemají žádnou z nich.
 *
 * POST + CSRF only — GET zobrazí potvrzovací formulář. Přepočet přepisuje
 * activity_type (včetně ručně nastavených hodnot), omylem navštívený odkaz
 * nesmí nic změnit.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

$activityCategories = ['Pěšky', 'Turistika', 'Běh', 'Kolo', 'E-bike', 'Auto'];

/* ===== GET: potvrzovací formulář ===== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $total   = (int)$pdo->query("SELECT COUNT(*) FROM tracks")->fetchColumn();
    $missing = (int)$pdo->query("SELECT COUNT(*) FROM tracks WHERE activity_type IS NULL OR activity_type = ''")->fetchColumn();
    ?><!DOCTYPE html>
<html lang="<?= htmlspecialchars(function_exists('app_lang') ? app_lang() : 'cs') ?>">
<head>
    <meta charset="UTF-8">
    <title>Přepočet typu aktivity</title>
    <style>
        body { font-family: sans-serif; margin: 40px auto; max-width: 560px; color: #333; }
        .card { border: 1px solid #ddd; border-radius: 10px; padding: 20px 24px; }
        .warn { background: #fff3cd; border: 1px solid #ffe08a; border-radius: 6px; padding: 10px 14px; margin: 14px 0; }
        label { display: block; margin: 14px 0; }
        button { padding: 10px 18px; font-size: 15px; border: 0; border-radius: 6px; background: #2e7d32; color: #fff; cursor: pointer; }
        a { color: #1565c0; }
    </style>
</head>
<body>
<div class="card">
    <h2>🏃 Přepočet typu aktivity</h2>
    <p>Celkem tras: <strong><?= $total ?></strong>, z toho bez aktivity: <strong><?= $missing ?></strong>.</p>
    <div class="warn">
        ⚠️ Přepočet <strong>všech</strong> tras přepíše i ručně nastavené typy aktivit
        (detekce podle rychlosti). Výchozí volba proto zpracuje jen trasy bez aktivity.
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <label>
            <input type="checkbox" name="only_missing" value="1" checked>
            Jen trasy bez nastavené aktivity (<?= $missing ?> tras)
        </label>
        <button type="submit">▶ Spustit přepočet</button>
    </form>
    <p><a href="index.php">← Zpět na přehled</a></p>
</div>
</body>
</html><?php
    exit;
}

/* ===== POST: provedení přepočtu ===== */
if (!csrf_verify()) {
    http_response_code(403);
    die('Neplatný bezpečnostní token.');
}

$onlyMissing = !empty($_POST['only_missing']);

$sql = "SELECT id, speed_avg, speed_max, distance_km, ascent FROM tracks";
if ($onlyMissing) {
    $sql .= " WHERE activity_type IS NULL OR activity_type = ''";
}
$tracks = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

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
        // Build IN clause with positional placeholders to avoid string interpolation
        $inPlaceholders = implode(',', array_fill(0, count($activityCategories), '?'));
        $existingCats = $pdo->prepare("
            SELECT c.name FROM track_categories tc
            JOIN categories c ON c.id = tc.category_id
            WHERE tc.track_id = ? AND c.name IN ($inPlaceholders)
        ");
        $existingCats->execute(array_merge([$id], $activityCategories));
        if ($existingCats->fetchColumn()) {
            $catSkipped++;
        } else {
            $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)")->execute([$activity]);
            $catSelAct = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $catSelAct->execute([$activity]);
            $catId = (int)$catSelAct->fetchColumn();
            if ($catId > 0) {
                $pdo->prepare("INSERT IGNORE INTO track_categories (track_id, category_id) VALUES (?, ?)")
                    ->execute([$id, $catId]);
                $catAssigned++;
            }
        }
    }
}

echo "Režim: " . ($onlyMissing ? 'jen trasy bez aktivity' : 'všechny trasy') . "<br>\n";
echo "Aktualizováno activity_type: {$updated} tras<br>\n";
echo "Nových kategorií přiřazeno: {$catAssigned} | Přeskočeno (již měly kategorii): {$catSkipped}\n";
echo "<br><a href='index.php'>← Zpět na přehled</a>";
echo " | <a href='stats.php'>📊 Statistiky</a>";
