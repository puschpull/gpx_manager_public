<?php
declare(strict_types=1);

/**
 * Dávkové doplnění názvů míst (tracks.place_name).
 *
 * Místo se ukazuje ve sloupci kompletní tabulky, v detailu trasy a jde
 * upravit v editaci; u tras pojmenovaných časovým razítkem z něj navíc
 * vzniká titulek.
 *
 * K funkčnosti to potřeba není — místo se doplní samo při prvním zobrazení
 * detailu. Smysl dávky je jiný: projít všechna zjištěná jména naráz
 * a nepovedená rovnou opravit.
 *
 * POST + CSRF only — GET zobrazí potvrzovací formulář. Každá dávka je
 * samostatný POST; pokračování řeší auto-odesílaný formulář (stejný postup
 * jako rebuild_thumbs.php).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/track_title.php';

// Menší dávka než u náhledů: každá trasa znamená jeden až dva dotazy na cizí
// API, takže se dávka drží pod limitem doby běhu skriptu.
$batchSize = 10;

/* Původně se místo zjišťovalo jen u tras s razítkovým názvem, protože jinde
   nebylo k čemu. Od chvíle, kdy je místo i sloupcem v tabulce, v detailu
   a v editaci, se doplňuje u všech tras — jinak by byl sloupec z poloviny
   prázdný. */
const REBUILD_PLACES_WHERE = "1";

