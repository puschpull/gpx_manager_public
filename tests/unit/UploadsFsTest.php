<?php
declare(strict_types=1);

namespace GpxManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * uploads_fs() must never return a path outside uploads/ (S1, 21 Nov 2026):
 * ".." through a missing directory used to slip past the realpath() check.
 */
final class UploadsFsTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function badPaths(): array
    {
        return [
            'missing dir then up'      => ['neexistuje/../../config.php'],
            'deep missing dir'         => ['a/b/../../../.env'],
            'plain up'                 => ['../config.php'],
            'existing dir then up'     => ['photos/../../config.php'],
            'backslashes'              => ['neexistuje\\..\\..\\config.php'],
            'mixed separators'         => ['photos\\../../.env'],
            'only dots'                => ['..'],
            'trailing dots'            => ['photos/..'],
            'leading slash then up'    => ['/../config.php'],
            'nul byte'                 => ["photos/x.jpg\0.png"],
        ];
    }

    /** @dataProvider badPaths */
    public function testRejectsTraversal(string $path): void
    {
        $this->expectException(\RuntimeException::class);
        uploads_fs($path);
    }

    /** @return array<string, array{string}> */
    public static function goodPaths(): array
    {
        return [
            'gpx file'            => ['20140330_cleaned_old_v17.gpx'],
            'photo thumb'         => ['photos/thumbs/abc.jpg'],
            'track thumb'         => ['thumbs/20140330.png'],
            'cache dir'           => ['_line_cache'],
            'missing subdir file' => ['radar/999999/x.png'],
            'dots inside name'    => ['photos/my..photo.jpg'],
            'single dot segment'  => ['./thumbs/x.png'],
            'leading slash'       => ['/thumbs/x.png'],
        ];
    }

    /** @dataProvider goodPaths */
    public function testAcceptsNormalPaths(string $path): void
    {
        $base = uploads_fs();
        $this->assertStringStartsWith($base, uploads_fs($path));
    }
}
