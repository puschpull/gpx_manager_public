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

if ($hasErrors) {
    fwrite(STDERR, "\ni18n key drift detected. Fix missing keys in the affected lang files.\n");
    exit(1);
}

$count = count($allKeys);
echo "i18n key sets consistent across $count languages.\n";
exit(0);
