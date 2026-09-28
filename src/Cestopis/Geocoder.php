<?php
declare(strict_types=1);

namespace GpxManager\Cestopis;

use PDO;

/**
 * Přeloží souřadnice zastávky na název místa přes Nominatim.
 *
 * Nominatim má tvrdá pravidla: nejvýš jeden dotaz za sekundu a povinná
 * identifikace v User-Agent. Proto ta pauza i cache — bez cache by se
 * při každém generování dotazovalo znovu na totéž.
 *
 * @see https://operations.osmfoundation.org/policies/nominatim/
 */
final class Geocoder
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $userAgent,
        private readonly float $precision = 0.0015,
        private readonly int $delayMicroseconds = 1_100_000,
        private readonly string $caBundle = '',
    ) {}

    /** Kolik dotazů služba nezodpověděla (síť, SSL, 429, 5xx) — ty se necachují. */
    private int $failures = 0;
    private string $lastError = '';

    /** @param list<Stop> $stops */
    public function fill(array $stops): void
    {
        $this->failures = 0;
        foreach ($stops as $stop) {
            if ($stop->lat === null || $stop->lon === null) {
                continue;
            }

            $stop->placeName = $this->lookup($stop->lat, $stop->lon);
        }

        // Selhání nesmí být tiché — jinak se na něj přijde až podle chybějících názvů
        if ($this->failures > 0) {
            Log::warn(sprintf('POZOR: geokódování u %d zastávek selhalo (%s) — zkusí se znovu příště.',
                $this->failures, $this->lastError));
        }
    }

    private function lookup(float $lat, float $lon): ?string
    {
        // Zaokrouhlení na ~150 m: sousední zastávky pak sdílejí jeden záznam
        // v cache a nedotazují se zbytečně dvakrát.
        $key = sprintf('%.4f|%.4f', round($lat / $this->precision) * $this->precision, round($lon / $this->precision) * $this->precision);

        $cached = $this->pdo->prepare('SELECT place_name FROM story_geocode_cache WHERE cache_key = ?');
        $cached->execute([$key]);
        $hit = $cached->fetchColumn();

        if ($hit !== false) {
            return $hit === '' ? null : (string) $hit;
        }

        [$name, $definitive] = $this->fetch($lat, $lon);

        // Do cache jen skutečnou odpověď služby (i „nic tu není"). Výpadek sítě
        // nebo přetížení (429, 5xx) se neukládá — jinak by se místo už nikdy
        // znovu nezjišťovalo a zastávka by zůstala bez názvu natrvalo.
        if ($definitive) {
            $this->pdo->prepare(
                'INSERT INTO story_geocode_cache (cache_key, lat, lon, place_name) VALUES (?, ?, ?, ?)'
            )->execute([$key, $lat, $lon, $name ?? '']);
        }

        usleep($this->delayMicroseconds);

        return $name;
    }

    /** @return array{0: ?string, 1: bool} [název místa, je to konečná odpověď služby] */
    private function fetch(float $lat, float $lon): array
    {
        $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
            'lat' => $lat,
            'lon' => $lon,
            'format' => 'jsonv2',
            'zoom' => 14,
            'accept-language' => 'cs',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => $this->userAgent,
        ]);
        if ($this->caBundle !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        }

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            $this->failures++;
            $this->lastError = $body === false ? $error : "HTTP {$status}";
            return [null, false];
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return [null, false];
        }
        $address = $data['address'] ?? [];

        foreach (['hamlet', 'village', 'suburb', 'town', 'city', 'municipality'] as $field) {
            if (!empty($address[$field])) {
                return [(string) $address[$field], true];
            }
        }

        return [!empty($data['name']) ? (string) $data['name'] : null, true];
    }
}
