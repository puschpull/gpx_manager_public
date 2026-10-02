<?php
declare(strict_types=1);

namespace GpxManager\Cestopis;

use PDO;

/**
 * Najde pojmenované turistické body z OpenStreetMap v okolí zastávek.
 *
 * Proč: model jinak ví jen název obce. S mapou ví, že dlouhá zastávka byla
 * 80 m od vyhlídky — to je ověřitelný fakt z mapy, ne dohad. Stejný zdroj
 * (Overpass / OSM) používá aplikace pro vrstvu „Turistické body".
 *
 * Pravidla, která drží fakta poctivá:
 *  - jen objekty se jménem (bezejmenná skála modelu nic neřekne),
 *  - jen vyjmenované druhy (lavičky, koše a parkoviště nejsou zážitek),
 *  - objekt patří k NEJBLIŽŠÍ zastávce a jen do RADIUS_M — „u vyhlídky"
 *    nesmí znamenat 400 m daleko,
 *  - že je objekt poblíž, neznamená, že jsme u něj byli (říká to FactSheet).
 *
 * Jeden dotaz na obdélník kolem celé trasy s přesnými druhy objektů (jako
 * vrstva v aplikaci) — pro server lehký; vzdálenost od zastávek se počítá až
 * tady. Cache 30 dní, při přetížení opakování a záložní server. Overpass je
 * služba zdarma s přísnými limity.
 */
