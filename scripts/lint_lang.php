<?php
declare(strict_types=1);

/**
 * i18n key consistency checker.
 *
 * Usage: php scripts/lint_lang.php
 *
 * Reference language: first file in alphabetical order (cs.php).
 * Fails with exit code 1 if any language file has missing or extra keys
 * compared to the reference. Prints a diff to stderr.
 *
 * Also fails when code calls t('key') / t_arr('key') with a key missing in the
 * reference file (missing everywhere = invisible to the per-language diff).
 *
 * Covers: QR-12 (i18n drift), OPS-02 (CI gate).
 */

$langDir = __DIR__ . '/../lang';
$files   = glob($langDir . '/*.php');

if (!$files) {
    fwrite(STDERR, "ERROR: No lang files found in $langDir\n");
    exit(1);
}

sort($files);

$allKeys = [];
foreach ($files as $f) {
    $strings = require $f;
    if (!is_array($strings)) {
        fwrite(STDERR, "ERROR: $f did not return an array\n");
        exit(1);
    }
    $allKeys[basename($f)] = array_keys($strings);
}

$refName = array_key_first($allKeys);
$reference = $allKeys[$refName];
$hasErrors = false;

foreach ($allKeys as $name => $keys) {
    if ($name === $refName) {
        continue;
    }

    $missing = array_diff($reference, $keys);
    $extra   = array_diff($keys, $reference);

    if ($missing) {
        fwrite(STDERR, "[$name] missing keys (vs $refName): " . implode(', ', array_values($missing)) . "\n");
        $hasErrors = true;
    }

    if ($extra) {
        fwrite(STDERR, "[$name] extra keys (vs $refName): " . implode(', ', array_values($extra)) . "\n");
        $hasErrors = true;
    }
}

// ── Keys used in code but missing in the lang files ──────────────────────
// The per-language comparison above cannot see a key that is missing in ALL
// files at once — t() then silently shows its Czech default (or the raw key,
// like "h2_elevation" on every track detail until 10/2026). Scan the code for
// t('key') / t_arr('key') with a literal key; keys built at runtime
// (t('lang_' . $code)) are skipped because the literal is followed by a dot.
$appRoot = realpath(__DIR__ . '/..');
$skipDir = '#[\\\\/](vendor|node_modules|_archiv|lang|\.git|\.claude)[\\\\/]#';
$callRe  = '/(?<![\w>$:])t(?:_arr)?\(\s*([\'"])([A-Za-z0-9_.\-]+)\1\s*[,)]/';
$refSet  = array_flip($reference);
$unknown = [];
$codeFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS));
foreach ($codeFiles as $file) {
    $path = $file->getPathname();
    if (substr($path, -4) !== '.php' || preg_match($skipDir, $path) || realpath($path) === __FILE__) {
        continue;
    }
    if (!preg_match_all($callRe, (string)file_get_contents($path), $m)) {
        continue;
    }
    foreach ($m[2] as $key) {
        if (!isset($refSet[$key])) {
            $unknown[$key][] = str_replace('\\', '/', substr($path, strlen($appRoot) + 1));
        }
    }
}
if ($unknown) {
    ksort($unknown);
    foreach ($unknown as $key => $where) {
        fwrite(STDERR, "[code] key '$key' is used but missing in $refName: " . implode(', ', array_unique($where)) . "\n");
    }
    $hasErrors = true;
}

if ($hasErrors) {
    fwrite(STDERR, "\ni18n key drift detected. Fix missing keys in the affected lang files.\n");
    exit(1);
}

$count = count($allKeys);
echo "i18n key sets consistent across $count languages; all keys used in code exist.\n";
exit(0);
