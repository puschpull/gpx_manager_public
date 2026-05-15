<?php
/**
 * migrate.php — Database migration runner for GPX Manager
 *
 * Usage (CLI):  php migrate.php
 * Usage (web):  http://localhost/gpx_manager/migrate.php  (localhost only)
 *
 * - Applies numbered SQL files from migrations/ in order
 * - Tracks applied migrations in schema_migrations table
 * - Uses GET_LOCK for concurrent-run safety
 * - Skips 0001_baseline.sql when tracks table already exists
 * - Idempotent: running twice applies 0 migrations the second time
 */

declare(strict_types=1);

// -------------------------------------------------------
//  Access guard — CLI or localhost only
// -------------------------------------------------------
$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit("403 Forbidden — migrate.php is only accessible from localhost or CLI.\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// -------------------------------------------------------
//  Bootstrap — DB connection only (no auto-migrations)
// -------------------------------------------------------
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    exit("FAILED: DB connection — " . $e->getMessage() . "\n");
}

// -------------------------------------------------------
//  Ensure schema_migrations table exists (bootstrap)
// -------------------------------------------------------
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    migration  VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// -------------------------------------------------------
//  GET_LOCK — prevent concurrent runs
// -------------------------------------------------------
// 30s timeout — long enough for typical production migrations but short
// enough that a stuck lock is noticed. MySQL releases lock automatically
// on session disconnect, so OOM-killed runs do not block forever.
$lock = $pdo->query("SELECT GET_LOCK('gpx_manager_migrate', 30)")->fetchColumn();
if ($lock !== '1' && $lock !== 1) {
    exit("FAILED: Could not acquire migration lock (another migrate.php is running?).\n");
}

// -------------------------------------------------------
//  Collect migration files
// -------------------------------------------------------
$migrations_dir = __DIR__ . '/migrations';
$files = glob($migrations_dir . '/[0-9][0-9][0-9][0-9]_*.sql');
if ($files === false || count($files) === 0) {
    release_lock($pdo);
    exit("No migration files found in migrations/.\n");
}
sort($files);

// -------------------------------------------------------
//  Already-applied set
// -------------------------------------------------------
$applied = $pdo->query("SELECT migration FROM schema_migrations")
               ->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

// -------------------------------------------------------
//  tracks table existence check (for baseline skip logic)
// -------------------------------------------------------
$tracks_exists = table_exists($pdo, 'tracks');

// -------------------------------------------------------
//  Run migrations
// -------------------------------------------------------
$count = 0;

foreach ($files as $path) {
    $name = basename($path);

    // Skip already-applied
    if (isset($applied[$name])) {
        out("SKIP  {$name} (already applied)");
        continue;
    }

    // Skip baseline when tracks table already exists (existing install)
    if ($name === '0001_baseline.sql' && $tracks_exists) {
        out("SKIP  {$name} (tracks table exists — existing install)");
        $pdo->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)")
            ->execute([$name]);
        continue;
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        release_lock($pdo);
        exit("FAILED: Cannot read {$name}\n");
    }

    try {
        // Execute each statement separately (PDO does not support multi-statement by default)
        foreach (split_sql($sql) as $statement) {
            $pdo->exec($statement);
        }
        $pdo->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)")
            ->execute([$name]);
        out("OK    {$name}");
        $count++;
    } catch (PDOException $e) {
        release_lock($pdo);
        exit("FAILED: {$name} — " . $e->getMessage() . "\n");
    }
}

release_lock($pdo);
out("Done. {$count} migration(s) applied.");

// -------------------------------------------------------
//  Helpers
// -------------------------------------------------------

function out(string $msg): void
{
    echo $msg . "\n";
}

function release_lock(PDO $pdo): void
{
    $pdo->query("SELECT RELEASE_LOCK('gpx_manager_migrate')");
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Split a SQL file into individual statements.
 * Handles SET @var, PREPARE, EXECUTE, DEALLOCATE as single tokens.
 * Skips empty lines and SQL comments.
 */
function split_sql(string $sql): array
{
    $statements = [];
    $current    = '';

    foreach (explode("\n", $sql) as $line) {
        $trimmed = trim($line);

        // Skip pure comment lines and empty lines
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }

        $current .= $line . "\n";

        // Statement ends at semicolon
        if (str_ends_with($trimmed, ';')) {
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $current = '';
        }
    }

    // Flush remainder without trailing semicolon (shouldn't happen in well-formed SQL)
    $stmt = trim($current);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }

    return $statements;
}
