<?php
declare(strict_types=1);

/**
 * scripts/lint_css_fragile.php
 *
 * Hlídá vzor, který 27.7.2026 způsobil zmizení horního menu.
 *
 * PROČ: Tailwind v4 dává utility do `@layer utilities`. Podle specifikace CSS
 * ale NEVRSTVENÉ pravidlo porazí jakékoli vrstvené — bez ohledu na pořadí a bez
 * `!important`. Rozšíření prohlížeče si běžně vkládají do stránky vlastní UI
 * a s ním obyčejné `.hidden{display:none}` mimo vrstvu. Každý náš prvek, jehož
 * viditelnost stojí na `hidden` + responzivní variantě, pak zmizí i na širokém
 * displeji — a v anonymním okně to přitom funguje, takže se to špatně hledá.
 *
 * CO JE V POŘÁDKU: `hidden` přidávané a odebírané JavaScriptem
 * (`classList.remove('hidden')`). Po odebrání třídy se cizí pravidlo nemá čeho
 * chytit. Stejně tak `md:hidden` — to je jiný název třídy, ten nikdo nepřebíjí.
 *
 * CO POUŽÍT MÍSTO TOHO: třídy .gpx-md-flex / .gpx-md-block / .gpx-sm-inline /
 * .gpx-sm-inline-flex definované v includes/layout_header.php.
 *
 * SPUŠTĚNÍ:  php scripts/lint_css_fragile.php
 * Vrací exit kód 1, když něco najde (kvůli CI).
 */

$root = dirname(__DIR__);

// Prohledávané přípony a vynechané adresáře
$skipDirs = ['vendor', 'node_modules', '.git', 'uploads', 'logs'];
$exts     = ['php', 'html'];

/** Rekurzivní sběr souborů. */
function collect(string $dir, array $skipDirs, array $exts): array {
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static function ($current) use ($skipDirs) {
                return !($current->isDir() && in_array($current->getFilename(), $skipDirs, true));
            }
        )
    );
    foreach ($it as $f) {
        if ($f->isFile() && in_array(strtolower($f->getExtension()), $exts, true)) {
            $out[] = $f->getPathname();
        }
    }
    return $out;
}

// `hidden` následované responzivní/stavovou variantou, která ho má přebít
$display = 'flex|block|inline|inline-flex|inline-block|grid|table|contents';
$variant = 'sm|md|lg|xl|2xl|dark|group-hover|group-focus|peer-checked|hover|focus';
$patterns = [
    // hidden ... md:flex
    '/class="[^"]*\bhidden\b[^"]*\b(?:' . $variant . '):(?:' . $display . ')\b[^"]*"/',
    // md:flex ... hidden  (obrácené pořadí ve výpisu tříd)
    '/class="[^"]*\b(?:' . $variant . '):(?:' . $display . ')\b[^"]*\bhidden\b[^"]*"/',
];

$hits = [];
foreach (collect($root, $skipDirs, $exts) as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $i => $line) {
        foreach ($patterns as $p) {
            if (preg_match($p, $line, $m)) {
                $hits[] = [
                    'file' => ltrim(str_replace($root, '', $file), '\\/'),
                    'line' => $i + 1,
                    'code' => trim(substr($m[0], 0, 110)),
                ];
                break;
            }
        }
    }
}

if (!$hits) {
    echo "OK — zadny krehky vzor 'hidden + responzivni varianta' nenalezen.\n";
    exit(0);
}

echo "NALEZEN KREHKY VZOR (" . count($hits) . "x)\n";
echo str_repeat('-', 78) . "\n";
foreach ($hits as $h) {
    echo "  {$h['file']}:{$h['line']}\n      {$h['code']}\n";
}
echo str_repeat('-', 78) . "\n";
echo "Viditelnost techto prvku stoji na tride `hidden`, kterou prebije\n";
echo "nevrstvene CSS od rozsireni prohlizece (Tailwind utility jsou v @layer).\n";
echo "Pouzij misto toho .gpx-md-flex / .gpx-md-block / .gpx-sm-inline /\n";
echo ".gpx-sm-inline-flex z includes/layout_header.php.\n";
exit(1);
