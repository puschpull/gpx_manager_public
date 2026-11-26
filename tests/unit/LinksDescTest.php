<?php
declare(strict_types=1);

namespace GpxManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/links_data.php';

/**
 * Every translation file of the Similar websites page must describe exactly
 * the links that exist in includes/links_data.php — a new link without its
 * translations (or a renamed one) would silently stay Czech in that language.
 */
final class LinksDescTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function langs(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/../../includes/links_desc/*.php') ?: [] as $f) {
            $out[basename($f, '.php')] = [$f];
        }
        return $out;
    }

    /** @return list<string> */
    private static function names(): array
    {
        $names = [];
        foreach (links_catalog_cs() as $cat) {
            foreach ($cat['items'] as $item) {
                $names[] = $item['name'];
            }
        }
        return $names;
    }

    public function testAllSevenLanguagesPresent(): void
    {
        $this->assertSame(['de', 'en', 'es', 'fr', 'it', 'pl', 'sk'], array_keys(self::langsSorted()));
    }

    /** @dataProvider langs */
    public function testSameNamesAsCatalog(string $file): void
    {
        $desc = require $file;
        $names = self::names();
        $this->assertSame([], array_values(array_diff($names, array_keys($desc))), 'missing descriptions');
        $this->assertSame([], array_values(array_diff(array_keys($desc), $names)), 'descriptions of unknown links');
        foreach ($desc as $name => $text) {
            $this->assertIsString($text);
            $this->assertNotSame('', trim($text), "empty description for $name");
        }
    }

    public function testNamesUnique(): void
    {
        $names = self::names();
        $this->assertSame(count($names), count(array_unique($names)));
    }

    /** @return array<string, array{string}> */
    private static function langsSorted(): array
    {
        $l = self::langs();
        ksort($l);
        return $l;
    }
}
