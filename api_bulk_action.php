<?php
/**
 * GPX Manager — Hromadné operace (AJAX API)
 * Požaduje přihlášení administrátora.
 *
 * POST parametry:
 *   action: delete | set_category | remove_category | set_color | set_favorite | unset_favorite
 *   ids[]: pole ID tras
 *   value: hodnota (název kategorie / barva)
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';

header('Content-Type: application/json; charset=utf-8');

// CSRF kontrola
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Neplatný CSRF token.']);
    exit;
}

$action = $_POST['action'] ?? '';
$ids    = $_POST['ids'] ?? [];
$value  = trim($_POST['value'] ?? '');

if (!is_array($ids) || empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'Žádné trasy nevybrány.']);
    exit;
}

$ids = array_map('intval', $ids);
$ids = array_filter($ids, fn($id) => $id > 0);

if (empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'Neplatná ID.']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$affected = 0;

try {
    switch ($action) {

        case 'delete':
            // Smazat soubory z disku
            $stmt = $pdo->prepare("SELECT id, filename FROM tracks WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $toDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();

            // Smazat kategorie
            $pdo->prepare("DELETE FROM track_categories WHERE track_id IN ($placeholders)")->execute($ids);

            // Smazat trasy z DB
            $delStmt = $pdo->prepare("DELETE FROM tracks WHERE id IN ($placeholders)");
            $delStmt->execute($ids);
            $affected = $delStmt->rowCount();

            $pdo->commit();

            // Smazat soubory
            foreach ($toDelete as $t) {
                $gpxFile = uploads_fs($t['filename']);
                if (is_file($gpxFile)) @unlink($gpxFile);

                $thumbFile = uploads_fs('thumbs/' . pathinfo($t['filename'], PATHINFO_FILENAME) . '.png');
                if (is_file($thumbFile)) @unlink($thumbFile);
            }
            break;

        case 'set_category':
            if ($value === '') {
                echo json_encode(['success' => false, 'error' => 'Název kategorie je povinný.']);
                exit;
            }

            // Vytvořit kategorii pokud neexistuje
            $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)")->execute([$value]);
            $catSel = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $catSel->execute([$value]);
            $catId = (int)$catSel->fetchColumn();

            if ($catId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Nelze vytvořit kategorii.']);
                exit;
            }

            // Přiřadit kategorii ke všem vybraným trasám (ignorovat duplicity)
            $insertStmt = $pdo->prepare("INSERT IGNORE INTO track_categories (track_id, category_id) VALUES (?, ?)");
            foreach ($ids as $id) {
                $insertStmt->execute([$id, $catId]);
                $affected++;
            }
            break;

        case 'remove_category':
            if ($value === '') {
                echo json_encode(['success' => false, 'error' => 'Název kategorie je povinný.']);
                exit;
            }

            $catSelR = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $catSelR->execute([$value]);
            $catId = (int)$catSelR->fetchColumn();
            if ($catId > 0) {
                $stmt = $pdo->prepare("DELETE FROM track_categories WHERE track_id IN ($placeholders) AND category_id = ?");
                $stmt->execute(array_merge($ids, [$catId]));
                $affected = $stmt->rowCount();
            }
            break;

        case 'set_color':
            $stmt = $pdo->prepare("UPDATE tracks SET color = ? WHERE id IN ($placeholders)");
            $stmt->execute(array_merge([$value], $ids));
            $affected = $stmt->rowCount();
            break;

        case 'set_favorite':
            $stmt = $pdo->prepare("UPDATE tracks SET is_favorite = 1 WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $affected = $stmt->rowCount();
            break;

        case 'unset_favorite':
            $stmt = $pdo->prepare("UPDATE tracks SET is_favorite = 0 WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $affected = $stmt->rowCount();
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Neznámá akce: ' . $action]);
            exit;
    }

    echo json_encode([
        'success'  => true,
        'action'   => $action,
        'affected' => $affected,
        'message'  => "Akce '$action' provedena pro $affected tras.",
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("api_bulk_action.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => APP_ENV === 'local' ? $e->getMessage() : 'Interní chyba serveru.',
    ]);
}
