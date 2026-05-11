<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/generate_thumb.php';

ini_set('memory_limit', '256M');

$uploadDir  = __DIR__ . '/uploads/';
$thumbDir   = __DIR__ . '/uploads/thumbs/';
$batchSize  = 20; // počet tras na jednu dávku

if (!is_dir($thumbDir)) @mkdir($thumbDir, 0755, true);

$offset = max(0, (int)($_GET['offset'] ?? 0));

// Celkový počet
$total = (int)$pdo->query("SELECT COUNT(*) FROM tracks")->fetchColumn();

// Načtení dávky
$stmt = $pdo->prepare("SELECT id, filename FROM tracks ORDER BY id ASC LIMIT :lim OFFSET :off");
$stmt->bindValue(':lim', $batchSize, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset,    PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ok      = 0;
$skipped = 0;
$failed  = 0;
$log     = [];

foreach ($rows as $row) {
    $filename  = $row['filename'];
    $gpxPath   = $uploadDir . $filename;
    $thumbName = pathinfo($filename, PATHINFO_FILENAME) . '.png';
    $thumbPath = $thumbDir . $thumbName;

    if (is_file($thumbPath)) {
        $log[] = ['status' => 'skip', 'msg' => "⏭ [{$row['id']}] {$filename} – přeskočeno"];
        $skipped++;
        continue;
    }

    if (!is_file($gpxPath)) {
        $log[] = ['status' => 'fail', 'msg' => "✗ [{$row['id']}] {$filename} – GPX nenalezen"];
        $failed++;
        continue;
    }

    $result = generate_thumb($gpxPath, $thumbPath);
    if ($result) {
        $log[] = ['status' => 'ok', 'msg' => "✓ [{$row['id']}] {$filename}"];
        $ok++;
    } else {
        $log[] = ['status' => 'fail', 'msg' => "✗ [{$row['id']}] {$filename} – selhalo"];
        $failed++;
    }
}

$nextOffset = $offset + $batchSize;
$done       = $nextOffset >= $total;
$progress   = min(100, (int)round($nextOffset / $total * 100));
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Rebuild náhledů</title>
    <?php if (!$done): ?>
    <meta http-equiv="refresh" content="2;url=rebuild_thumbs.php?offset=<?= $nextOffset ?>">
    <?php endif; ?>
    <style>
        body { font-family: monospace; margin: 20px; background: #1e1e1e; color: #ccc; }
        .ok   { color: #4caf50; }
        .skip { color: #888; }
        .fail { color: #f44336; }
        .progress-wrap { background: #333; border-radius: 6px; height: 24px; margin: 16px 0; overflow: hidden; }
        .progress-bar  { background: #4caf50; height: 100%; transition: width .3s; display: flex; align-items: center; padding-left: 8px; font-size: 13px; color: #000; font-weight: bold; }
        .summary { margin-top: 20px; font-size: 15px; border-top: 1px solid #444; padding-top: 10px; }
        .done { color: #4caf50; font-size: 18px; font-weight: bold; margin-top: 16px; }
    </style>
</head>
<body>

<h2>🖼️ Generování náhledů tras</h2>

<div class="progress-wrap">
    <div class="progress-bar" style="width:<?= $progress ?>%"><?= $progress ?>%</div>
</div>

<p>
    Zpracováno: <strong><?= min($nextOffset, $total) ?></strong> / <strong><?= $total ?></strong> tras
    &nbsp;|&nbsp; Dávka: <?= $offset + 1 ?>–<?= min($nextOffset, $total) ?>
    <?php if (!$done): ?>
        &nbsp;|&nbsp; <em>Pokračuji za 2 sekundy…</em>
    <?php endif; ?>
</p>

<pre>
<?php foreach ($log as $entry): ?>
<span class="<?= $entry['status'] ?>"><?= htmlspecialchars($entry['msg']) ?></span>
<?php endforeach; ?>
</pre>

<?php if ($done): ?>
    <div class="done">✅ Hotovo! Všechny náhledy byly zpracovány.</div>
    <div class="summary">
        Celkem: <strong><?= $total ?></strong> tras<br>
        <a href="index.php" style="color:#4da3ff;">← Zpět na přehled tras</a>
    </div>
<?php else: ?>
    <div class="summary">
        <a href="rebuild_thumbs.php?offset=<?= $nextOffset ?>" style="color:#4da3ff;">
            ▶ Pokračovat ručně pokud se stránka neobnoví
        </a>
        &nbsp;|&nbsp;
        <a href="rebuild_thumbs.php?offset=0" style="color:#888;">
            ↺ Začít od začátku
        </a>
    </div>
<?php endif; ?>

</body>
</html>