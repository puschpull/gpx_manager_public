<?php
declare(strict_types=1);

/**
 * Cestopis — rozmístění fotek do textu (časopisová podoba story.php).
 *
 * Bez modelu a zdarma: text je psaný chronologicky, takže se fotky párují
 * s odstavci podle času. Časy „11:16“ / „12.28“ zmíněné v odstavci určí,
 * o které části výletu odstavec mluví; odstavec bez času převezme odhad
 * od sousedů. Jedna fotka na dva odstavce + jedna úvodní nahoře.
 *
 * Z každé zastávky nejvýš jedna fotka; přednost mají zastávky s víc
 * fotkami a delší zastavení. Popisek jen z uložených faktů (místo, čas,
 * nejbližší bod z mapy) — nic odhadnutého z obsahu fotky.
 */

/** Minuty od půlnoci z „H:MM“ / „HH.MM“, nebo null. */
function story_hm_to_min(string $hm): ?int {
    if (!preg_match('/^(\d{1,2})[:.](\d{2})$/', trim($hm), $m)) {
        return null;
    }
    $h = (int)$m[1]; $i = (int)$m[2];
    return ($h < 24 && $i < 60) ? $h * 60 + $i : null;
}

/**
 * Časové rozpětí každého odstavce [min, max] v minutách, nebo null.
 * Hledá jen časy uvnitř rozpětí výletu, ať „14,7 km“ nebo „1914“
 * nevypadají jako hodiny.
 *
 * @param list<string> $paras
 * @return list<?array{0:int,1:int}>
 */
function story_para_times(array $paras, int $tripFrom, int $tripTo): array {
    $out = [];
    foreach ($paras as $p) {
        $found = [];
        if (preg_match_all('/(?<![\d,.])(\d{1,2})[:.](\d{2})(?![\d,])/u', $p, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $t = story_hm_to_min($m[1] . ':' . $m[2]);
                if ($t !== null && $t >= $tripFrom - 30 && $t <= $tripTo + 30) {
                    $found[] = $t;
                }
            }
        }
        $out[] = $found ? [min($found), max($found)] : null;
    }
    return $out;
}

/**
 * @param list<string> $paras   odstavce textu
 * @param list<array>  $stops   zastávky ze story.php (od, do, misto, okoli, photos, delsi, trvani)
 * @param array<int, array{0:int,1:int}> $dims  [photo_id => [šířka, výška]]
 * @return array{hero: ?array, after: array<int, array>}  after = [index odstavce => fotka za ním]
 */
function story_article_layout(array $paras, array $stops, array $dims): array {
    $layout = ['hero' => null, 'after' => []];
    $stops = array_values(array_filter($stops, static fn($s) => $s['photos'] !== []));
    $n = count($paras);
    if ($stops === [] || $n === 0) {
        return $layout;
    }

    foreach ($stops as &$s) {
        $s['from'] = story_hm_to_min($s['od']) ?? 0;
        $s['to']   = story_hm_to_min($s['do']) ?? $s['from'];
        // Váha zastávky: víc fotek a delší zastavení = zajímavější místo
        $s['score'] = count($s['photos']) + (int)$s['trvani'] / 3 + ($s['delsi'] ? 5 : 0);
    }
    unset($s);
    $tripFrom = $stops[0]['from'];
    $tripTo   = end($stops)['to'];

    $item = static function (array $s) use ($dims): array {
        // Prostřední snímek ze salvy bývá ten „hlavní“
        $p = $s['photos'][intdiv(count($s['photos']), 2)];
        [$w, $h] = $dims[$p->id] ?? [0, 0];
        $near = $s['okoli'][0]['nazev'] ?? null;
        $cap = trim(($s['misto'] ?? '') . ($near ? ', poblíž ' . $near : ''), ', ');
        return [
            'photo'   => $p,
            'caption' => ($cap !== '' ? $cap . ' · ' : '') . $p->takenAt->format('H:i'),
            'orient'  => ($w > 0 && $h > $w) ? 'portrait' : 'landscape',
        ];
    };

    // Úvodní fotka: nejvýraznější zastávka celého výletu
    $heroIdx = 0;
    foreach ($stops as $i => $s) {
        if ($s['score'] > $stops[$heroIdx]['score']) $heroIdx = $i;
    }
    $layout['hero'] = $item($stops[$heroIdx]);
    $used = [$heroIdx => true];

    // Čas odstavců; odstavce bez času dostanou odhad lineárně mezi sousedy
    $times = story_para_times($paras, $tripFrom, $tripTo);
    $mid = [];
    foreach ($times as $i => $t) $mid[$i] = $t ? ($t[0] + $t[1]) / 2 : null;
    $known = array_filter($mid, static fn($v) => $v !== null);
    foreach ($mid as $i => $v) {
        if ($v !== null) continue;
        $prev = null; $next = null;
        foreach ($known as $k => $kv) {
            if ($k < $i) $prev = [$k, $kv];
            if ($k > $i && $next === null) $next = [$k, $kv];
        }
        if ($prev && $next) {
            $mid[$i] = $prev[1] + ($next[1] - $prev[1]) * ($i - $prev[0]) / ($next[0] - $prev[0]);
        } else {
            // Bez opory: rovnoměrně po celém výletě
            $mid[$i] = $tripFrom + ($tripTo - $tripFrom) * ($i + 0.5) / $n;
        }
    }

    // Jedna fotka na dva odstavce: za 2., 4., 6. … odstavcem. Za úplně
    // posledním ne — tam následují čísla a galerie; posune se o odstavec výš.
    for ($k = 1; $k < $n; $k += 2) {
        $at = ($k === $n - 1) ? $k - 1 : $k;
        if ($at < 0 || isset($layout['after'][$at])) continue;

        $range = array_filter([$times[$k - 1] ?? null, $times[$k] ?? null]);
        $lo = $range ? min(array_map(static fn($r) => $r[0], $range)) : null;
        $hi = $range ? max(array_map(static fn($r) => $r[1], $range)) : null;
        $target = ($mid[$k - 1] + $mid[$k]) / 2;

        $best = null; $bestVal = -INF;
        foreach ($stops as $i => $s) {
            if (isset($used[$i])) continue;
            $inside = $lo !== null && $s['to'] >= $lo && $s['from'] <= $hi;
            // Uvnitř rozpětí odstavců rozhoduje váha, jinak blízkost v čase
            $val = $inside ? 1000 + $s['score'] : -abs(($s['from'] + $s['to']) / 2 - $target);
            if ($val > $bestVal) { $bestVal = $val; $best = $i; }
        }
        if ($best === null) break;   // zastávky došly
        $used[$best] = true;
        $layout['after'][$at] = $item($stops[$best]);
    }

    return $layout;
}
