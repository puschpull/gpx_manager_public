<?php
/**
 * Přepočet obtížnosti u všech tras
 * Spouští se jednorázově nebo po importu.
 *
 * POST + CSRF only — GET zobrazí potvrzovací formulář (hromadná mutace
 * nesmí proběhnout omylem navštíveným odkazem).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

/* ===== GET: potvrzovací formulář ===== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM tracks")->fetchColumn();
    ?><!DOCTYPE html>
<html lang="<?= htmlspecialchars(function_exists('app_lang') ? app_lang() : 'cs') ?>">
<head>
    <meta charset="UTF-8">
    <title>Přepočet obtížnosti</title>
    <style>
        body { font-family: sans-serif; margin: 40px auto; max-width: 560px; color: #333; }
        .card { border: 1px solid #ddd; border-radius: 10px; padding: 20px 24px; }
        button { padding: 10px 18px; font-size: 15px; border: 0; border-radius: 6px; background: #2e7d32; color: #fff; cursor: pointer; }
        a { color: #1565c0; }
    </style>
</head>
<body>
<div class="card">
    <h2>⛰️ Přepočet obtížnosti</h2>
    <p>Přepočítá obtížnost (1–5) podle vzdálenosti a převýšení u všech
       <strong><?= $total ?></strong> tras.</p>
    <form method="post">
        <?= csrf_field() ?>
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
