<?php
declare(strict_types=1);

namespace GpxManager\Cestopis;

final class Geo
{
    public const EARTH_RADIUS_M = 6_371_008.8;

    /** Vzdálenost dvou bodů na kouli v metrech. */
    public static function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lon2 - $lon1);

        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    /**
     * Medián souřadnic — odolnější vůči odlehlým hodnotám než průměr,
     * což je u GPS z telefonu podstatné.
     *
     * @param list<array{float,float}> $points
     * @return array{float,float}|null
     */
    public static function medianPoint(array $points): ?array
    {
        if ($points === []) {
            return null;
        }

        $lats = array_column($points, 0);
        $lons = array_column($points, 1);
        sort($lats);
        sort($lons);

        return [self::median($lats), self::median($lons)];
    }

    /** @param list<float> $sorted */
    private static function median(array $sorted): float
    {
        $n = count($sorted);
        $mid = intdiv($n, 2);

        return $n % 2 === 1
            ? $sorted[$mid]
            : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
    }
}
