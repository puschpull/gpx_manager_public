<?php
declare(strict_types=1);

namespace GpxManager\Cestopis;

/**
 * Složí text a fotky do jedné HTML stránky.
 *
 * Když je k trase víc uložených verzí (jiný styl, s fotkami, s mapou…), stránka
 * je nabídne přepínačem nahoře — jednu po druhé, nebo všechny vedle sebe.
 * Přepínač je čisté CSS (radio + :checked), stránka nepotřebuje JavaScript.
 */
final class Renderer
{
    private const STYLE_NAMES = ['factual' => 'věcný', 'narrative' => 'vypravěčský', 'literary' => 'literární'];

    public function __construct(private readonly string $photoBaseUrl) {}

    /**
     * @param array<string,mixed> $track
     * @param list<Stop> $stops
     * @param list<array<string,mixed>> $stories hotové verze z Repository::stories(…, onlyDone: true), od nejstarší
     * @param array<int, list<array{nazev:string, druh:string, vzdalenost_m:int}>> $pois index zastávky → objekty z mapy
     */
    public function render(array $track, array $stops, array $stories, array $pois = []): string
    {
        if ($stories === []) {
            throw new \InvalidArgumentException('K trase není uložený žádný cestopis.');
        }

        $title = $track['alt_title'] ?: ($track['place_name'] ?: 'Výlet');
        $date = empty($track['date_start'])
            ? ''
            : (new \DateTimeImmutable((string) $track['date_start']))->format('j. n. Y');

        // Přepínač + texty. Výchozí je nejnovější verze.
        $last = count($stories) - 1;
        $radios = '';
        $labels = '';
        $texts = '';
        $css = '';
        foreach ($stories as $n => $s) {
            $id = 'v' . $s['id'];
            $radios .= sprintf('<input type="radio" name="verze" id="%s"%s>', $id, $n === $last ? ' checked' : '');
            $labels .= sprintf('<label for="%s">%s</label>', $id, $this->e($this->shortLabel($s)));
            $paragraphs = '';
            foreach (preg_split('/\n\s*\n/', trim($s['story'])) as $p) {
                $paragraphs .= '<p>' . nl2br($this->e(trim($p))) . "</p>\n";
            }
            $texts .= sprintf(
                "<article class=\"story %s\"><p class=\"meta\">%s</p>\n%s</article>\n",
                $id, $this->e($this->longLabel($s)), $paragraphs,
            );
            $css .= "#{$id}:checked~.stories .{$id}{display:block}\n";
            $css .= "#{$id}:checked~.switch label[for={$id}]{background:CanvasText;color:Canvas}\n";
        }
        $single = count($stories) === 1;
        if (!$single) {
            $radios .= '<input type="radio" name="verze" id="vall">';
            $labels .= '<label for="vall">vše vedle sebe</label>';
        }

        $numbers = '';
        foreach ($stories[$last]['facts']['cisla'] ?? [] as $label => $value) {
            if ($value === null) {
                continue;
            }
            $numbers .= sprintf(
                '<div><dt>%s</dt><dd>%s</dd></div>',
                $this->e(str_replace('_', ' ', (string) $label)),
                $this->e((string) $value),
            );
        }

        $gallery = '';
        foreach ($stops as $i => $stop) {
            $heading = $stop->placeName ?: 'Zastávka ' . ($i + 1);
            $near = '';
            if (!empty($pois[$i])) {
                $near = '<p class="near">Nedaleko: ' . implode(', ', array_map(
                    fn(array $p) => sprintf('%s <small>(%s, ~%d m)</small>', $this->e($p['nazev']), $this->e($p['druh']), $p['vzdalenost_m']),
                    $pois[$i],
                )) . '</p>';
            }
            $gallery .= sprintf(
                "<section class=\"stop\"><h3>%d. %s <span>%s–%s</span></h3>%s<div class=\"grid\">",
                $i + 1,
                $this->e($heading),
                $stop->startAt->format('H:i'),
                $stop->endAt->format('H:i'),
                $near,
            );

            foreach ($stop->photos as $photo) {
                $gallery .= sprintf(
                    '<figure><img loading="lazy" src="%s" alt="%s"><figcaption>%s</figcaption></figure>',
                    $this->e($this->photoBaseUrl . $photo->filename),
                    $this->e($photo->caption ?? 'Fotografie pořízená v ' . $photo->takenAt->format('H:i')),
                    $this->e($photo->caption ?? $photo->takenAt->format('H:i')),
                );
            }

            $gallery .= "</div></section>\n";
        }

        $switchHtml = $single ? '' : "<div class=\"switch\">Verze textu: {$labels}</div>";

        return <<<HTML
        <!doctype html>
        <html lang="cs">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>{$this->e($title)} — {$date}</title>
        <style>
        :root{color-scheme:light dark}
        body{max-width:46rem;margin:0 auto;padding:2rem 1.25rem 5rem;
             font:16px/1.65 Georgia,serif;background:Canvas;color:CanvasText}
        body:has(#vall:checked){max-width:96rem}
        h1{font-size:2rem;line-height:1.2;margin:0 0 .25rem}
        .date{color:GrayText;margin:0 0 1.5rem}
        p{margin:0 0 1rem}
        input[name=verze]{position:absolute;opacity:0;pointer-events:none}
        .switch{font:14px/1.4 system-ui,sans-serif;margin:0 0 1.5rem;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center}
        .switch label{display:inline-block;padding:.3rem .7rem;border:1px solid GrayText;border-radius:999px;cursor:pointer}
        #vall:checked~.switch label[for=vall]{background:CanvasText;color:Canvas}
        .story{display:none}
        {$css}
        #vall:checked~.stories{display:grid;grid-template-columns:repeat(auto-fit,minmax(22rem,1fr));gap:2rem}
        #vall:checked~.stories .story{display:block;border-top:3px solid GrayText;padding-top:.5rem}
        .meta{font:13px/1.4 system-ui,sans-serif;color:GrayText;margin:0 0 1rem}
        dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));
           gap:.75rem;margin:2rem 0;padding:1rem;border:1px solid GrayText;border-radius:4px}
        dt{font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:GrayText}
        dd{margin:0;font-size:1.05rem}
        .stop h3{font-size:1rem;margin:2rem 0 .5rem;font-weight:600}
        .stop h3 span{color:GrayText;font-weight:400;font-size:.85rem}
        .near{font-size:.85rem;margin:0 0 .5rem}
        .near small{color:GrayText}
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:.5rem}
        figure{margin:0}
        img{width:100%;height:auto;display:block;border-radius:3px}
        figcaption{font-size:.75rem;color:GrayText;padding-top:.25rem}
        .draft{border:1px solid GrayText;padding:.75rem 1rem;border-radius:4px;
               font-size:.85rem;color:GrayText;margin-bottom:2rem}
        </style>
        </head>
        <body>
        <p class="draft">Návrh vygenerovaný z dat trasy. Před zveřejněním si ho projdi.</p>
        <h1>{$this->e($title)}</h1>
        <p class="date">{$date}</p>
        {$radios}
        {$switchHtml}
        <div class="stories">
        {$texts}
        </div>
        <dl>{$numbers}</dl>
        {$gallery}
        </body>
        </html>
        HTML;
    }

    /** @param array<string,mixed> $s řádek z Repository::stories() */
    private function shortLabel(array $s): string
    {
        $extra = array_filter([
            $s['photos_sent'] > 0 ? 'fotky' : null,
            $s['with_map'] ? 'mapa' : null,
            str_contains((string) $s['model'], 'sonnet') ? 'Sonnet' : null,
        ]);

        return sprintf('#%d %s%s', $s['id'], self::STYLE_NAMES[$s['style']] ?? $s['style'],
            $extra === [] ? '' : ' + ' . implode(' + ', $extra));
    }

    /** @param array<string,mixed> $s řádek z Repository::stories() */
    private function longLabel(array $s): string
    {
        return sprintf('Verze #%d · %s · styl %s · %s · %s · %s%s',
            $s['id'],
            (new \DateTimeImmutable((string) $s['created_at']))->format('j. n. Y H:i'),
            self::STYLE_NAMES[$s['style']] ?? $s['style'],
            $s['model'],
            $s['photos_sent'] > 0 ? "model viděl {$s['photos_sent']} fotek" : 'bez fotek',
            $s['with_map'] ? 's body z mapy OSM' : 'bez mapy',
            $s['cost_usd'] !== null ? sprintf(' · $%.2f', $s['cost_usd']) : '',
        );
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
