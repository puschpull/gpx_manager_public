<?php

// Composer autoloader (graceful — aplikace funguje i bez vendor/, dokud uživatel nespustí composer install)
$_autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($_autoload)) {
    require_once $_autoload;
}
unset($_autoload);

date_default_timezone_set('Europe/Prague');

// Load .env file
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Database configuration
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'gpx_manager');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

// Admin credentials
define('ADMIN_USER', $_ENV['ADMIN_USER'] ?? 'admin');
define('ADMIN_PASS_HASH', $_ENV['ADMIN_PASS_HASH'] ?? '');

// Admin IPs (comma-separated, localhost always included)
define('ADMIN_IPS', $_ENV['ADMIN_IPS'] ?? '127.0.0.1,::1');

// API keys (all optional)
define('TF_API_KEY',      $_ENV['TF_API_KEY']      ?? '');
define('MAPYCOM_API_KEY', $_ENV['MAPYCOM_API_KEY']  ?? '');
define('MAPILLARY_TOKEN', $_ENV['MAPILLARY_TOKEN']  ?? '');

// Path to uploads folder
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// Environment detection
$serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
if (in_array($serverAddr, ['127.0.0.1', '::1']) || str_contains($_SERVER['SERVER_NAME'] ?? '', 'local')) {
    define('APP_ENV', 'local');
} else {
    define('APP_ENV', 'production');
}

// Error handling based on environment
error_reporting(E_ALL);

if (APP_ENV === 'local') {
    ini_set('display_errors', 1);
} else {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/logs/errors.log');
}

// Global exception handler — logs uncaught exceptions
set_exception_handler(function (Throwable $e) {
    $msg = sprintf(
        "[%s] UNCAUGHT %s: %s in %s:%d\n%s",
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    error_log($msg);

    if (APP_ENV !== 'local') {
        http_response_code(500);
        // JSON response for AJAX, plain text otherwise
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json')
        ) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Interní chyba serveru.']);
        } else {
            echo '<h1>Chyba serveru</h1><p>Omlouváme se, došlo k neočekávané chybě.</p>';
        }
    } else {
        // Na lokálu ukázat detaily
        throw $e;
    }
});

// Log fatal errors (memory, timeout, parse)
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        error_log(sprintf(
            "[%s] FATAL %s in %s:%d: %s",
            date('Y-m-d H:i:s'),
            match($error['type']) {
                E_ERROR => 'E_ERROR',
                E_CORE_ERROR => 'E_CORE_ERROR',
                E_COMPILE_ERROR => 'E_COMPILE_ERROR',
                E_PARSE => 'E_PARSE',
                default => 'E_' . $error['type'],
            },
            $error['file'],
            $error['line'],
            $error['message']
        ));
    }
});
