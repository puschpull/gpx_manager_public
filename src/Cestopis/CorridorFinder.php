<?php

declare(strict_types=1);

namespace GpxManager\Cestopis;

/**
 * Místa podél CELÉ trasy — podklad pro místopisný cestopis.
 *
 * PoiFinder hledá body jen u zastávek (kde se fotilo). Místopisný text ale
 * vypráví o krajině, kudy trasa vede: zřícenina, kolem které se šlo, obec
 * o kus stranou, potok v údolí. Tady se proto prochází čára trasy z GPX
 * a hledají se pojmenované objekty do CORRIDOR_M od ní (obce do PLACE_M,
 * chráněná území a toky bez limitu — jsou velké a jejich „střed“ bývá daleko).
 *
 * Výsledek je seznam KANDIDÁTŮ seřazený podle průchodu trasou, s odkazem na
 * Wikipedii / Wikidata, kde ho OSM má. Co z toho stojí za zmínku a co je
 * o místě pravda, se dohledává ve zdrojích — tahle třída nic nepopisuje.
 * Zdarma: jeden dotaz na Overpass (cache 30 dní přes PoiFinder::query).
 */
final class CorridorFinder
{
    public const CORRIDOR_M = 400;
    public const PLACE_M = 1200;

    /** Druhy objektů: regulární výraz hodnoty pro klíč OSM. */
    private const KINDS = [
        'historic' => '.',
        'natural' => 'peak|spring|cave_entrance|rock|stone|cliff|water|tree|valley|ridge|arch|saddle',
        'tourism' => 'viewpoint|attraction|artwork|museum',
        'amenity' => 'place_of_worship',
        'man_made' => 'tower|cross|water_well|mineshaft|adit',
        'leisure' => 'nature_reserve',
        'boundary' => 'protected_area',
        'water' => 'pond|lake|reservoir',
        'waterway' => 'river|stream',
    ];

    public function __construct(private readonly PoiFinder $overpass) {}

    /**
     * @param list<array{0:float,1:float}> $points body trasy [lat, lon]
     * @return list<array{km:float, vzdalenost_m:int, nazev:string, druh:string, wikipedia:?string, wikidata:?string}>
     */
    public function find(array $points): array
    {
        if (count($points) < 2) {
            return [];
        }
        // Zředit na ~25 m a spočítat ujetou vzdálenost ke každému bodu
        $line = [$points[0]];
        $cum = [0.0];
        foreach ($points as $p) {
            $d = Geo::distanceMeters($line[array_key_last($line)][0], $line[array_key_last($line)][1], $p[0], $p[1]);
            if ($d >= 25) {
                $line[] = $p;
                $cum[] = $cum[array_key_last($cum)] + $d;
            }
        }

        $elements = $this->overpass->query($this->buildQuery($line));
        if ($elements === null) {
            Log::warn('POZOR: místa z OpenStreetMap se nepodařilo stáhnout.');
            return [];
        }

        $best = [];
        foreach ($elements as $el) {
            $tags = $el['tags'] ?? [];
            $name = trim((string) ($tags['name'] ?? ''));
            $lat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $lon = $el['lon'] ?? $el['center']['lon'] ?? null;
            if ($name === '' || $lat === null || $lon === null) {
                continue;
            }
            $kind = $this->kind($tags);
            $isPlace = isset($tags['place']);
            $isBig = ($tags['boundary'] ?? '') === 'protected_area' || ($tags['leisure'] ?? '') === 'nature_reserve'
                || isset($tags['waterway']);

            $near = 0;
            $dist = INF;
            foreach ($line as $i => [$pLat, $pLon]) {
                $d = Geo::distanceMeters($pLat, $pLon, (float) $lat, (float) $lon);
                if ($d < $dist) {
                    $dist = $d;
                    $near = $i;
                }
            }
            $limit = $isPlace ? self::PLACE_M : ($isBig ? INF : self::CORRIDOR_M);
            if ($dist > $limit) {
                continue;
            }
            // Tentýž objekt bývá v OSM jako bod i jako plocha — nechat bližší
            $key = mb_strtolower($name);
            if (isset($best[$key]) && $best[$key]['vzdalenost_m'] <= $dist) {
                continue;
            }
            $best[$key] = [
                'km' => round($cum[$near] / 1000, 2),
                'vzdalenost_m' => (int) (round($dist / 10) * 10),
                'nazev' => $name,
                'druh' => $kind,
                'wikipedia' => isset($tags['wikipedia']) ? (string) $tags['wikipedia'] : null,
                'wikidata' => isset($tags['wikidata']) ? (string) $tags['wikidata'] : null,
            ];
        }

        $out = array_values($best);
        usort($out, static fn(array $a, array $b) => $a['km'] <=> $b['km']);
        return $out;
    }

    /** @param list<array{0:float,1:float}> $line */
    private function buildQuery(array $line): string
    {
        // Obdélník kolem trasy + okraj pro obce, zaokrouhlený ven (trefí cache)
        $lats = array_column($line, 0);
        $lons = array_column($line, 1);
        $padLat = self::PLACE_M / 111_000;
        $padLon = self::PLACE_M / (111_000 * cos(deg2rad(array_sum($lats) / count($lats))));
        $grid = 0.005;
        $bbox = sprintf('%.3f,%.3f,%.3f,%.3f',
            floor((min($lats) - $padLat) / $grid) * $grid, floor((min($lons) - $padLon) / $grid) * $grid,
            ceil((max($lats) + $padLat) / $grid) * $grid, ceil((max($lons) + $padLon) / $grid) * $grid);

        $parts = [];
        foreach (self::KINDS as $k => $re) {
            $parts[] = sprintf('nwr["name"]["%s"~"%s"](%s);', $k, $re, $bbox);
        }
        $parts[] = sprintf('node["name"]["place"~"village|hamlet|town|isolated_dwelling|locality"](%s);', $bbox);
        $parts[] = sprintf('nwr["name"]["wikidata"](%s);', $bbox);

        return '[out:json][timeout:60];(' . implode('', $parts) . ');out center tags;';
    }

    /** @param array<string,string> $tags */
    private function kind(array $tags): string
    {
        foreach (['place', 'historic', 'natural', 'tourism', 'amenity', 'man_made', 'water', 'waterway', 'leisure', 'boundary'] as $k) {
            if (isset($tags[$k])) {
                return $k . '=' . $tags[$k];
            }
        }
        return '?';
    }

    /**
     * Body trasy z GPX souboru (trkpt, případně rtept).
     * @return list<array{0:float,1:float}>
     */
    public static function readGpx(string $path): array
    {
        $xml = @file_get_contents($path);
        if ($xml === false) {
            throw new \RuntimeException("GPX soubor nejde přečíst: {$path}");
        }
        $points = [];
        if (preg_match_all('/<(?:trkpt|rtept)\b([^>]*)>/i', $xml, $m)) {
            foreach ($m[1] as $attrs) {
                if (preg_match('/\blat="([-\d.]+)"/', $attrs, $la) && preg_match('/\blon="([-\d.]+)"/', $attrs, $lo)) {
                    $points[] = [(float) $la[1], (float) $lo[1]];
                }
            }
        }
        return $points;
    }
}