final class PoiFinder
{
    public const RADIUS_M = 150;
    private const CACHE_TTL_DAYS = 30;
    /** Hlavní server a záloha (hlavní bývá přetížený — 504/429). */
    private const ENDPOINTS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];

    /** Druhy objektů, které stojí za zmínku: [klíč, hodnota, český název]. */
    private const KINDS = [
        ['tourism', 'viewpoint', 'vyhlídka'],
        ['natural', 'peak', 'vrchol'],
        ['natural', 'rock', 'skála'],
        ['natural', 'stone', 'balvan'],
        ['natural', 'cliff', 'skalní stěna'],
        ['natural', 'cave_entrance', 'jeskyně'],
        ['natural', 'spring', 'pramen'],
        ['natural', 'arch', 'skalní brána'],
        ['historic', 'castle', 'hrad / zámek'],
        ['historic', 'ruins', 'zřícenina'],
        ['historic', 'archaeological_site', 'archeologické naleziště'],
        ['historic', 'memorial', 'pamětní místo'],
        ['historic', 'monument', 'památník'],
        ['historic', 'wayside_cross', 'křížek'],
        ['historic', 'wayside_shrine', 'boží muka'],
        ['man_made', 'tower', 'věž'],
        ['amenity', 'shelter', 'přístřešek'],
        ['tourism', 'attraction', 'turistický cíl'],
        ['tourism', 'artwork', 'umělecké dílo'],
    ];

    private int $failures = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $userAgent,
        private readonly string $caBundle = '',
    ) {}

    /**
     * @param list<Stop> $stops
     * @return array<int, list<array{nazev:string, druh:string, vzdalenost_m:int}>> index zastávky → objekty
     */
    public function find(array $stops): array
    {
        $points = [];
        foreach ($stops as $i => $stop) {
            if ($stop->lat !== null && $stop->lon !== null) {
                $points[$i] = [$stop->lat, $stop->lon];
            }
        }
        if ($points === []) {
            return [];
        }

        $elements = $this->query($this->buildQuery($points));
        if ($elements === null) {
            Log::warn('POZOR: mapové body z OpenStreetMap se nepodařilo stáhnout — cestopis bude bez nich.');
            return [];
        }

        $byStop = [];
        $seen = [];
        foreach ($elements as $el) {
            $tags = $el['tags'] ?? [];
            $name = trim((string) ($tags['name'] ?? ''));
            $kind = $this->kind($tags);
            $lat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $lon = $el['lon'] ?? $el['center']['lon'] ?? null;
            if ($name === '' || $kind === null || $lat === null || $lon === null) {
                continue;
            }

            // Nejbližší zastávka v dosahu
            $best = null;
            $bestDist = INF;
            foreach ($points as $i => [$pLat, $pLon]) {
                $d = Geo::distanceMeters($pLat, $pLon, (float) $lat, (float) $lon);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $best = $i;
                }
            }
            if ($best === null || $bestDist > self::RADIUS_M) {
                continue;
            }

            // Tentýž objekt bývá v OSM jako bod i jako plocha — jen jednou
            $key = $best . '|' . mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $byStop[$best][] = [
                'nazev' => $name,
                'druh' => $kind,
                'vzdalenost_m' => (int) (round($bestDist / 10) * 10),
            ];
        }

        foreach ($byStop as &$list) {
            usort($list, static fn(array $a, array $b) => $a['vzdalenost_m'] <=> $b['vzdalenost_m']);
        }
        unset($list);
        ksort($byStop);

        return $byStop;
    }

    /** @param array<int, array{float,float}> $points */
    private function buildQuery(array $points): string
    {
        // Obdélník kolem všech zastávek + okraj RADIUS_M, zaokrouhlený ven na
        // 0,005° (stejný dotaz pro stejnou trasu → trefí cache).
        $lats = array_column($points, 0);
        $lons = array_column($points, 1);
        $padLat = self::RADIUS_M / 111_000;
        $padLon = self::RADIUS_M / (111_000 * cos(deg2rad(array_sum($lats) / count($lats))));
        $grid = 0.005;
        $s = floor((min($lats) - $padLat) / $grid) * $grid;
        $w = floor((min($lons) - $padLon) / $grid) * $grid;
        $n = ceil((max($lats) + $padLat) / $grid) * $grid;
        $e = ceil((max($lons) + $padLon) / $grid) * $grid;
        $bbox = sprintf('%.3f,%.3f,%.3f,%.3f', $s, $w, $n, $e);

        $parts = [];
        foreach (self::KINDS as [$k, $v]) {
            $parts[] = sprintf('nwr["%s"="%s"]["name"](%s);', $k, $v, $bbox);
        }

        return '[out:json][timeout:40];(' . implode('', $parts) . ');out center tags;';
    }

    /** @param array<string,string> $tags */
    private function kind(array $tags): ?string
    {
        foreach (self::KINDS as [$k, $v, $label]) {
            if (($tags[$k] ?? null) === $v) {
                // Věž jen rozhledna, ne vysílač
                if ($k === 'man_made' && ($tags['tower:type'] ?? '') !== 'observation') {
                    continue;
                }
                return $label;
            }
        }
        return null;
    }

    /**
     * Dotaz na Overpass s cache a záložním serverem (používá i CorridorFinder).
     * @return list<array<string,mixed>>|null  null = služba selhala
     */
    public function query(string $query): ?array
    {
        $key = md5($query);
        $stmt = $this->pdo->prepare(
            'SELECT payload FROM story_poi_cache WHERE cache_key = ? AND created_at > NOW() - INTERVAL ' . self::CACHE_TTL_DAYS . ' DAY'
        );
        $stmt->execute([$key]);
        $hit = $stmt->fetchColumn();
        if ($hit !== false) {
            $data = json_decode((string) $hit, true);
            if (is_array($data)) {
                return $data['elements'] ?? [];
            }
        }

        // Každý server nejvýš dvakrát; přetížení (429/5xx) = chvíli počkat
        $body = false;
        $data = null;
        foreach (self::ENDPOINTS as $endpoint) {
            for ($try = 1; $try <= 2; $try++) {
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query(['data' => $query]),
                    CURLOPT_TIMEOUT => 60,
                    CURLOPT_USERAGENT => $this->userAgent,
                ]);
                if ($this->caBundle !== '') {
                    curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
                }
                $body = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                $data = $body === false ? null : json_decode((string) $body, true);
                if ($status === 200 && is_array($data)) {
                    break 2;
                }
                $this->failures++;
                Log::warn(sprintf('Overpass (%s): %s', (string) parse_url($endpoint, PHP_URL_HOST),
                    $body === false ? $error : "HTTP {$status}"));
                $data = null;
                if ($try < 2) {
                    sleep(5);
                }
            }
        }
        if ($data === null) {
            return null;   // selhání se necachuje
        }

        $this->pdo->prepare(
            'REPLACE INTO story_poi_cache (cache_key, payload) VALUES (?, ?)'
        )->execute([$key, (string) $body]);

        return $data['elements'] ?? [];
    }
}
