<?php
declare(strict_types=1);

/**
 * Smazání trasy — POST only, s CSRF ochranou
 */
require_once __DIR__ . '/includes/auth.php';
render_admin_banner();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!csrf_verify()) {
    die('Neplatný bezpečnostní token.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    die('Neplatné ID.');
}

// Načteme trasu pro smazání souboru
$stmt = $pdo->prepare("SELECT filename FROM tracks WHERE id = ?");
$stmt->execute([$id]);
$track = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$track) {
    die('Trasa nenalezena.');
}

$pdo->beginTransaction();
try {
    // Smazání kategorií
    $pdo->prepare("DELETE FROM track_categories WHERE track_id = ?")->execute([$id]);

    // Smazání trasy z DB
    $pdo->prepare("DELETE FROM tracks WHERE id = ?")->execute([$id]);

    $pdo->commit();

    // Smazání souborů trasy z uploads/ (GPX + .bak, náhled, radarové snímky)
    delete_track_files($id, (string)$track['filename']);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log("delete.php: " . $e->getMessage());
    die('Chyba při mazání trasy.' . (APP_ENV === 'local' ? ' Detail: ' . htmlspecialchars($e->getMessage()) : ''));
}

// Přesměrování zpět na index s filtry
$backParams = $_POST;
unset($backParams['id'], $backParams['_csrf_token'], $backParams['delete_confirm']);
$backUrl = 'index.php' . (!empty($backParams) ? '?' . http_build_query($backParams) : '');
header('Location: ' . $backUrl);
exit;
