<?php
declare(strict_types=1);

namespace GpxManager\Cestopis;

/**
 * Vybere k zastávkám několik fotek a připraví je pro model (zmenšené JPEG).
 *
 * Proč: bez fotek model o místě neví nic kromě jména a čísel. S fotkou smí
 * popsat, co na ní OPRAVDU je (les, skály, výhled) — pořád žádný dohad.
 *
 * Soukromí: fotky odcházejí do API poskytovatele modelu. Proto se posílají
 * jen na výslovný přepínač --s-fotkami (u každého spuštění znovu), nikdy samy.
 *
 * Výběr: delší zastavení až 3 fotky, krátké 1 (s $restOnly žádná), rovnoměrně rozložené v čase;
 * celkem nejvýš $maxTotal. Zmenšení na $maxSide px (800 px na rozpoznání
 * krajiny stačí, náhledy aplikace 400 px jsou na to malé).
 */
final class PhotoPicker
{
    public function __construct(
        private readonly string $photoDir,
        private readonly int $maxSide = 800,
        private readonly int $quality = 80,
        private readonly int $maxTotal = 40,
        /** Jen delší zastavení (levnější varianta — méně fotek, méně práce pro model). */
        private readonly bool $restOnly = false,
    ) {}

    /**
     * @param list<Stop> $stops
     * @return list<array{stop:int, photo:Photo, data:string}> stop = pořadí zastávky (od 1)
     */
    public function pick(array $stops): array
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('Pro posílání fotek modelu je potřeba rozšíření PHP gd.');
        }

        $out = [];
        $missing = 0;
        foreach ($this->select($stops) as $sel) {
            $data = $this->encode($this->photoDir . $sel['photo']->filename);
            if ($data === null) {
                $missing++;
                continue;
            }
            $out[] = $sel + ['data' => $data];
        }

        if ($missing > 0) {
            Log::warn(sprintf('POZOR: %d vybraných fotek se nepodařilo načíst z %s — pošle se méně.',
                $missing, $this->photoDir));
        }

        return $out;
    }

    /**
     * Které fotky se pošlou — bez načítání souborů (stačí na odhad ceny).
     * @param list<Stop> $stops
     * @return list<array{stop:int, photo:Photo}>
     */
    public function select(array $stops): array
    {
        // Kolik fotek na zastávku: delší 3, krátké 1; když je to přes limit, delší dostanou 2
        $restOnly = $this->restOnly;
        $perStop = static fn(Stop $s, int $rest): int => min($s->photoCount(), $s->isRest() ? $rest : ($restOnly ? 0 : 1));
        $rest = 3;
        $total = array_sum(array_map(static fn(Stop $s) => $perStop($s, $rest), $stops));
        if ($total > $this->maxTotal) {
            $rest = 2;
        }

        $out = [];
        foreach ($stops as $i => $stop) {
            foreach ($this->spread($stop->photos, $perStop($stop, $rest)) as $photo) {
                if (count($out) >= $this->maxTotal) {
                    break 2;
                }
                $out[] = ['stop' => $i + 1, 'photo' => $photo];
            }
        }

        return $out;
    }

    /**
     * Rovnoměrně rozložený výběr $k snímků (první, prostřední, poslední…).
     * @param list<Photo> $photos
     * @return list<Photo>
     */
    private function spread(array $photos, int $k): array
    {
        $n = count($photos);
        if ($k <= 0 || $n === 0) {
            return [];
        }
        if ($k >= $n) {
            return $photos;
        }
        if ($k === 1) {
            return [$photos[intdiv($n, 2)]];
        }
        $picked = [];
        for ($j = 0; $j < $k; $j++) {
            $picked[] = $photos[(int) round($j * ($n - 1) / ($k - 1))];
        }
        return $picked;
    }

    /** Zmenšený JPEG v base64, nebo null když soubor chybí / nejde načíst. */
    private function encode(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if ($src === false) {
            return null;
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1.0, $this->maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($dst, null, $this->quality);
        $jpeg = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return base64_encode($jpeg);
    }
}
