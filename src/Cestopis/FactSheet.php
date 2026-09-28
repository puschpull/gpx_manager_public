<?php
declare(strict_types=1);

namespace GpxManager\Cestopis;

/**
 * Sestaví seznam ověřených faktů, který dostane jazykový model.
 *
 * Tohle je jádro celé věci. Model nedostane surová data ani volnost —
 * dostane uzavřený seznam tvrzení a instrukci, že smí použít jen je.
 * Co tady není, o tom nesmí psát.
 *
 * Záměrně tu NEJSOU výšky z fotek: telefon je měří barometrem a v reálných
 * datech se liší od GPS přístroje o desítky až stovky metrů. Kdyby je model
 * dostal, napsal by o stoupání, které se nestalo.
 */
final class FactSheet
{
    /**
     * @param array<string,mixed> $track řádek z tabulky tracks
     * @param list<Stop> $stops
     * @param list<string> $categories
     * @param array<int, list<array{nazev:string, druh:string, vzdalenost_m:int}>> $pois index zastávky → objekty z mapy (PoiFinder)
     * @param list<array{stop:int, photo:Photo}> $photos fotky, které dostane model (PhotoPicker)
     * @return array<string,mixed>
     */
    public function build(array $track, array $stops, array $categories = [], array $pois = [], array $photos = []): array
    {
        $withMap = $pois !== [];
        $withPhotos = $photos !== [];

        $notes = [
            'Údaj cas_ve_stani pochází z GPS přístroje (měří podle rychlosti). '
                . 'Součet trvání zastávek níže je počítaný z časů fotek a bývá vyšší — '
                . 'jsou to dvě různá měřítka. NIKDY je nesčítej ani neporovnávej jako rozpor.',
            'Zastávka s trvani_minut = 0 znamená, že fotky (počet je v pocet_fotek) vznikly během '
                . 'jedné minuty — ne jediný snímek a ne že se tam nic nedělo.',
            'Nic nesčítej ani neporovnávej sám. Počty celkem a to, co bylo „nejdelší" nebo „nejvíc", '
                . 'ber jen ze sekce souhrn; jiné superlativy nepoužívej.',
        ];
        if ($withMap) {
            $notes[] = 'Položky v_okoli jsou pojmenované objekty z mapy OpenStreetMap do '
                . PoiFinder::RADIUS_M . ' m od zastávky. Že je objekt poblíž, NEZNAMENÁ, že jsme ho '
                . 'navštívili nebo viděli — piš „nedaleko", „kousek od", ne „prohlédli jsme si". '
                . 'Vzdálenost je jen přibližná.';
        }
        if ($withPhotos) {
            $notes[] = 'K zastávkám uvedeným v prilozene_fotky jsou přiloženy fotky (vždy s označením '
                . 'zastávky a času). Ostatní fotky model nevidí.';
        }

        $missing = [
            $withPhotos
                ? 'Fotky nemají popisky. Víme jen to, co je vidět na přiložených snímcích; o ostatních nevíme nic.'
                : 'Fotky nemají popisky — o jejich obsahu nevíme nic.',
            'Výšky z fotek jsou z telefonu a neodpovídají GPS přístroji, proto tu nejsou.',
            'Nevíme, s kým se šlo, ani co se cestou dělo mimo uvedené časy'
                . ($withPhotos ? '.' : '; nevíme ani, jaké bylo počasí.'),
            'Názvy míst pocházejí z veřejné geokódovací služby a mohou být nepřesné.',
        ];

        // Prázdný čas NESMÍ projít do new DateTimeImmutable('') — to je „teď"
        // a model by dostal smyšlené datum. Chybějící údaj = null (model ho nemá).
        $start = self::dt($track['date_start'] ?? null);
        $end = self::dt($track['date_end'] ?? null);

        return [
            'vylet' => [
                'datum' => $start?->format('Y-m-d'),
                'den_v_tydnu' => $start !== null ? self::WEEKDAYS[(int) $start->format('N')] : null,
                'vyrazili_v' => $start?->format('H:i'),
                'skoncili_v' => $end?->format('H:i'),
                'vychozi_misto' => $track['place_name'] ?: null,
                'druh_aktivity' => $track['activity_type'] ?: null,
                'obtiznost_1_az_5' => $track['difficulty'] !== null ? (int) $track['difficulty'] : null,
                'oblasti' => $categories,
                'zarizeni' => $track['device'] ?: null,
                'vlastni_poznamka' => $track['note'] ?: null,
            ],
            'cisla' => [
                'vzdalenost_km' => self::round($track['distance_km'], 1),
                'stoupani_m' => self::round($track['ascent'], 0),
                'klesani_m' => self::round($track['descent'], 0),
                'nejnizsi_bod_m' => self::round($track['elevation_min'], 0),
                'nejvyssi_bod_m' => self::round($track['elevation_max'], 0),
                'celkovy_cas' => self::hm($track['duration']),
                'cas_v_pohybu' => self::hm($track['moving_time']),
                'cas_ve_stani' => self::hm($track['stopped_time']),
                'prumerna_rychlost_kmh' => self::round($track['speed_avg'], 1),
                'nejvyssi_rychlost_kmh' => self::round($track['speed_max'], 1),
            ],
            'zastavky' => $zastavky = $this->stops($stops, $pois),
            'useky_mezi_zastavkami' => $useky = $this->legs($stops),
            // Spočítané předem — model se při sčítání a porovnávání plete
            // (trasa 681: „66 fotek" místo 76, „nejdelší úsek 23 min" místo 30)
            'souhrn' => self::summary($zastavky, $useky),
            'prilozene_fotky' => array_map(static fn(array $p) => [
                'zastavka' => $p['stop'],
                'cas' => $p['photo']->takenAt->format('H:i'),
            ], $photos),
            'pozor_na' => $notes,
            'co_v_datech_NENI' => $missing,
            // Dohledatelnost: z čeho text vznikl (uloženo s cestopisem ve facts_json)
            'zdroje_faktu' => [
                'mapa_openstreetmap' => $withMap,
                'fotky_pro_model' => count($photos),
            ],
        ];
    }

