<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  share_image.php — obrázek trasy pro náhled sdíleného odkazu
 *
 *  GET ?id=<track_id> → PNG 1200×630.
 *
 *  Obrázek se generuje až při prvním vyžádání a ukládá se do
 *  uploads/share/. Starší trasy tak nemusí nic přegenerovávat
 *  a hromadné rebuildy odpadají. Přegeneruje se, když je GPX
 *  novější než hotový obrázek (třeba po filtraci trasy).
 *
 *  Přístup: stejná pravidla jako detail trasy — když je detail
 *  pro návštěvníky skrytý, obrázek se nevydá. Jinak by tudy
 *  vedla cesta kolem Viditelných stránek.
 * ===========================================================
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/share_image.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit; }

if (empty($_SESSION['is_admin'])) {
    $visible = get_app_config('visible_pages', all_pages());
    if (!in_array('detail', (array)$visible, true)) { http_response_code(403); exit; }
}

$st = $pdo->prepare('SELECT filename FROM tracks WHERE id = ? LIMIT 1');
$st->execute([$id]);
$filename = $st->fetchColumn();
if (!$filename) { http_response_code(404); exit; }

$gpxPath = uploads_fs((string)$filename);
if (!is_file($gpxPath)) { http_response_code(404); exit; }

$outPath = uploads_fs('share/' . $id . '.jpg');
$type    = 'image/jpeg';

$fresh = is_file($outPath) && filemtime($outPath) >= filemtime($gpxPath);
if (!$fresh && !generate_share_image($gpxPath, $outPath)) {
    // Náhrada: malý náhled z importu je pořád lepší než rozbitý obrázek
    $thumb = uploads_fs('thumbs/' . pathinfo((string)$filename, PATHINFO_FILENAME) . '.png');
    if (is_file($thumb)) { $outPath = $thumb; $type = 'image/png'; }
    else { http_response_code(404); exit; }
}

// ?warm=1 — jen připravit soubor, nic neposílat. Detail trasy si to vyžádá
// na pozadí, takže obrázek je hotový dřív, než odkaz někam vložíš; jinak by
// na něj čekal až robot náhledu (u nové trasy okolo 9 s, pak už z disku).
if (!empty($_GET['warm'])) { http_response_code(204); exit; }

header('Content-Type: ' . $type);
header('Content-Length: ' . (string)filesize($outPath));
header('Cache-Control: public, max-age=86400');
readfile($outPath);
