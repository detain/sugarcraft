<?php

declare(strict_types=1);

namespace SugarCraft\Image;

use SugarCraft\Image\Codec\RgbaPixels;

/**
 * Pure-PHP PNG pixel decoder for still images, with no GD quantisation.
 *
 * GD's `imagecreatefromstring()` reads 8-bit alpha down to 7 bits, so a pixel at
 * alpha 254 comes back fully opaque. Callers that classify transparency (mask
 * ingest) need the stored samples, so this decoder walks the PNG container by
 * hand and returns straight-alpha RGBA8.
 *
 * Scope matches {@see ApngDecoder}: bit depth 8, non-interlaced, colour types
 * 0 (gray), 2 (RGB), 3 (palette), 4 (gray+alpha) and 6 (RGBA). Anything else —
 * 16-bit, interlaced, a malformed or CRC-failing stream, a palette index past
 * PLTE — yields null so the caller can fall back to GD, which handles those
 * shapes. The chunk walk, scanline unfilter and colour expansion are shared
 * with ApngDecoder's frame path rather than kept as a second copy.
 */
final class PngPixels
{
    /** PNG signature — the fixed 8 bytes every PNG stream opens with. */
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** Colour type → samples per pixel (valid only for bit depth 8). */
    private const CHANNELS = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];

    /**
     * Largest image {@see decode()} accepts by default. Tied to the
     * {@see ImageSource} admission cap, so a still PNG the loader admits is also
     * decodable here.
     */
    public const MAX_PIXELS = ImageSource::MAX_PIXELS;

    /**
     * Raw-scanline ceiling for {@see decode()}: the bytes an image inflates to,
     * `height × (width × channels + 1)` with the filter bytes included. The value
     * is the former MaskIngest::MAX_IDAT_RAW_BYTES, so the set of stills declined
     * for size is unchanged: gray and gray+alpha up to the pixel ceiling, RGBA up
     * to 4095², and a 4096² RGBA frame declined.
     */
    public const MAX_RAW_BYTES = 67_108_864;

    /** Hard ceiling on each dimension, matching {@see ApngDecoder}. */
    private const HARD_MAX_DIM = 16384;

    private function __construct() {}

    /**
     * Decode the base image of a PNG stream to straight-alpha RGBA8.
     *
     * For an APNG this is the default (IDAT) image, which is what a non-animated
     * viewer shows. Returns null, never throws, for anything this decoder does
     * not handle: a non-PNG prefix, a malformed or CRC-failing chunk stream, an
     * unsupported shape, a declared size over $maxPixels or over
     * {@see MAX_RAW_BYTES}, or a palette index past PLTE.
     *
     * Returns the {@see RgbaPixels} value object (width, height and buffer) rather
     * than a bare buffer, because every caller needs the geometry with the bytes.
     *
     * @param int $maxPixels Largest width×height accepted. A non-positive value
     *                       declines every image.
     */
    public static function decode(string $bytes, int $maxPixels = self::MAX_PIXELS): ?RgbaPixels
    {
        if (strlen($bytes) < 8 || substr($bytes, 0, 8) !== self::SIGNATURE) {
            return null;
        }

        try {
            $chunks = self::walkChunks($bytes, 8);
            if ($chunks === [] || $chunks[0]['type'] !== 'IHDR') {
                return null;
            }

            [$width, $height, $channels, $colorType] = self::parseIhdr($chunks[0]['data']);
            if ($width < 1 || $height < 1 || $width > self::HARD_MAX_DIM || $height > self::HARD_MAX_DIM) {
                return null;
            }
            // Dimensions are bounded above, so the product cannot overflow int.
            if ($width * $height > $maxPixels) {
                return null;
            }
            // Gate on the scanline bytes the inflate would produce, for every colour
            // type. A pixel count alone would admit a 4096² RGBA frame (64 MiB of
            // samples plus filter bytes) that the raw budget declines.
            if ($height * ($width * $channels + 1) > self::MAX_RAW_BYTES) {
                return null;
            }

            $idat = '';
            foreach ($chunks as $chunk) {
                if ($chunk['type'] === 'IDAT') {
                    $idat .= $chunk['data'];
                }
            }
            if ($idat === '') {
                return null;
            }

            $palette = self::parsePalette($chunks);
            $trns = self::parseTrns($chunks);
            if ($colorType === 3 && $palette === []) {
                return null;
            }

            $rgba = self::inflateAndUnfilter($idat, $width, $height, $channels, $colorType, $palette, $trns);

            return new RgbaPixels($width, $height, $rgba);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    // ─── Shared primitives (also driven by ApngDecoder) ─────────────────────

    /**
     * Walk length-prefixed PNG chunks, verifying each CRC.
     *
     * @return list<array{type: string, data: string}>
     * @throws \InvalidArgumentException  on a truncated stream or a bad CRC
     * @internal Shared PNG container primitive (see {@see PngPixels}).
     */
    public static function walkChunks(string $bytes, int $offset): array
    {
        $len = strlen($bytes);
        $out = [];

        while ($offset < $len) {
            // Each chunk: 4-byte length, 4-byte type, `length` data, 4-byte CRC.
            if ($offset + 8 > $len) {
                throw new \InvalidArgumentException(Lang::t('apng.truncated'));
            }
            $un = unpack('N', substr($bytes, $offset, 4));
            $dataLen = $un === false ? 0 : $un[1];
            $type = substr($bytes, $offset + 4, 4);

            // A PNG chunk type is exactly four ASCII letters (ISO 15948 §5.3). The
            // CRC-failure message below interpolates the type, and this is a TUI
            // library whose product is terminal bytes — never let raw attacker
            // bytes reach an exception string. Reject anything else up front.
            if (preg_match('/^[A-Za-z]{4}$/', $type) !== 1) {
                throw new \InvalidArgumentException(Lang::t('apng.bad_chunk_type', ['type' => bin2hex($type)]));
            }

            $dataStart = $offset + 8;
            $crcStart  = $dataStart + $dataLen;
            if ($crcStart + 4 > $len) {
                throw new \InvalidArgumentException(Lang::t('apng.truncated'));
            }

            $data = substr($bytes, $dataStart, $dataLen);
            $crcUn = unpack('N', substr($bytes, $crcStart, 4));
            $storedCrc = $crcUn === false ? 0 : $crcUn[1];
            if ($storedCrc !== crc32($type . $data)) {
                throw new \InvalidArgumentException(Lang::t('apng.bad_chunk_crc', ['type' => $type]));
            }

            $out[] = ['type' => $type, 'data' => $data];

            if ($type === 'IEND') {
                break;
            }
            $offset = $crcStart + 4;
        }

        return $out;
    }

    /**
     * @param string $data IHDR chunk payload.
     * @return array{int,int,int,int}  [width, height, channels, colorType]
     * @internal Shared PNG container primitive (see {@see PngPixels}).
     */
    public static function parseIhdr(string $data): array
    {
        if (strlen($data) < 13) {
            throw new \InvalidArgumentException(Lang::t('apng.no_ihdr'));
        }
        $u = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccomp/Cfilter/Cinterlace', $data);
        if ($u === false) {
            throw new \InvalidArgumentException(Lang::t('apng.no_ihdr'));
        }

        if ($u['depth'] !== 8) {
            throw new \InvalidArgumentException(Lang::t('apng.unsupported_bit_depth', ['depth' => $u['depth']]));
        }
        if ($u['interlace'] !== 0) {
            throw new \InvalidArgumentException(Lang::t('apng.unsupported_interlace'));
        }
        // Only deflate/inflate compression (0) and adaptive filtering (0) exist;
        // any other value is a future/invalid revision we must not guess at.
        if ($u['comp'] !== 0 || $u['filter'] !== 0) {
            throw new \InvalidArgumentException(Lang::t('apng.unsupported_method'));
        }
        if (!isset(self::CHANNELS[$u['color']])) {
            throw new \InvalidArgumentException(Lang::t('apng.unsupported_color_type', ['type' => $u['color']]));
        }

        return [(int) $u['width'], (int) $u['height'], self::CHANNELS[$u['color']], (int) $u['color']];
    }

    /**
     * @param list<array{type: string, data: string}> $chunks
     * @return list<int>  flat RGB triples (0..255) from PLTE
     * @internal Shared PNG container primitive (see {@see PngPixels}).
     */
    public static function parsePalette(array $chunks): array
    {
        foreach ($chunks as $c) {
            if ($c['type'] === 'PLTE') {
                // PLTE is a sequence of whole RGB triples, 1..256 of them. A torn
                // length is corruption — refuse rather than read a split entry.
                if (strlen($c['data']) % 3 !== 0 || strlen($c['data']) > 768) {
                    throw new \InvalidArgumentException(Lang::t('apng.bad_plte', ['bytes' => strlen($c['data'])]));
                }

                return array_values(unpack('C*', $c['data']) ?: []);
            }
        }

        return [];
    }

    /**
     * @param list<array{type: string, data: string}> $chunks
     * @return list<int>  per-index alpha (0..255) from tRNS, empty when absent
     * @internal Shared PNG container primitive (see {@see PngPixels}).
     */
    public static function parseTrns(array $chunks): array
    {
        foreach ($chunks as $c) {
            if ($c['type'] === 'tRNS') {
                return array_values(unpack('C*', $c['data']) ?: []);
            }
        }

        return [];
    }

    /**
     * Inflate a frame's zlib stream and reverse PNG per-scanline filtering,
     * returning an RGBA buffer (fw×fh×4). Colour type 3 (palette) is expanded to
     * RGBA using PLTE + tRNS; every other supported type maps channels directly.
     *
     * @param list<int> $palette
     * @param list<int> $trns
     * @internal Shared PNG container primitive (see {@see PngPixels}).
     */
    public static function inflateAndUnfilter(string $zlibData, int $fw, int $fh, int $channels, int $colorType, array $palette, array $trns): string
    {
        if ($zlibData === '') {
            throw new \InvalidArgumentException(Lang::t('apng.no_frame_data'));
        }

        // Stride in bytes of the raw (pre-filter) scanline.
        $stride = $fw * $channels;
        $expected = $fh * ($stride + 1); // + filter byte per row

        // Bound the inflate ITSELF to the declared geometry before a single byte
        // materialises: PHP stops decompressing at $expected rather than building a
        // multi-megabyte buffer for a tiny frame (decompression bomb). A well-formed
        // stream inflates to EXACTLY $expected (ISO 15948 §6.3 — one filter byte plus
        // one stride per scanline), so anything larger is a bomb and anything smaller
        // is truncation; both are refused.
        $inflated = @gzuncompress($zlibData, $expected);
        if ($inflated === false) {
            // false means either a corrupt stream or one that would overflow the
            // ceiling — both fail loud, neither spends the memory.
            throw new \InvalidArgumentException(Lang::t('apng.inflate_failed'));
        }
        if (strlen($inflated) !== $expected) {
            throw new \InvalidArgumentException(Lang::t('apng.bad_frame_length', [
                'actual'   => strlen($inflated),
                'expected' => $expected,
            ]));
        }

        // bytes-per-pixel for the filter reference (>=1; whole pixels for depth 8).
        $bpp = max(1, $channels);
        $prev = str_repeat("\0", $stride);
        $rgba = '';

        for ($row = 0; $row < $fh; $row++) {
            $filterType = ord($inflated[$row * ($stride + 1)]);
            $line = substr($inflated, $row * ($stride + 1) + 1, $stride);
            $cur = self::unfilterLine($line, $prev, $filterType, $bpp);
            $rgba .= self::expandRowToRgba($cur, $channels, $colorType, $fw, $palette, $trns);
            $prev = $cur;
        }

        return $rgba;
    }

    /**
     * Reverse the five PNG scanline filters for one row.
     *
     * @param string $line raw filtered bytes for the row (length stride)
     * @param string $prev reconstructed bytes of the row above (length stride)
     * @return string reconstructed row bytes
     */
    private static function unfilterLine(string $line, string $prev, int $filterType, int $bpp): string
    {
        $stride = strlen($line);
        $cur = str_repeat("\0", $stride);

        for ($i = 0; $i < $stride; $i++) {
            $rawByte = ord($line[$i]);
            $left = $i >= $bpp ? ord($cur[$i - $bpp]) : 0;
            $up = ord($prev[$i]);
            $upLeft = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;

            $recon = match ($filterType) {
                0       => $rawByte,
                1       => $rawByte + $left,
                2       => $rawByte + $up,
                3       => $rawByte + intdiv($left + $up, 2),
                4       => $rawByte + self::paeth($left, $up, $upLeft),
                default => throw new \InvalidArgumentException(Lang::t('apng.unsupported_filter', ['type' => $filterType])),
            };
            $cur[$i] = chr($recon & 0xFF);
        }

        return $cur;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }

        return $pb <= $pc ? $b : $c;
    }

    /**
     * Colour-type-specific row expansion (palette vs non-palette), invoked from
     * {@see inflateAndUnfilter()} where the palette is in scope.
     *
     * @param list<int> $palette
     * @param list<int> $trns
     */
    private static function expandRowToRgba(string $row, int $channels, int $colorType, int $fw, array $palette, array $trns): string
    {
        if ($colorType === 3) {
            $size = intdiv(count($palette), 3);
            $out = '';
            for ($x = 0; $x < $fw; $x++) {
                $idx = ord($row[$x]);
                // An index past the palette is a fatal spec violation — fail loud
                // rather than paint opaque black and hide the corruption.
                if (!isset($palette[$idx * 3 + 2])) {
                    throw new \InvalidArgumentException(Lang::t('apng.palette_index_out_of_range', [
                        'index' => $idx,
                        'size'  => $size,
                    ]));
                }
                $r = $palette[$idx * 3];
                $g = $palette[$idx * 3 + 1];
                $b = $palette[$idx * 3 + 2];
                // A tRNS shorter than the palette is legal: absent entries are opaque.
                $a = $trns[$idx] ?? 255;
                $out .= chr($r) . chr($g) . chr($b) . chr($a);
            }

            return $out;
        }

        $key = self::trnsColourKey($colorType, $trns);
        if ($channels === 4 && $key === null) {
            return substr($row, 0, $fw * 4);
        }

        // Filled as a byte list and packed once: appending per pixel to a
        // string rebuilds it on every iteration.
        $out = [];
        for ($x = 0; $x < $fw; $x++) {
            $base = $x * $channels;
            switch ($channels) {
                case 4:
                    $r = ord($row[$base]);
                    $g = ord($row[$base + 1]);
                    $b = ord($row[$base + 2]);
                    $a = ord($row[$base + 3]);
                    break;
                case 3:
                    $r = ord($row[$base]);
                    $g = ord($row[$base + 1]);
                    $b = ord($row[$base + 2]);
                    $a = 255;
                    break;
                case 2:
                    $r = $g = $b = ord($row[$base]);
                    $a = ord($row[$base + 1]);
                    break;
                case 1:
                    $r = $g = $b = ord($row[$base]);
                    $a = 255;
                    break;
                default:
                    throw new \InvalidArgumentException(Lang::t('apng.unsupported_color_type', ['type' => $channels]));
            }
            // A tRNS key marks exact colour matches fully transparent; the key is
            // compared on the stored samples, before any alpha is applied.
            if ($key !== null && $r === $key[0] && $g === $key[1] && $b === $key[2]) {
                $a = 0;
            }
            $out[] = $r;
            $out[] = $g;
            $out[] = $b;
            $out[] = $a;
        }

        return pack('C*', ...$out);
    }

    /**
     * The tRNS colour key for a gray (type 0) or RGB (type 2) image, as the
     * 8-bit sample triple it matches; null when there is no usable key.
     *
     * tRNS holds 16-bit big-endian samples. An 8-bit image can only match a key
     * whose high byte is zero, so any other key matches nothing.
     *
     * @param list<int> $trns  the tRNS payload, unpacked as bytes
     * @return array{int,int,int}|null
     */
    private static function trnsColourKey(int $colorType, array $trns): ?array
    {
        if ($colorType === 0 && count($trns) === 2 && $trns[0] === 0) {
            return [$trns[1], $trns[1], $trns[1]];
        }
        if ($colorType === 2 && count($trns) === 6 && $trns[0] === 0 && $trns[2] === 0 && $trns[4] === 0) {
            return [$trns[1], $trns[3], $trns[5]];
        }

        return null;
    }
}
