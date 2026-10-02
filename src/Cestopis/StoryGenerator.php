<?php
declare(strict_types=1);

namespace GpxManager\Cestopis;

use PDO;

/**
 * Jedno místo, které řídí vznik cestopisu — pro příkazovou řádku i web.
 *
 *   trasa + fotky → zastávky → (názvy míst, body z mapy, fotky) → fakta → model → track_stories
 *
 * Řádek v track_stories vzniká DŘÍV, než se zavolá API (stav „running"),
 * takže druhé kliknutí během generování nic nezaplatí a výsledek ani cena
 * se neztratí, když se zavře okno. Neúspěch se zapíše i se spotřebou.
 */
final class StoryGenerator
{
    /** none = bez fotek, rest = jen z delších zastavení, all = ze všech zastávek */
    public const PHOTO_MODES = ['none', 'rest', 'all'];

    private readonly Repository $repo;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $apiKey,
        private readonly string $userAgent,
        private readonly string $photoDir,
        private readonly string $caBundle = '',
        /** Jen pro testy proti falešnému API — naostro výchozí adresa. */
        private readonly string $apiEndpoint = 'https://api.anthropic.com/v1/messages',
    ) {
        $this->repo = new Repository($pdo);
    }

    /** Styl verze psané mimo API (místopisný cestopis). */
    public const STYLE_LOCAL = 'local';
    /** „Model“ verze psané nebo upravené ručně (ne API) — cena 0. */
    public const MODEL_MANUAL = 'rucne';

    /**
     * Místa podél celé trasy (CorridorFinder) — podklad pro místopisný text.
     * Zdarma, jen OpenStreetMap.
     * @return list<array<string,mixed>>
     */
    public function corridor(int $trackId): array
    {
        $file = $this->repo->gpxFilename($trackId);
        if ($file === null) {
            throw new \RuntimeException("Trasa {$trackId} neexistuje.");
        }
        $path = dirname(rtrim($this->photoDir, '/\\')) . DIRECTORY_SEPARATOR . $file;
        return (new CorridorFinder(new PoiFinder($this->pdo, $this->userAgent, $this->caBundle)))
            ->find(CorridorFinder::readGpx($path));
    }

    /**
     * Uloží text napsaný mimo API jako novou verzi (koncept, cena 0).
     *
     * Úprava existující verze ($baseId) převezme její fakta beze změny —
     * značky „[foto N]“ v textu odkazují na čísla zastávek té verze a ta se
     * nesmí přečíslovat. Nový místopisný text si fakta spočítá (zdarma,
     * z cache), aby šly vložit fotky ze zastávek.
     *
     * @param list<array{nazev:string, url:string}> $sources prameny (zobrazí se pod článkem)
     */
    public function saveManual(int $trackId, string $text, array $sources, ?int $baseId = null): int
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') {
            throw new \InvalidArgumentException('Text je prázdný.');
        }
        $style = self::STYLE_LOCAL;
        if ($baseId !== null) {
            $base = $this->repo->story($baseId);
            if ($base === null || (int) $base['track_id'] !== $trackId || $base['status'] !== 'done') {
                throw new \RuntimeException("Verze #{$baseId} k této trase neexistuje.");
            }
            $facts = (array) $base['facts'];
            $style = (string) $base['style'];
        } else {
            try {
                ['track' => $track, 'stops' => $stops, 'pois' => $pois] = $this->prepare($trackId);
                $facts = (new FactSheet())->build($track, $stops, $this->repo->categories($trackId), $pois);
            } catch (\RuntimeException $e) {
                // Trasa bez fotek: místopisný text jde i bez nich, jen bez fotek v článku
                Log::warn($e->getMessage());
                $facts = [];
            }
        }
        $facts['prameny'] = array_values($sources);
        $facts['puvod'] = ['typ' => 'rucne', 'z_verze' => $baseId];

        return $this->repo->saveManual($trackId, $style, self::MODEL_MANUAL, $text, $facts);
    }

    public function repository(): Repository
    {
        return $this->repo;
    }

    /**
     * Zastávky trasy s názvy míst a body z mapy (vše z cache, když už byly
     * zjištěné). Nic neplatí — volá jen Nominatim / Overpass.
     * @return array{track:array<string,mixed>, stops:list<Stop>, pois:array<int,list<array<string,mixed>>>}
     */
    public function prepare(int $trackId, bool $withMap = true, bool $geocode = true): array
    {
        $track = $this->repo->track($trackId);
        $photos = $this->repo->photos($trackId);
        if ($photos === []) {
            throw new \RuntimeException("Trasa {$trackId} nemá žádné fotky s časem — není z čeho psát.");
        }
        $stops = (new StopDetector())->detect($photos);
        Log::info(sprintf('Fotek: %d, zastávek: %d', count($photos), count($stops)));

        if ($geocode) {
            Log::info('Názvy míst zastávek (Nominatim, 1 dotaz/s)…');
            (new Geocoder($this->pdo, $this->userAgent, caBundle: $this->caBundle))->fill($stops);
        }
        $pois = [];
        if ($withMap) {
            Log::info('Body z mapy OpenStreetMap…');
            $pois = (new PoiFinder($this->pdo, $this->userAgent, $this->caBundle))->find($stops);
        }

        return ['track' => $track, 'stops' => $stops, 'pois' => $pois];
    }

    /**
     * Vygeneruje a uloží novou verzi najednou (příkazová řádka). Vrací id.
     * Když pro trasu už jedno generování běží, vyhodí výjimku a nic neplatí.
     */
    public function generate(int $trackId, string $style, string $model, bool $withMap, string $photoMode, bool $geocode = true): int
    {
        $id = $this->begin($trackId, $style, $model, $withMap, $photoMode);
        $this->run($id, $geocode);

        return $id;
    }

    /**
     * Krok 1: ověří vstup a založí řádek „running". Rychlé, nic neplatí —
     * web po něm hned odpoví prohlížeči a run() pustí až po odeslání odpovědi.
     */
    public function begin(int $trackId, string $style, string $model, bool $withMap, string $photoMode): int
    {
        self::validate($style, $model, $photoMode);
        if ($this->apiKey === '') {
            throw new \RuntimeException('Chybí API klíč (ANTHROPIC_API_KEY v .env).');
        }
        $this->repo->track($trackId);   // neexistující trasa = výjimka dřív, než vznikne řádek

        $id = $this->repo->start($trackId, $style, $model, $withMap, $photoMode);
        if ($id === null) {
            throw new \RuntimeException('Pro tuto trasu už jeden cestopis vzniká. Počkej, až doběhne.');
        }

        return $id;
    }

    /**
     * Krok 2: placená část. Výsledek i neúspěch zapíše do řádku $id;
     * výjimku po zápisu pustí dál (volající ji jen zaloguje / vypíše).
     */
    public function run(int $id, bool $geocode = true): void
    {
        $row = $this->repo->story($id);
        if ($row === null || $row['status'] !== 'running') {
            throw new \RuntimeException("Verze #{$id} nečeká na generování.");
        }
        $trackId = (int) $row['track_id'];
        $style = (string) $row['style'];
        $model = (string) $row['model'];
        $withMap = (bool) $row['with_map'];
        $photoMode = (string) $row['photo_mode'];

        $started = time();
        $narrator = new Narrator($this->apiKey, $model, $this->apiEndpoint, caBundle: $this->caBundle);
        try {
            ['track' => $track, 'stops' => $stops, 'pois' => $pois] = $this->prepare($trackId, $withMap, $geocode);

            $images = [];
            if ($photoMode !== 'none') {
                $images = (new PhotoPicker($this->photoDir, restOnly: $photoMode === 'rest'))->pick($stops);
                Log::info(sprintf('Do API se pošle %d zmenšených fotek.', count($images)));
            }

            $facts = (new FactSheet())->build($track, $stops, $this->repo->categories($trackId), $pois, $images);
            Log::info("Píše {$model}, styl {$style}…");
            $story = $narrator->write($facts, $style, $images);

            $usage = $narrator->lastUsage;   // po úspěšném write() je vždy nastavené
            $this->repo->finish($id, $story, $facts, $usage,
                Narrator::cost($usage['model'], $usage['input_tokens'], $usage['output_tokens']),
                time() - $started, count($images));
        } catch (\Throwable $e) {
            $usage = $narrator->lastUsage;
            $this->repo->fail($id, $e->getMessage(), $usage,
                $usage !== null ? Narrator::cost($usage['model'], $usage['input_tokens'], $usage['output_tokens']) : null,
                time() - $started);
            throw $e;
        }
    }

    /**
     * Odhad ceny před generováním (USD). Když už stejná varianta někdy
     * běžela, vychází z její skutečné spotřeby; jinak z hrubého vzorce
     * podle zkušenosti s trasou 574 (9/2026).
     * @return array{usd:?float, input_tokens:int, output_tokens:int, photos:int, from_history:int}
     */
    public function estimate(int $trackId, string $style, string $model, bool $withMap, string $photoMode): array
    {
        self::validate($style, $model, $photoMode);

        $photos = 0;
        if ($photoMode !== 'none') {
            $stops = (new StopDetector())->detect($this->repo->photos($trackId));
            $photos = count((new PhotoPicker($this->photoDir, restOnly: $photoMode === 'rest'))->select($stops));
        }

        $history = $this->repo->averageUsage($style, $model, $withMap, $photoMode);
        if ($history !== null) {
            $in = (int) round($history['input_tokens']);
            $out = (int) round($history['output_tokens']);
        } else {
            // Fakta a pravidla ~4 500 tokenů, body z mapy ~500, fotka 800 px ~500.
            // Výstup: text podle stylu + přemýšlení, které s fotkami výrazně roste
            // (trasa 574: bez fotek ~1 500, s 37 fotkami ~9 000 tokenů).
            $in = 4500 + ($withMap ? 500 : 0) + $photos * 500;
            $out = ['factual' => 1300, 'narrative' => 1500, 'literary' => 1700][$style] + $photos * 200;
        }

        return [
            'usd' => Narrator::cost($model, $in, $out),
            'input_tokens' => $in,
            'output_tokens' => $out,
            'photos' => $photos,
            'from_history' => $history['count'] ?? 0,
        ];
    }

    private static function validate(string $style, string $model, string $photoMode): void
    {
        if (!isset(Narrator::STYLES[$style])) {
            throw new \InvalidArgumentException("Neznámý styl „{$style}\".");
        }
        if (!isset(Narrator::MODELS[$model])) {
            throw new \InvalidArgumentException("Neznámý model „{$model}\".");
        }
        if (!in_array($photoMode, self::PHOTO_MODES, true)) {
            throw new \InvalidArgumentException("Neznámý režim fotek „{$photoMode}\".");
        }
    }
}
