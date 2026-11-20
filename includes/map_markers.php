<?php
declare(strict_types=1);

/**
 * Značky s poznámkou na stránce Mapa (tabulka map_markers, migrace 0021).
 * Sdílí api/markers/list.php, save.php a delete.php.
 */

/** Povolené symboly: klíč v databázi => emoji na mapě. */
function map_marker_symbols(): array
{
    return [
        'pin'      => '📍',
        'star'     => '⭐',
        'question' => '❓',
        'ruin'     => '🏚️',
        'water'    => '💧',
        'cross'    => '✝️',
        'camera'   => '📷',
        'flag'     => '🚩',
    ];
}

/** Řádek z databáze → JSON pro klienta. */
function map_marker_out(array $r): array
{
    return [
        'id'       => (int)$r['id'],
        'lat'      => (float)$r['lat'],
        'lon'      => (float)$r['lon'],
        'title'    => (string)$r['title'],
        'note'     => (string)($r['note'] ?? ''),
        'symbol'   => isset(map_marker_symbols()[$r['symbol']]) ? (string)$r['symbol'] : 'pin',
        'isPublic' => (int)$r['is_public'] === 1,
    ];
}
