<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  activity.php — Difficulty & activity-type helpers
 *  Scoring, labels, and badge rendering for track difficulty
 *  and auto-detected activity type.
 * ===========================================================
 */

/**
 * Calculates route difficulty on a 1–5 scale.
 *
 * Scoring:
 *   1 = Very easy (walk in the park)
 *   2 = Easy
 *   3 = Moderate
 *   4 = Hard
 *   5 = Very hard
 *
 * @param float|null $distance_km   Total distance in km.
 * @param float|null $ascent        Total ascent in metres.
 * @param float|null $elevation_max Highest elevation in metres (reserved for future use).
 * @param float|null $elevation_min Lowest elevation in metres (reserved for future use).
 */
function calculateDifficulty(?float $distance_km, ?float $ascent, ?float $elevation_max, ?float $elevation_min): ?int {
    if ($distance_km === null || $distance_km <= 0) return null;
    if ($ascent === null) $ascent = 0;

    // Points for distance (0–40)
    $distPoints = min(40, $distance_km * 1.5);

    // Points for ascent (0–40)
    $ascentPoints = min(40, $ascent * 0.03);

    // Points for average slope (0–20)
    $slopePoints = 0;
    if ($distance_km > 0 && $ascent > 0) {
        $avgSlope    = ($ascent / ($distance_km * 1000)) * 100; // percentage
        $slopePoints = min(20, $avgSlope * 2);
    }

    $total = $distPoints + $ascentPoints + $slopePoints;

    if ($total <= 15) return 1;
    if ($total <= 30) return 2;
    if ($total <= 50) return 3;
    if ($total <= 70) return 4;
    return 5;
}

/**
 * Returns the human-readable label for a difficulty value.
 */
function difficultyLabel(?int $diff): string {
    return match($diff) {
        1 => 'Velmi snadná',
        2 => 'Snadná',
        3 => 'Středně náročná',
        4 => 'Náročná',
        5 => 'Velmi náročná',
        default => '—',
    };
}

/**
 * Returns an HTML badge (coloured dots) representing track difficulty.
 */
function difficultyBadge(?int $diff): string {
    if ($diff === null) return '<span class="diff-badge diff-0">—</span>';
    $colors = [1 => '#4caf50', 2 => '#8bc34a', 3 => '#ff9800', 4 => '#f44336', 5 => '#9c27b0'];
    $label  = difficultyLabel($diff);
    $dots   = str_repeat('●', $diff) . str_repeat('○', 5 - $diff);
    $color  = $colors[$diff] ?? '#999';
    return "<span class=\"diff-badge diff-{$diff}\" title=\"{$label}\" style=\"color:{$color};\">{$dots}</span>";
}

/**
 * Auto-detects activity type from speed and elevation profile.
 *
 * Uses ACTIVITY_AUTO_THRESHOLD_KMH from constants.php for the 'Auto' cutoff.
 *
 * Returns one of: 'Pěšky', 'Turistika', 'Běh', 'Kolo', 'E-bike', 'Auto', or null.
 */
function detectActivityType(?float $speed_avg, ?float $speed_max, ?float $distance_km, ?float $ascent): ?string {
    if ($speed_avg === null || $speed_avg <= 0) return null;
    if ($distance_km === null || $distance_km <= 0) return null;

    $autoThreshold = defined('ACTIVITY_AUTO_THRESHOLD_KMH') ? ACTIVITY_AUTO_THRESHOLD_KMH : 80;

    // Extremely high max speed → car
    if ($speed_max !== null && $speed_max > $autoThreshold) return 'Auto';

    // Average speed decides the category
    if ($speed_avg > 35) return 'Auto';

    if ($speed_avg >= 12) {
        // Bike vs e-bike: e-bike tends to have higher ascent/km at higher speed
        if ($ascent !== null && $distance_km > 0) {
            $ascentPerKm = $ascent / $distance_km;
            if ($speed_avg >= 15 && $ascentPerKm > 20) return 'E-bike';
        }
        return 'Kolo';
    }

    if ($speed_avg >= 6) {
        if ($ascent !== null && $ascent > 200) return 'Turistika';
        return 'Běh';
    }

    // Slow pace
    if ($ascent !== null && $ascent > 100) return 'Turistika';
    return 'Pěšky';
}

/**
 * Returns the localised display label for a raw activity_type DB value.
 *
 * DB stores Czech string values ('Pěšky', 'Turistika', …) — OPTION A from
 * AUDIT_REPORT.md TASK-25: keep Czech DB values, translate at render time.
 * Covers: QR-11, FE-16.
 */
function activity_type_label(string $val): string {
    // Map raw Czech DB strings → t() lookups so the label is localised.
    $map = [
        'Pěšky'    => t('act_walking',  'Pěšky'),
        'Turistika' => t('act_hiking',   'Turistika'),
        'Běh'       => t('act_running',  'Běh'),
        'Kolo'      => t('act_biking',   'Kolo'),
        'E-bike'    => t('act_ebiking',  'E-bike'),
        'Auto'      => t('act_car',      'Auto'),
    ];
    return $map[$val] ?? $val;
}

/**
 * Returns an HTML badge for a given activity type string.
 */
function activityBadge(?string $type): string {
    if ($type === null || $type === '') return '<span class="activity-badge activity-none">—</span>';
    $icons = [
        'Pěšky'    => '🚶',
        'Turistika' => '🥾',
        'Běh'       => '🏃',
        'Kolo'      => '🚴',
        'E-bike'    => '⚡🚴',
        'Auto'      => '🚗',
    ];
    $icon  = $icons[$type] ?? '❓';
    $label = activity_type_label($type);
    return "<span class=\"activity-badge\" title=\"" . h($label) . "\">{$icon} " . h($label) . "</span>";
}
