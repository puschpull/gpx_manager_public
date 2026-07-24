<?php
declare(strict_types=1);

/**
 * Dávkové generování chybějících náhledů tras (OSM tiles).
 *
 * POST + CSRF only — GET zobrazí potvrzovací formulář. Každá dávka je
 * samostatný POST; pokračování mezi dávkami řeší auto-odesílaný formulář
 * (nahrazuje původní GET meta-refresh, který obcházel CSRF ochranu).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/generate_thumb.php';

ini_set('memory_limit', '256M');

$uploadDir  = uploads_fs();
$thumbDir   = uploads_fs('thumbs/');
$batchSize  = 20; // počet tras na jednu dávku

/* ===== GET: potvrzovací formulář ===== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM tracks")->fetchColumn();
    ?><!DOCTYPE html>
<html lang="<?= htmlspecialchars(function_exists('app_lang') ? app_lang() : 'cs') ?>">
<head>
    <meta charset="UTF-8">
    <title>Rebuild náhledů</title>
    <style>
        body { font-family: monospace; margin: 40px auto; max-width: 560px; background: #1e1e1e; color: #ccc; }
        .card { border: 1px solid #444; border-radius: 10px; padding: 20px 24px; }
        button { padding: 10px 18px; font-size: 15px; border: 0; border-radius: 6px; background: #4caf50; color: #000; font-weight: bold; cursor: pointer; }
        a { color: #4da3ff; }
    </style>
</head>
<body>
<div class="card">
    <h2>🖼️ Generování náhledů tras</h2>
    <p>Projde všech <strong><?= $total ?></strong> tras po dávkách
       (<?= $batchSize ?> tras/dávka) a vygeneruje <em>chybějící</em> náhledy.
       Existující náhledy se přeskakují.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="offset" value="0">
        <button type="submit">▶ Spustit generování</button>
    </form>
    <p><a href="index.php">← Zpět na přehled</a></p>
</div>
</body>
</html><?php
    exit;
}

/* ===== POST: zpracování jedné dávky ===== */
if (!csrf_verify()) {
    http_response_code(403);
    die('Neplatný bezpečnostní token.');
}

if (!is_dir($thumbDir)) @mkdir($thumbDir, 0755, true);

$offset = max(0, (int)($_POST['offset'] ?? 0));

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
$progress   = $total > 0 ? min(100, (int)round($nextOffset / $total * 100)) : 100;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(function_exists('app_lang') ? app_lang() : 'cs') ?>">
<head>
    <meta charset="UTF-8">
    <title>Rebuild náhledů</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #1e1e1e; color: #ccc; }
        .ok   { color: #4caf50; }
        .skip { color: #888; }
        .fail { color: #f44336; }
        .progress-wrap { background: #333; border-radius: 6px; height: 24px; margin: 16px 0; overflow: hidden; }
        .progress-bar  { background: #4caf50; height: 100%; transition: width .3s; display: flex; align-items: center; padding-left: 8px; font-size: 13px; color: #000; font-weight: bold; }
        .summary { margin-top: 20px; font-size: 15px; border-top: 1px solid #444; padding-top: 10px; }
        .done { color: #4caf50; font-size: 18px; font-weight: bold; margin-top: 16px; }
        button { background: none; border: 0; padding: 0; font: inherit; cursor: pointer; }
        .btn-next    { color: #4da3ff; }
        .btn-restart { color: #888; }
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
    <form method="post" id="next-batch">
        <?= csrf_field() ?>
        <input type="hidden" name="offset" value="<?= $nextOffset ?>">
        <div class="summary">
            <button type="submit" class="btn-next">▶ Pokračovat ručně pokud stránka nepokračuje sama</button>
        </div>
    </form>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="offset" value="0">
        <button type="submit" class="btn-restart">↺ Začít od začátku</button>
    </form>
    <script>setTimeout(function () { document.getElementById('next-batch').submit(); }, 2000);</script>
<?php endif; ?>

</body>
</html>
