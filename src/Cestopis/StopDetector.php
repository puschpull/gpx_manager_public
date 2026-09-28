<?php
declare(strict_types=1);

namespace GpxManager\Cestopis;

/**
 * Rozdělí sled fotek na zastávky.
 *
 * Tři věci, které se u reálných dat ukázaly jako nutné:
 *
 * 1. Fotky chodí v salvách — tři snímky během dvaceti sekund na jednom místě
 *    nejsou tři události, ale jedna.
 *
 * 2. GPS z telefonu občas ustřelí nebo naopak zopakuje starou polohu.
 *    V testovacích datech je dvojice snímků vzdálená 600 m se čtyřsekundovým
 *    odstupem, což odpovídá 540 km/h. Takové souřadnice se zahodí —
 *    fotka zůstane, jen se u ní nevěří poloze.
 *
 * 3. Delší pauza na jednom místě je pořád JEDNA zastávka. Kdyby o rozdělení
 *    rozhodoval jen čas, dvacetiminutová svačina by se rozpadla na dvě
 *    události podle toho, kdy se cvakalo — a právě ta svačina je přitom
 *    to nejzajímavější, co v datech je. Proto se povolený odstup řídí
 *    vzdáleností: stojím-li na místě, můžu stát dlouho.
 */
final class StopDetector
{
    public function __construct(
        private readonly int $maxGapSeconds = 360,
        private readonly int $maxGapSecondsSamePlace = 2400,
        private readonly float $samePlaceMeters = 70.0,
        private readonly float $maxRadiusMeters = 250.0,
        private readonly float $maxPlausibleKmh = 12.0,
        private readonly int $outlierWindow = 3,
    ) {}

    /**
     * @param list<Photo> $photos seřazené podle taken_at vzestupně
     * @return list<Stop>
     */
    public function detect(array $photos): array
    {
        if ($photos === []) {
            return [];
        }

        $this->flagImplausibleCoords($photos);

        $stops = [];
        $current = [];

        foreach ($photos as $photo) {
            if ($current === [] || $this->belongsTo($current, $photo)) {
                $current[] = $photo;
                continue;
            }

            $stops[] = $this->makeStop($current);
            $current = [$photo];
        }

        if ($current !== []) {
            $stops[] = $this->makeStop($current);
        }

        return $stops;
    }

    /**
     * Najde fyzikálně nemožné přeskoky a u každého rozhodne, KTERÝ z obou
     * snímků je ten vadný — porovnáním s mediánem okolního okna. Naivní
     * pravidlo „podezřelý je ten, kde skok začíná" označí i legitimní bod
     * na začátku delšího přesunu.
     *
     * @param list<Photo> $photos
     */
    private function flagImplausibleCoords(array $photos): void
    {
        $n = count($photos);

        for ($i = 0; $i < $n - 1; $i++) {
            $a = $photos[$i];
            $b = $photos[$i + 1];

            if (!$a->hasCoords() || !$b->hasCoords()) {
                continue;
            }

            if ($this->impliedKmh($a, $b) <= $this->maxPlausibleKmh) {
                continue;
            }

            $reference = $this->windowMedian($photos, $i, $n);
            if ($reference === null) {
                continue;
            }

            $distA = Geo::distanceMeters($reference[0], $reference[1], (float) $a->lat, (float) $a->lon);
            $distB = Geo::distanceMeters($reference[0], $reference[1], (float) $b->lat, (float) $b->lon);

            if ($distA > $distB) {
                $a->coordsReliable = false;
            } else {
                $b->coordsReliable = false;
            }
        }
    }

    /**
     * @param list<Photo> $photos
     * @return array{float,float}|null
     */
    private function windowMedian(array $photos, int $centreIndex, int $count): ?array
    {
        $from = max(0, $centreIndex - $this->outlierWindow);
        $to = min($count - 1, $centreIndex + 1 + $this->outlierWindow);

        $points = [];
        for ($j = $from; $j <= $to; $j++) {
            if ($photos[$j]->hasCoords()) {
                $points[] = [(float) $photos[$j]->lat, (float) $photos[$j]->lon];
            }
        }

        return Geo::medianPoint($points);
    }

    private function impliedKmh(Photo $a, Photo $b): float
    {
        $seconds = max(1, abs($b->takenAt->getTimestamp() - $a->takenAt->getTimestamp()));
        $meters = Geo::distanceMeters((float) $a->lat, (float) $a->lon, (float) $b->lat, (float) $b->lon);

        return $meters / $seconds * 3.6;
    }

    /** @param list<Photo> $current */
    private function belongsTo(array $current, Photo $photo): bool
    {
        $last = $current[count($current) - 1];
        $gap = $photo->takenAt->getTimestamp() - $last->takenAt->getTimestamp();
        $centre = $this->centre($current);

        $usable = $centre !== null && $photo->hasCoords() && $photo->coordsReliable;
        $distance = $usable
            ? Geo::distanceMeters($centre[0], $centre[1], (float) $photo->lat, (float) $photo->lon)
            : null;

        // Stojím-li pořád na stejném místě, delší mezera zastávku neukončuje.
        $allowedGap = $distance !== null && $distance <= $this->samePlaceMeters
            ? $this->maxGapSecondsSamePlace
            : $this->maxGapSeconds;

        if ($gap > $allowedGap) {
            return false;
        }

        return $distance === null || $distance <= $this->maxRadiusMeters;
    }

    /**
     * @param list<Photo> $photos
     * @return array{float,float}|null
     */
    private function centre(array $photos): ?array
    {
        $points = [];

        foreach ($photos as $photo) {
            if ($photo->hasCoords() && $photo->coordsReliable) {
                $points[] = [(float) $photo->lat, (float) $photo->lon];
            }
        }

        return Geo::medianPoint($points);
    }

    /** @param list<Photo> $photos */
    private function makeStop(array $photos): Stop
    {
        $centre = $this->centre($photos);

        return new Stop(
            photos: $photos,
            startAt: $photos[0]->takenAt,
            endAt: $photos[count($photos) - 1]->takenAt,
            lat: $centre[0] ?? null,
            lon: $centre[1] ?? null,
        );
    }
}
