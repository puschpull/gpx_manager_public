<?php
declare(strict_types=1);

namespace GpxManager\Cestopis;

/**
 * Zavolá jazykový model a nechá ho z faktů napsat text.
 *
 * Pravidla v systémovém promptu jsou napsaná tvrdě schválně. Model, který
 * dostane volnost, doplní počasí, náladu a výhledy — a cestopis pak sice
 * hezky čte, ale popisuje výlet, který se nekonal.
 */
final class Narrator
{
    public const STYLES = [
        'factual' => 'Piš věcně a stručně, jako zápis do vlastního deníku. Bez patosu.',
        'narrative' => 'Piš vypravěčsky, ale střízlivě. Bez květnatých přívlastků.',
        'literary' => 'Piš literárně — jako fejeton nebo cestopisná črta: obrazně, s rytmem vět, '
            . 's citem pro detail. Obrazy a přirovnání ale stav JEN na tom, co je ve faktech nebo '
            . 'na přiložených fotkách; jazyk smí být bohatý, obsah ne vymyšlený. Čísla v textu '
            . 'používej střídmě, souhrn čísel patří do závěrečného odstavce.',
    ];

    /** Modely, které jde zvolit, a jejich ceník v USD za 1M tokenů [vstup, výstup] (9/2026). */
    public const MODELS = [
        'claude-opus-5' => [5.0, 25.0],
        'claude-sonnet-5' => [2.0, 10.0],
    ];

    /** Kolikrát zkusit znovu při přetížení / výpadku API (429, 5xx, 529, síť). */
    private const MAX_ATTEMPTS = 4;

    /**
     * Spotřeba posledního volání — nastaví se, jakmile API odpoví, TAKÉ když
     * pak text neprojde kontrolou (odmítnutí, uříznutí). I to se platí a musí
     * se započítat do měsíčního stropu.
     * @var array{input_tokens:int, output_tokens:int, model:string}|null
     */
    public ?array $lastUsage = null;

