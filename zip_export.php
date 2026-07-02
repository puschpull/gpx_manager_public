<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

/* ===== Sestavení filtru ===== */
$filterActive = isset($_GET['filter_submit']);

$_filterZip = buildFilterSQL($filterActive ? null : [], 't.');
$params     = $_filterZip['params'];
$whereSql   = $_filterZip['where'] ? ('WHERE ' . $_filterZip['where']) : '';

/* ===== Načtení souborů ===== */
$sql = "
    SELECT t.filename
    FROM tracks t
    $whereSql
    ORDER BY t.date_start DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($rows)) {
    http_response_code(404);
    die('Žádné trasy neodpovídají filtru.');
}

/* ===== Kontrola ZipArchive ===== */
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    die('PHP rozšíření ZipArchive není dostupné.');
}

/* ===== Sestavení ZIP ===== */
$uploadDir = uploads_fs();
$zipName   = 'gpx_export_' . date('Y-m-d_His') . '_(' . count($rows) . 'tras).zip';
$tmpZip    = sys_get_temp_dir() . '/' . $zipName;

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    die('Nepodařilo se vytvořit ZIP archiv.');
}

$added   = 0;
$missing = [];

foreach ($rows as $filename) {
    $filePath = $uploadDir . $filename;
    if (is_file($filePath)) {
        $zip->addFile($filePath, $filename);
        $added++;
    } else {
        $missing[] = $filename;
    }
}

$zip->close();

/* ===== Logování chybějících souborů ===== */
if (!empty($missing)) {
    error_log('zip_export.php – chybějící GPX soubory: ' . implode(', ', $missing));
}

if ($added === 0) {
    unlink($tmpZip);
    http_response_code(404);
    die('Žádný GPX soubor nebyl nalezen na disku.');
}

/* ===== Odeslání ZIP ===== */
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Pragma: no-cache');
header('Expires: 0');

readfile($tmpZip);
unlink($tmpZip);
exit;