    /**
     * @param list<Stop> $stops
     * @return list<array<string,mixed>>
     */
    private function stops(array $stops, array $pois = []): array
    {
        $out = [];

        foreach ($stops as $i => $stop) {
            $row = [
                'poradi' => $i + 1,
                'od' => $stop->startAt->format('H:i'),
                'do' => $stop->endAt->format('H:i'),
                'trvani_minut' => (int) round($stop->durationSeconds() / 60),
                'pocet_fotek' => $stop->photoCount(),
                'delsi_zastaveni' => $stop->isRest(),
                'misto' => $stop->placeName,
                'souradnice' => $stop->lat !== null
                    ? sprintf('%.5f, %.5f', $stop->lat, $stop->lon)
                    : null,
            ];
            if (!empty($pois[$i])) {
                $row['v_okoli'] = $pois[$i];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<Stop> $stops
     * @return list<array<string,mixed>>
     */
    private function legs(array $stops): array
    {
        $out = [];

        for ($i = 0; $i < count($stops) - 1; $i++) {
            $from = $stops[$i];
            $to = $stops[$i + 1];

            $seconds = $to->startAt->getTimestamp() - $from->endAt->getTimestamp();
            $meters = $from->lat !== null && $to->lat !== null
                ? Geo::distanceMeters($from->lat, $from->lon, $to->lat, $to->lon)
                : null;

            $out[] = [
                'ze_zastavky' => $i + 1,
                'na_zastavku' => $i + 2,
                'trvani_minut' => (int) round($seconds / 60),
                'vzdusnou_carou_m' => $meters !== null ? (int) round($meters) : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $zastavky
     * @param list<array<string,mixed>> $useky
     * @return array<string,mixed>
     */
    private static function summary(array $zastavky, array $useky): array
    {
        $max = static function (array $rows, string $key): ?array {
            $best = null;
            foreach ($rows as $r) {
                if ($best === null || $r[$key] > $best[$key]) {
                    $best = $r;
                }
            }
            return $best;
        };
        $stop = $max($zastavky, 'trvani_minut');
        $photos = $max($zastavky, 'pocet_fotek');
        $leg = $max($useky, 'trvani_minut');

        return [
            'pocet_zastavek' => count($zastavky),
            'pocet_fotek_celkem' => array_sum(array_column($zastavky, 'pocet_fotek')),
            'nejdelsi_zastaveni' => $stop === null ? null
                : ['zastavka' => $stop['poradi'], 'od' => $stop['od'], 'trvani_minut' => $stop['trvani_minut']],
            'nejvic_fotek' => $photos === null ? null
                : ['zastavka' => $photos['poradi'], 'od' => $photos['od'], 'pocet_fotek' => $photos['pocet_fotek']],
            'nejdelsi_usek_chuze' => $leg === null ? null
                : ['ze_zastavky' => $leg['ze_zastavky'], 'na_zastavku' => $leg['na_zastavku'], 'trvani_minut' => $leg['trvani_minut']],
        ];
    }

    private static function dt(mixed $value): ?\DateTimeImmutable
    {
        return ($value === null || $value === '') ? null : new \DateTimeImmutable((string) $value);
    }

    private static function round(mixed $value, int $precision): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }

    /** Chybějící údaj zůstane null — „0 h 00 min" by byl vymyšlený údaj. */
    private static function hm(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $seconds = (int) $value;

        return sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    private const WEEKDAYS = [
        1 => 'pondělí', 2 => 'úterý', 3 => 'středa', 4 => 'čtvrtek',
        5 => 'pátek', 6 => 'sobota', 7 => 'neděle',
    ];
}
