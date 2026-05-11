<?php
/**
 * API endpoint: přepne is_favorite u trasy (AJAX)
 * POST: id = track ID
 * Vrací JSON: { success, is_favorite }
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE tracks SET is_favorite = NOT is_favorite WHERE id = ?");
    $stmt->execute([$id]);

    $stmt2 = $pdo->prepare("SELECT is_favorite FROM tracks WHERE id = ?");
    $stmt2->execute([$id]);
    $val = (int)$stmt2->fetchColumn();

    echo json_encode(['success' => true, 'is_favorite' => $val]);
} catch (Throwable $e) {
    error_log("api_toggle_favorite.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => APP_ENV === 'local' ? $e->getMessage() : 'Interní chyba serveru.']);
}
