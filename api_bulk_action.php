<?php
declare(strict_types=1);

/**
 * GPX Manager — Hromadné operace (AJAX API)
 * Požaduje přihlášení administrátora.
 *
 * POST parametry:
 *   action: delete | set_category | remove_category | set_color | set_favorite | unset_favorite
 *           | add_collection | remove_collection
 *   ids[]: pole ID tras
 *   value: hodnota (název kategorie / barva / ID kolekce)
 *   new_collection: název nové kolekce (add_collection s value = 'new')
 *   all_filtered = 1 + filter_query: jen u kolekcí — místo označených tras
 *           všechny trasy, které odpovídají filtru (query string stránky)
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

// Kolekce: volitelně všechny vyfiltrované trasy (stejný filtr jako tabulka)
if (in_array($action, ['add_collection', 'remove_collection'], true) && ($_POST['all_filtered'] ?? '') === '1') {
    require_once __DIR__ . '/includes/track_filter.php';
    parse_str((string)($_POST['filter_query'] ?? ''), $fq);
    if (isset($fq['filter_submit'])) {
        $f = buildFilterSQL($fq, 't.');
        $st = $pdo->prepare('SELECT t.id FROM tracks t' . ($f['where'] !== '' ? ' WHERE ' . $f['where'] : ''));
        $st->execute($f['params']);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) {
            echo json_encode(['success' => false, 'error' => 'Žádné trasy nevybrány.']);
            exit;
        }
    }
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

            // Smazat soubory (GPX + .bak, náhled, radarové snímky)
            foreach ($toDelete as $t) {
                delete_track_files((int)$t['id'], (string)$t['filename']);
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

        case 'add_collection':
        case 'remove_collection':
            // value = ID kolekce, nebo 'new' + new_collection = název nové (neveřejné) kolekce
            if ($action === 'add_collection' && $value === 'new') {
                $newName = trim((string)($_POST['new_collection'] ?? ''));
                if ($newName === '' || mb_strlen($newName) > 160) {
                    echo json_encode(['success' => false, 'error' => t('col_name_required', 'Zadejte název kolekce.')]);
                    exit;
                }
                $pdo->prepare('INSERT INTO collections (name, is_public) VALUES (?, 0)')->execute([$newName]);
                $colId = (int)$pdo->lastInsertId();
            } else {
                $colId = (int)$value;
                $ex = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE id = ?');
                $ex->execute([$colId]);
                if ($colId <= 0 || (int)$ex->fetchColumn() === 0) {
                    echo json_encode(['success' => false, 'error' => t('col_not_found', 'Kolekce neexistuje.')]);
                    exit;
                }
            }
            if ($action === 'add_collection') {
                $pos = $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM collection_tracks WHERE collection_id = ?');
                $pos->execute([$colId]);
                $next = (int)$pos->fetchColumn();
                // Řazení podle data, ať nová kolekce nemá trasy v náhodném pořadí
                $ord = $pdo->prepare("SELECT id FROM tracks WHERE id IN ($placeholders) ORDER BY date_start ASC, id ASC");
                $ord->execute($ids);
                $ins = $pdo->prepare('INSERT IGNORE INTO collection_tracks (collection_id, track_id, position) VALUES (?, ?, ?)');
                foreach ($ord->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                    $ins->execute([$colId, (int)$tid, ++$next]);
                    $affected += $ins->rowCount();
                }
            } else {
                $stmt = $pdo->prepare("DELETE FROM collection_tracks WHERE collection_id = ? AND track_id IN ($placeholders)");
                $stmt->execute(array_merge([$colId], $ids));
                $affected = $stmt->rowCount();
            }
            echo json_encode([
                'success'       => true,
                'action'        => $action,
                'affected'      => $affected,
                'collection_id' => $colId,
                'message'       => str_replace('{n}', (string)$affected,
                    t($action === 'add_collection' ? 'col_bulk_added' : 'col_bulk_removed', '{n}')),
            ]);
            exit;

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
