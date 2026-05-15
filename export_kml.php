<?php
/**
 * export_kml.php — Export trasy jako KML (Google Earth / Google Maps / QGIS)
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/gpx_parser.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Invalid ID'); }

$stmt = $pdo->prepare("SELECT id, filename, track_name FROM tracks WHERE id = ?");
$stmt->execute([$id]);
$track = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$track) { http_response_code(404); exit('Track not found'); }

$gpxPath = __DIR__ . '/uploads/' . $track['filename'];
if (!file_exists($gpxPath)) { http_response_code(404); exit('GPX file not found'); }

$gpx = safe_load_gpx($gpxPath);
if (!$gpx) { http_response_code(422); exit('Failed to parse GPX'); }

// Načtení trackpointů (s podporou namespace i bez)
$ns = $gpx->getNamespaces(true);
$defaultNs = $ns[''] ?? null;
if ($defaultNs) {
    $gpx->registerXPathNamespace('g', $defaultNs);
    $trkpts = $gpx->xpath('//g:trkpt') ?: [];
} else {
    $trkpts = $gpx->xpath('//trkpt') ?: [];
}

$name     = $track['track_name'] ?: pathinfo($track['filename'], PATHINFO_FILENAME);
$safeName = preg_replace('/[^\w\-]/', '_', $name);

header('Content-Type: application/vnd.google-earth.kml+xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $safeName . '.kml"');

$coords = [];
foreach ($trkpts as $pt) {
    $lat  = number_format((float)$pt['lat'], 7, '.', '');
    $lon  = number_format((float)$pt['lon'], 7, '.', '');
    $ele  = isset($pt->ele) ? number_format((float)$pt->ele, 2, '.', '') : '0';
    $coords[] = "$lon,$lat,$ele";
}
$coordStr = implode(' ', $coords);
$nameEsc  = htmlspecialchars($name, ENT_XML1, 'UTF-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<kml xmlns="http://www.opengis.net/kml/2.2">
<Document>
  <name><?= $nameEsc ?></name>
  <Style id="trackStyle">
    <LineStyle>
      <color>ff0000ff</color>
      <width>3</width>
    </LineStyle>
  </Style>
  <Placemark>
    <name><?= $nameEsc ?></name>
    <styleUrl>#trackStyle</styleUrl>
    <LineString>
      <altitudeMode>absolute</altitudeMode>
      <coordinates><?= $coordStr ?></coordinates>
    </LineString>
  </Placemark>
</Document>
</kml>
