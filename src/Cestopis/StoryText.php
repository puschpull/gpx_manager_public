<?php

declare(strict_types=1);

namespace GpxManager\Cestopis;

/**
 * Text cestopisu → odstavce + značky fotek; prameny.
 *
 * Značka „[foto N]“ (N = pořadí zastávky ve faktech) stojí za odstavcem,
 * o jehož místě mluví; „[foto N/k]“ vybere k-tou fotku té zastávky (ruční
 * výběr ve Správě cestopisu). Značka PŘED prvním odstavcem určí úvodní fotku.
 * Značka se nikdy nezobrazí jako text: čtení (story.php) na její místo dá
 * fotku, správa ji jen naznačí, příkazová řádka ji skryje. Starší verze
 * značky nemají — fotky se pak rozmístí podle časů (includes/story_layout.php).
 */
final class StoryText
{
    /** Toleruje „[Foto 5]“, „[foto: 5]“, „[foto č. 5]“, „[foto 5/3]“. */
    private const MARK = '/\[\s*foto\s*(?:č\.|:)?\s*(\d{1,3})(?:\s*\/\s*(\d{1,3}))?\s*\]/iu';

    /**
     * @return array{paras: list<string>, photos: array<int, array{stop:int, pick:?int}>, hero: ?array{stop:int, pick:?int}}
     *         photos = [index odstavce => fotka ZA tím odstavcem]
     */
    public static function split(string $text): array
    {
        $paras = [];
        $photos = [];
        $hero = null;
        foreach (preg_split('/\n\s*\n/', trim(str_replace("\r\n", "\n", $text))) ?: [] as $block) {
            $marks = [];
            if (preg_match_all(self::MARK, $block, $m, PREG_SET_ORDER)) {
                foreach ($m as $mm) {
                    $marks[] = ['stop' => (int) $mm[1], 'pick' => isset($mm[2]) && $mm[2] !== '' ? (int) $mm[2] : null];
                }
            }
            $clean = trim((string) preg_replace([self::MARK, '/[ \t]{2,}/'], ['', ' '], $block));

            if ($clean !== '') {
                $paras[] = $clean;
            }
            if ($marks === []) {
                continue;
            }
            if ($paras === []) {
                // Samostatná značka před prvním odstavcem = úvodní fotka
                $hero ??= $marks[0];
                continue;
            }
            $at = count($paras) - 1;
            if (!isset($photos[$at])) {
                $photos[$at] = $marks[0];
            }
        }

        return ['paras' => $paras, 'photos' => $photos, 'hero' => $hero];
    }

    /** Text bez značek (pro místa, kde se fotky nevkládají). */
    public static function plain(string $text): string
    {
        return implode("\n\n", self::split($text)['paras']);
    }

    /**
     * Prameny z textu: řádek „Název | https://…“, „Název — https://…“ nebo jen
     * adresa. Jen http(s); řádek bez adresy se vynechá.
     * @return list<array{nazev:string, url:string}>
     */
    public static function parseSources(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (!preg_match('~(https?://\S+)~i', $line, $m)) {
                continue;
            }
            $url = rtrim($m[1], '.,;)');
            $name = trim((string) preg_replace('~\s*[|—–-]?\s*https?://\S+~i', '', $line), " \t|—–-");
            if ($name === '') {
                $name = (string) parse_url($url, PHP_URL_HOST);
            }
            $out[] = ['nazev' => mb_substr($name, 0, 200), 'url' => mb_substr($url, 0, 500)];
        }
        return $out;
    }

    /** Prameny zpět do textu (pro úpravu ve formuláři). */
    public static function formatSources(array $sources): string
    {
        return implode("\n", array_map(
            static fn(array $s) => trim(($s['nazev'] ?? '') . ' | ' . ($s['url'] ?? ''), ' |'),
            $sources
        ));
    }
}
