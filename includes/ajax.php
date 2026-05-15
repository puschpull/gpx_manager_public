<?php
declare(strict_types=1);

/**
 * ajax_endpoint() — central wrapper for JSON API endpoints.
 *
 * Handles:
 *  - Content-Type + nosniff headers
 *  - Optional admin gate (403 JSON on failure)
 *  - Optional CSRF verification (403 JSON on failure)
 *  - try/catch with structured error logging
 *  - APP_ENV=local exposes exception message; production returns generic string
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/ajax.php';
 *   ajax_endpoint(function () use ($pdo): array {
 *       // ... business logic ...
 *       return ['ok' => true];
 *   }, ['csrf' => true, 'admin' => true, 'name' => 'photos/delete']);
 */
function ajax_endpoint(callable $handler, array $opts = []): void
{
    $requireCsrf  = $opts['csrf']  ?? true;
    $requireAdmin = $opts['admin'] ?? false;
    $endpoint     = $opts['name']  ?? basename($_SERVER['SCRIPT_NAME'] ?? 'unknown');

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    try {
        if ($requireAdmin && empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        if ($requireCsrf && !csrf_verify()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'CSRF']);
            return;
        }

        $result = $handler();
        echo json_encode($result, JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        error_log(
            '[AJAX ' . $endpoint . '] ' . $e->getMessage()
            . ' at ' . $e->getFile() . ':' . $e->getLine()
        );
        http_response_code(500);
        $message = (defined('APP_ENV') && APP_ENV === 'local')
            ? $e->getMessage()
            : 'Internal error';
        echo json_encode(['ok' => false, 'error' => $message]);
    }
}
