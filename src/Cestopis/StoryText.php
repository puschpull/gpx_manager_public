<?php

declare(strict_types=1);

namespace GpxManager\Cestopis;

/**
 * Text cestopisu → odstavce + značky fotek.
 *
 * Model smí mezi odstavce vložit značku „[foto N]“ (N = pořadí zastávky
 * ve faktech) na místo, kde o té zastávce píše. Značka se nikdy nezobrazí
 * jako text: čtení (story.php) na její místo dá fotku, správa a výpis
 * z příkazové řádky ji jen naznačí. Starší verze značky nemají — pak je
 * `photos` prázdné a fotky se rozmístí podle časů (includes/story_layout.php).
 */
final class StoryText
{
    /** Značka kdekoli v textu; toleruje „[Foto 5]“, „[foto: 5]“, „[foto č. 5]“. */
    private const MARK = '/\[\s*foto\s*(?:č\.|:)?\s*(\d{1,3})\s*\]/iu';

    /**
     * @return array{paras: list<string>, photos: array<int,int>}
     *         photos = [index odstavce => číslo zastávky], fotka patří ZA ten odstavec
     */
    public static function split(string $text): array
    {
        $paras = [];
        $photos = [];
        foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $block) {
            $marks = [];
            if (preg_match_all(self::MARK, $block, $m)) {
                $marks = array_map('intval', $m[1]);
            }
            $clean = trim((string) preg_replace([self::MARK, '/[ \t]{2,}/'], ['', ' '], $block));

            if ($clean !== '') {
                $paras[] = $clean;
            }
            // Značka patří za odstavec, ve kterém stojí (nebo za předchozí,
            // když stojí samostatně); před prvním odstavcem se nepoužije
            $at = count($paras) - 1;
            if ($at >= 0 && $marks !== [] && !isset($photos[$at])) {
                $photos[$at] = $marks[0];
            }
        }

        return ['paras' => $paras, 'photos' => $photos];
    }

    /** Text bez značek (pro místa, kde se fotky nevkládají). */
    public static function plain(string $text): string
    {
        return implode("\n\n", self::split($text)['paras']);
    }
}
