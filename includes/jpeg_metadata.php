<?php
declare(strict_types=1);

/**
 * GPX Manager — carry metadata over from an uploaded photo to the resized copy.
 *
 * PHP GD writes a bare JPEG: EXIF (camera, GPS, date) and the ICC colour
 * profile of the upload are lost and the original is not kept. After GD has
 * written the resized photo, jpeg_copy_metadata() inserts the original EXIF
 * APP1 segment and ICC APP2 segment(s) byte for byte. Inside the EXIF block
 * only fixed-size values are patched in place, so every offset (MakerNote
 * included) stays valid:
 *   - Orientation = 1 (GD already rotated the pixels; anything else would
 *     rotate them a second time in viewers and in generate_photo_thumb())
 *   - PixelXDimension / PixelYDimension = real size of the resized photo
 *   - IFD1 is unlinked, so the embedded preview (old orientation) is ignored
 * XMP is not copied: phones keep motion-photo/depth pointers there that refer
 * to data the resized file no longer has.
 *
 * Any parse problem means "no metadata", never a failed upload.
 */

/**
 * Returns [segments] of the APP markers before SOS: ['marker' => int, 'data' => string]
 * where data is the payload without marker and length bytes; null if not a JPEG.
 */
function jpeg_read_app_segments(string $bytes): ?array {
    if (strncmp($bytes, "\xFF\xD8", 2) !== 0) return null;
    $len = strlen($bytes);
    $pos = 2;
    $out = [];
    while ($pos + 4 <= $len) {
        if ($bytes[$pos] !== "\xFF") return $out;
        $marker = ord($bytes[$pos + 1]);
        if ($marker === 0xFF) { $pos++; continue; }          // fill byte
        if ($marker === 0xDA || $marker === 0xD9) break;      // SOS / EOI
        $segLen = unpack('n', substr($bytes, $pos + 2, 2))[1];
        if ($segLen < 2 || $pos + 2 + $segLen > $len) return $out;
        $out[] = ['marker' => $marker, 'data' => substr($bytes, $pos + 4, $segLen - 2)];
        $pos += 2 + $segLen;
    }
    return $out;
}

/**
 * Patches an EXIF payload ("Exif\0\0" + TIFF) in place. Returns null when the
 * TIFF structure is not readable.
 */
function jpeg_patch_exif(string $exif, int $width, int $height): ?string {
    if (strncmp($exif, "Exif\0\0", 6) !== 0) return null;
    $base = 6;
    $len  = strlen($exif);
    if ($len < $base + 8) return null;
    $order = substr($exif, $base, 2);
    if ($order !== 'II' && $order !== 'MM') return null;
    $le = $order === 'II';

    $u16 = static function (int $off) use (&$exif, $le, $len): ?int {
        if ($off < 0 || $off + 2 > $len) return null;
        return unpack($le ? 'v' : 'n', substr($exif, $off, 2))[1];
    };
    $u32 = static function (int $off) use (&$exif, $le, $len): ?int {
        if ($off < 0 || $off + 4 > $len) return null;
        return unpack($le ? 'V' : 'N', substr($exif, $off, 4))[1];
    };
    $put16 = static function (int $off, int $v) use (&$exif, $le): void {
        $exif = substr_replace($exif, pack($le ? 'v' : 'n', $v), $off, 2);
    };
    $put32 = static function (int $off, int $v) use (&$exif, $le): void {
        $exif = substr_replace($exif, pack($le ? 'V' : 'N', $v), $off, 4);
    };
    // Value field of a SHORT/LONG entry with count 1 (stored inline in the entry).
    $setInline = static function (int $entry, int $v) use ($u16, $u32, $put16, $put32): void {
        $type  = $u16($entry + 2);
        $count = $u32($entry + 4);
        if ($count !== 1) return;
        if ($type === 3) {                       // SHORT: first 2 bytes of the field
            $put16($entry + 8, $v);
        } elseif ($type === 4) {                 // LONG
            $put32($entry + 8, $v);
        }
    };

    $ifd0 = $u32($base + 4);
    if ($ifd0 === null) return null;
    $ifd0 += $base;
    $n = $u16($ifd0);
    if ($n === null || $ifd0 + 2 + $n * 12 + 4 > $len) return null;

    $exifIfd = null;
    for ($i = 0; $i < $n; $i++) {
        $e   = $ifd0 + 2 + $i * 12;
        $tag = $u16($e);
        if ($tag === 0x0112) {                   // Orientation
            $setInline($e, 1);
        } elseif ($tag === 0x8769) {             // ExifIFD pointer
            $p = $u32($e + 8);
            $exifIfd = $p === null ? null : $p + $base;
        }
    }
    $put32($ifd0 + 2 + $n * 12, 0);              // unlink IFD1 (embedded preview)

    if ($exifIfd !== null) {
        $m = $u16($exifIfd);
        if ($m !== null && $exifIfd + 2 + $m * 12 <= $len) {
            for ($i = 0; $i < $m; $i++) {
                $e   = $exifIfd + 2 + $i * 12;
                $tag = $u16($e);
                if ($tag === 0xA002) $setInline($e, $width);   // PixelXDimension
                if ($tag === 0xA003) $setInline($e, $height);  // PixelYDimension
            }
        }
    }
    return $exif;
}

/**
 * Copies EXIF and ICC from $srcPath into the GD-written JPEG $destPath.
 * Returns true if something was copied. Never throws.
 */
function jpeg_copy_metadata(string $srcPath, string $destPath): bool {
    try {
        $src  = @file_get_contents($srcPath);
        $dest = @file_get_contents($destPath);
        if ($src === false || $dest === false) return false;
        $srcSegs  = jpeg_read_app_segments($src);
        $destSegs = jpeg_read_app_segments($dest);
        if ($srcSegs === null || $destSegs === null) return false;

        $size = @getimagesize($destPath);
        if (!$size) return false;

        $insert = '';
        foreach ($srcSegs as $seg) {
            $data = $seg['data'];
            if ($seg['marker'] === 0xE1 && strncmp($data, "Exif\0\0", 6) === 0) {
                $data = jpeg_patch_exif($data, (int)$size[0], (int)$size[1]);
                if ($data === null) continue;
            } elseif (!($seg['marker'] === 0xE2 && strncmp($data, "ICC_PROFILE\0", 12) === 0)) {
                continue;
            }
            $insert .= "\xFF" . chr($seg['marker']) . pack('n', strlen($data) + 2) . $data;
        }
        if ($insert === '') return false;

        // After SOI and GD's JFIF APP0, before everything else.
        $at = 2;
        if ($destSegs && $destSegs[0]['marker'] === 0xE0) {
            $at += 4 + strlen($destSegs[0]['data']);
        }
        $out = substr($dest, 0, $at) . $insert . substr($dest, $at);

        $tmp = $destPath . '.meta.tmp';
        if (@file_put_contents($tmp, $out) !== strlen($out) || !@rename($tmp, $destPath)) {
            @unlink($tmp);
            return false;
        }
        return true;
    } catch (\Throwable $e) {
        error_log('jpeg_metadata: ' . $e->getMessage() . " ($srcPath)");
        return false;
    }
}