    /** Cena v USD podle ceníku; neznámý model = null (nehádat). */
    public static function cost(string $model, int $inputTokens, int $outputTokens): ?float
    {
        // Odpověď může nést verzi modelu s příponou (claude-opus-5-2026…) — porovnat začátek
        foreach (self::MODELS as $id => [$in, $out]) {
            if ($model === $id || str_starts_with($model, $id . '-')) {
                return round(($inputTokens * $in + $outputTokens * $out) / 1_000_000, 4);
            }
        }
        return null;
    }

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-opus-5',
        private readonly string $endpoint = 'https://api.anthropic.com/v1/messages',
        private readonly int $timeoutSeconds = 300,
        private readonly string $caBundle = '',
    ) {}

    /**
     * @param array<string,mixed> $facts
     * @param list<array{stop:int, photo:Photo, data:string}> $images fotky pro model (PhotoPicker); prázdné = bez fotek
     */
    public function write(array $facts, string $style = 'factual', array $images = []): string
    {
        if (!isset(self::STYLES[$style])) {
            throw new \InvalidArgumentException(
                "Neznámý styl „{$style}\". Povolené: " . implode(', ', array_keys(self::STYLES))
            );
        }

        $content = [[
            'type' => 'text',
            'text' => "Fakta o výletu:\n\n"
                . json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]];
        // Každá fotka má před sebou štítek, ke které zastávce patří — model ji
        // tak nemůže přiřadit jinému místu.
        foreach ($images as $img) {
            $stop = $facts['zastavky'][$img['stop'] - 1] ?? null;
            $content[] = [
                'type' => 'text',
                'text' => sprintf('Zastávka %d (%s–%s), fotka pořízená v %s:',
                    $img['stop'], $stop['od'] ?? '?', $stop['do'] ?? '?', $img['photo']->takenAt->format('H:i')),
            ];
            $content[] = [
                'type' => 'image',
                'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $img['data']],
            ];
        }

        $payload = [
            'model' => $this->model,
            // Novější modely nejdřív přemýšlejí a přemýšlení se počítá do
            // max_tokens — 4 000 by mohlo text uříznout. Platí se jen za to,
            // co se opravdu vygeneruje, ne za strop.
            'max_tokens' => 16000,
            'system' => $this->systemPrompt($style, $images !== [], !empty($facts['zdroje_faktu']['mapa_openstreetmap'])),
            'messages' => [[
                'role' => 'user',
                'content' => $content,
            ]],
        ];
        // Když bezpečnostní klasifikátor modelu dotaz odmítne, API ho samo
        // zopakuje na doporučeném záložním modelu (jen u modelů, které to umí).
        if ($this->model === 'claude-opus-5') {
            $payload['fallbacks'] = 'default';
        }

        $this->lastUsage = null;
        $response = $this->post($payload);

        // Skutečná spotřeba — dřív, než se cokoli zkontroluje (platí se i za odmítnutí)
        $usage = $response['usage'] ?? [];
        $this->lastUsage = [
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            // Při přepnutí na záložní model odpověď nese ten skutečně použitý
            'model' => (string) ($response['model'] ?? $this->model),
        ];
        Log::info(sprintf('Tokeny: vstup %d, výstup %d (vč. přemýšlení)',
            $this->lastUsage['input_tokens'], $this->lastUsage['output_tokens']));

        // Důvod konce kontrolovat DŘÍV, než se čte text: uříznutý nebo
        // odmítnutý text se nesmí uložit, jako by byl hotový.
        $stop = (string) ($response['stop_reason'] ?? '');
        if ($stop === 'refusal') {
            $why = $response['stop_details']['category'] ?? 'neuvedeno';
            throw new \RuntimeException("Model dotaz odmítl (kategorie: {$why}). Text se neukládá.");
        }
        if ($stop === 'max_tokens') {
            throw new \RuntimeException('Text je uříznutý (vyčerpán max_tokens). Text se neukládá.');
        }
        if ($stop !== 'end_turn') {
            throw new \RuntimeException("Neočekávaný konec odpovědi: „{$stop}\". Text se neukládá.");
        }

        // Odpověď má víc bloků (přemýšlení, případně přepnutí na záložní
        // model) — text je jen v blocích typu "text", ne nutně v prvním.
        $text = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('Model nevrátil žádný text. Text se neukládá.');
        }

        return $text;
    }

    private function systemPrompt(string $style, bool $withPhotos = false, bool $withMap = false): string
    {
        $styles = self::STYLES;

        // Pravidlo 2 se liší: bez fotek model o tom, co bylo vidět, neví nic;
        // s fotkami smí popsat přesně to, co na nich je — a nic víc.
        $rule2 = $withPhotos
            ? <<<R
            2. K některým zastávkám máš přiložené fotky, každou se štítkem zastávky.
               Smíš popsat JEN to, co je na nich skutečně vidět (les, skály, cesta,
               světlo, výhled…), a JEN u zastávky, ke které fotka patří. Co na
               fotkách není, neexistuje. Na fotkách NEPOJMENOVÁVEJ místa, rostliny,
               zvířata ani stavby — jména smíš brát jen z fakt (misto, v_okoli).
               Počasí jen tak, jak je vidět (slunce, mraky), nikdy teplotu.
               Nepiš o náladě, únavě ani o lidech.
            R
            : <<<R
            2. Nepiš o počasí, ročním období, výhledech, barvách, náladě, únavě,
               lidech ani o tom, co bylo vidět. Nic z toho v datech není.
            R;
        $rule5 = $withMap
            ? <<<R
            5. Nevymýšlej jména míst. Používej jen ta, která jsou ve faktech
               (misto a v_okoli). Objekt z v_okoli byl NEDALEKO zastávky —
               nepiš, že jsme ho navštívili, vylezli na něj nebo ho viděli,
               pokud to neukazuje fotka té zastávky; i pak ho jen nazvi,
               nepopisuj, co o něm nevíš. Kde místo chybí, piš neurčitě.
            R
            : <<<R
            5. Nevymýšlej jména míst. Používej jen ta, která jsou ve faktech.
               Kde místo chybí, piš neurčitě („další zastavení", „o kus dál").
            R;
        $andPhotos = $withPhotos ? ' a přiložených fotek' : '';
        $photoHint = $withPhotos
            ? '- Pojmenovaný objekt z v_okoli spolu s fotkou téže zastávky dává textu místo i obraz — využij to.'
            : '- Vysoký počet fotek na jedné zastávce naznačuje, že tam bylo něco'
                . "\n  zajímavého. Smíš naznačit, že bylo co fotit — ale ne hádat, co to bylo.";

        return <<<PROMPT
        Jsi zapisovatel cestovního deníku. Dostaneš strukturovaná fakta o jednom
        výletu a napíšeš z nich souvislý český text v první osobě množného čísla.

        NEPŘEKROČITELNÁ PRAVIDLA:

        1. Smíš použít VÝHRADNĚ údaje z dodaných fakt{$andPhotos}. Nic nedoplňuj
           — ani obecné znalosti o místě (historii, pověsti, výšku kopce).
        {$rule2}
        3. Nepiš o nadmořské výšce jednotlivých zastávek. Máš jen nejnižší
           a nejvyšší bod celé trasy.
        4. Neodhaduj, co se dělo v mezerách mezi zastávkami, kromě toho,
           že se šlo — a jak dlouho to trvalo. Z času a vzdálenosti
           vzdušnou čarou NEVYVOZUJ terén (stoupání, klesání, „nahoru",
           „dolů"), tempo ani náladu („nespěchalo se") — krátká vzdálenost
           za dlouhý čas může být i klikatá cesta nebo focení.
        {$rule5}
        6. Jména míst a objektů přebírej PŘESNĚ tak, jak jsou ve faktech — neopravuj
           je ani je nepřepisuj na podobné známější. Smíš je jen skloňovat.
        7. Zařízení jen jmenuj; nepřipisuj mu funkce ani činnost (nepočítá kroky,
           neměří nic, co ve faktech není).
        8. Když si nejsi něčím jistý, vynech to. Kratší a pravdivý text
           je lepší než delší a vymyšlený.

        CO NAOPAK DĚLEJ:

        - Veď text chronologicky, ale odstavce stav podle úseků výletu
          a míst, ne podle hodin. Delší zastavení („delsi_zastaveni": true)
          si zaslouží zvláštní zmínku; krátké průchody klidně shrň dohromady.
        - Časy používej střídmě: přesný čas jen u startu, nejdelšího
          zastavení a konce, a to číslem tak, jak je ve faktech (13:31).
          Jinak piš volně („před polednem", „za chvíli", „o kus dál") —
          nepřepočítávej časy na slovní obraty typu „tři minuty po půl
          druhé", v tom se snadno splete. Nikdy nevypisuj řadu časů za sebou.
        - Nehodnoť a nevykládej („pak už šlo všechno jinak", „kvůli tomu se
          šlo", „výlet začal doopravdy") — to ve faktech není. Popisuj, co se
          stalo, a nech čtenáře, ať si to domyslí.
        - Nepočítej fotky ve větách („jedenáct snímků"); počet fotek ti jen
          napovídá, kde bylo co fotit. Slova „zastávka" a „zastavení"
          používej málo a střídej je s jinými obraty.
        - Pište jako člověk, který vzpomíná: střídej krátké a delší věty,
          vyhni se výčtům, šablonovitým spojkám („poté", „následně")
          a opakování stejné stavby vět.
        {$photoHint}
        - Na závěr jeden krátký odstavec se souhrnem — jen hlavní čísla
          (délka, převýšení, celkový čas), ne všechno, co ve faktech je.

        FOTKY V TEXTU:

        Text se zobrazí jako článek s fotkami. Za odstavec, který mluví
        o určité zastávce, vlož na samostatný řádek značku [foto N],
        kde N je „poradi" té zastávky ve faktech. Jedna značka na každé
        dva odstavce (mezi dvěma značkami vždy aspoň dva odstavce textu),
        každá zastávka nejvýš jednou, žádná značka
        před prvním ani za posledním (souhrnným) odstavcem. Vybírej
        zastávky s více fotkami nebo delším zastavením. Značka musí
        odpovídat tomu, o čem odstavec opravdu je. Mimo značky na fotky
        v textu neodkazuj („na fotce vidíme…").

        {$styles[$style]}

        Výstup: čistý text členěný na odstavce (prázdný řádek mezi nimi)
        se značkami [foto N]. Žádné nadpisy, žádný markdown, žádný úvodní
        ani závěrečný komentář o tom, co jsi udělal.
        PROMPT;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function post(array $payload): array
    {
        $headers = [
            'content-type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
        ];
        if (isset($payload['fallbacks'])) {
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }

        for ($attempt = 1; ; $attempt++) {
            $responseHeaders = [];
            $ch = curl_init($this->endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                    if (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $responseHeaders[strtolower(trim($k))] = trim($v);
                    }
                    return strlen($line);
                },
            ]);
            if ($this->caBundle !== '') {
                curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
            }

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $decoded = $body === false ? null : json_decode((string) $body, true);
            if ($body !== false && $status === 200 && is_array($decoded)) {
                return $decoded;
            }

            // Opakovat jen to, co má smysl opakovat: síť, 408, 409, 429, 5xx
            // (vč. 529 přetížení). Chyby 400/401/403/404 opakovat nemá cenu.
            $retryable = $body === false || in_array($status, [408, 409, 429], true) || $status >= 500;
            $message = $body === false
                ? "spojení selhalo: {$error}"
                : ($decoded['error']['message'] ?? substr((string) $body, 0, 400));

            if (!$retryable || $attempt >= self::MAX_ATTEMPTS) {
                throw new \RuntimeException(
                    $body === false ? "API: {$message}" : "API vrátilo {$status}: {$message}"
                );
            }

            $wait = isset($responseHeaders['retry-after']) && is_numeric($responseHeaders['retry-after'])
                ? min(60, (int) $responseHeaders['retry-after'])
                : min(60, 2 ** $attempt + random_int(0, 1000) / 1000);
            Log::warn(sprintf('API nedostupné (%s), pokus %d/%d za %.0f s…',
                $body === false ? 'síť' : (string) $status, $attempt + 1, self::MAX_ATTEMPTS, $wait));
            usleep((int) ($wait * 1_000_000));
        }
    }
}
