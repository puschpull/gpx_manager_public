<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  format.php — Output formatting helpers
 *  HTML escaping, unit conversion, number/duration formatting.
 * ===========================================================
 */

/**
 * HTML-escapes a string for safe output inside HTML.
 */
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Safe JSON for inline <script> tags — prevents </script> injection
 * and HTML breakout via < > & " '.
 * Use ALWAYS instead of json_encode() inside <script>…</script> blocks.
 * Not needed on AJAX endpoints with Content-Type: application/json.
 */
function js_safe_json(mixed $value): string {
    return json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE
    );
}

/**
 * Returns the current unit system ('metric' or 'imperial').
 */
function app_units(): string {
    static $units = null;
    if ($units === null) {
        $units = $_COOKIE['app_units'] ?? 'metric';
        if (!in_array($units, ['metric', 'imperial'])) $units = 'metric';
    }
    return $units;
}

/**
 * Formats a distance value (km → mi for imperial).
 */
function fmtDist(?float $km, int $decimals = 1): string {
    if ($km === null) return '—';
    if (app_units() === 'imperial') {
        return number_format($km * 0.621371, $decimals, ',', ' ') . ' mi';
    }
    return number_format($km, $decimals, ',', ' ') . ' km';
}

/**
 * Formats an elevation / ascent value (m → ft for imperial).
 */
function fmtElev(?float $m, int $decimals = 0): string {
    if ($m === null) return '—';
    if (app_units() === 'imperial') {
        return number_format($m * 3.28084, $decimals, ',', ' ') . ' ft';
    }
    return number_format($m, $decimals, ',', ' ') . ' m';
}

/**
 * Formats a speed value (km/h → mph for imperial).
 */
function fmtSpeed(?float $kmh, int $decimals = 1): string {
    if ($kmh === null) return '—';
    if (app_units() === 'imperial') {
        return number_format($kmh * 0.621371, $decimals, ',', ' ') . ' mph';
    }
    return number_format($kmh, $decimals, ',', ' ') . ' km/h';
}

/**
 * Formats a duration in seconds to H:MM:SS string.
 */
function formatSecondsToHMS(?int $seconds): string {
    if ($seconds === null) {
        return '';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%d:%02d:%02d', $h, $m, $s);
}