/* ===== GET: potvrzovací formulář ===== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $total   = (int)$pdo->query("SELECT COUNT(*) FROM tracks WHERE " . REBUILD_PLACES_WHERE)->fetchColumn();
    $missing = (int)$pdo->query("SELECT COUNT(*) FROM tracks WHERE " . REBUILD_PLACES_WHERE
                              . " AND place_name IS NULL")->fetchColumn();
    $hasKey  = defined('MAPYCOM_API_KEY') && MAPYCOM_API_KEY !== '';
    ?><!DOCTYPE html>
<html lang="<?= htmlspecialchars(function_exists('app_lang') ? app_lang() : 'cs') ?>">
<head>
    <meta charset="UTF-8">
    <title>Doplnění názvů míst</title>
    <style>
        body { font-family: monospace; margin: 40px auto; max-width: 620px; background: #1e1e1e; color: #ccc; }
        .card { border: 1px solid #444; border-radius: 10px; padding: 20px 24px; }
        button { padding: 10px 18px; font-size: 15px; border: 0; border-radius: 6px; background: #4caf50; color: #000; font-weight: bold; cursor: pointer; }
        a { color: #4da3ff; }
        .warn { color: #ffb74d; }
        label { display: block; margin: 14px 0; font-size: 14px; }
    </style>
</head>
<body>
<div class="card">
    <h2>📍 Doplnění názvů míst</h2>
    <p>Tras celkem: <strong><?= $total ?></strong>, z toho bez zjištěného místa
       <strong><?= $missing ?></strong>.</p>
    <p>U každé se přečte start (a u přejezdové trasy i cíl) přímo z GPX
       a přes Mapy.com se zjistí název místa. Zpracovává se po
       <?= $batchSize ?> trasách; výsledek se vypisuje ke kontrole.</p>
    <?php if (!$hasKey): ?>
        <p class="warn">⚠ Chybí MAPYCOM_API_KEY — bez klíče se nezjistí nic.</p>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="offset" value="0">
        <label>
            <input type="checkbox" name="refresh" value="1">
            Přepočítat i trasy, které místo už mají (jinak se přeskakují)
        </label>
        <button type="submit">▶ Spustit doplňování</button>
    </form>
    <p><a href="admin.php">← Zpět do administrace</a></p>
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

$offset  = max(0, (int)($_POST['offset'] ?? 0));
$refresh = !empty($_POST['refresh']);

$total = (int)$pdo->query("SELECT COUNT(*) FROM tracks WHERE " . REBUILD_PLACES_WHERE)->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM tracks WHERE " . REBUILD_PLACES_WHERE
                    . " ORDER BY date_start DESC, id DESC LIMIT :lim OFFSET :off");
$stmt->bindValue(':lim', $batchSize, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset,    PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$found = 0; $skipped = 0; $failed = 0;
$log   = [];

foreach ($rows as $track) {
    $had = $track['place_name'] !== null && trim((string)$track['place_name']) !== '';

    if ($had && !$refresh) {
        $log[] = ['status' => 'skip', 'id' => (int)$track['id'],
                  'place' => (string)$track['place_name'],
                  'title' => track_display_title($pdo, $track)];
        $skipped++;
        continue;
    }
    if ($refresh) {
        // Vynutit nové zjištění — track_place_name() jinak vrátí uloženou hodnotu
        $track['place_name'] = null;
    }

    $place = track_place_name($pdo, $track);
    $track['place_name'] = $place ?? '';
    $title = track_display_title($pdo, $track);

    if ($place !== null) { $found++;  $status = 'ok'; }
    else                 { $failed++; $status = 'fail'; }

    $log[] = ['status' => $status, 'id' => (int)$track['id'],
              'place' => $place ?? '—', 'title' => $title];
}

$nextOffset = $offset + $batchSize;
$done       = $nextOffset >= $total;
$progress   = $total > 0 ? min(100, (int)round($nextOffset / $total * 100)) : 100;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(function_exists('app_lang') ? app_lang() : 'cs') ?>">
<head>
    <meta charset="UTF-8">
    <title>Doplnění názvů míst</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #1e1e1e; color: #ccc; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; }
        td, th { padding: 4px 8px; border-bottom: 1px solid #333; text-align: left; vertical-align: top; }
        th { color: #888; font-weight: normal; }
        .ok   { color: #4caf50; }
        .skip { color: #888; }
        .fail { color: #f44336; }
        .progress-wrap { background: #333; border-radius: 6px; height: 24px; margin: 16px 0; overflow: hidden; }
        .progress-bar  { background: #4caf50; height: 100%; transition: width .3s; display: flex; align-items: center; padding-left: 8px; font-size: 13px; color: #000; font-weight: bold; }
        .summary { margin-top: 20px; font-size: 15px; border-top: 1px solid #444; padding-top: 10px; }
        .done { color: #4caf50; font-size: 18px; font-weight: bold; margin-top: 16px; }
        button { background: none; border: 0; padding: 0; font: inherit; cursor: pointer; }
        a { color: #4da3ff; }
        .btn-next { color: #4da3ff; }
    </style>
</head>
<body>

<h2>📍 Doplnění názvů míst</h2>

<div class="progress-wrap">
    <div class="progress-bar" style="width:<?= $progress ?>%"><?= $progress ?>%</div>
</div>

<p>
    Zpracováno: <strong><?= min($nextOffset, $total) ?></strong> / <strong><?= $total ?></strong> tras
    &nbsp;|&nbsp; v této dávce: nalezeno <?= $found ?>, přeskočeno <?= $skipped ?>, bez výsledku <?= $failed ?>
    <?php if (!$done): ?>&nbsp;|&nbsp; <em>Pokračuji za 2 sekundy…</em><?php endif; ?>
</p>

<table>
    <tr><th>trasa</th><th>místo</th><th>výsledný titulek</th></tr>
    <?php foreach ($log as $e): ?>
    <tr class="<?= $e['status'] ?>">
        <td><a href="detail.php?id=<?= $e['id'] ?>" target="_blank">#<?= $e['id'] ?></a></td>
        <td><?= htmlspecialchars($e['place']) ?></td>
        <td><?= htmlspecialchars($e['title']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<?php if ($done): ?>
    <div class="done">✅ Hotovo.</div>
    <div class="summary">
        Nepovedený název přepiš v editaci trasy v poli <strong>Místo</strong>.
        Celý titulek jde přebít <strong>alternativním titulkem</strong>,
        ten má přednost před vším.<br>
        <a href="admin.php">← Zpět do administrace</a>
    </div>
<?php else: ?>
    <form method="post" id="next-batch">
        <?= csrf_field() ?>
        <input type="hidden" name="offset" value="<?= $nextOffset ?>">
        <?php if ($refresh): ?><input type="hidden" name="refresh" value="1"><?php endif; ?>
        <div class="summary">
            <button type="submit" class="btn-next">▶ Pokračovat ručně, pokud stránka nepokračuje sama</button>
        </div>
    </form>
    <script>setTimeout(function () { document.getElementById('next-batch').submit(); }, 2000);</script>
<?php endif; ?>

</body>
</html>
