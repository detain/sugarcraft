<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests\Support;

/**
 * Pure-PHP builders for the tiny animated fixtures the {@see
 * \SugarCraft\Mosaic\ImageSource::fromAnimatedFile()} tests decode.
 *
 * PHP's GD writes neither animated GIFs nor APNGs, so the fixtures are assembled
 * here: APNGs byte-by-byte (correct PNG chunk framing), animated GIFs by splicing
 * GD's own per-frame LZW under a shared global table with hand-written Graphics
 * Control Extensions. Keeping the writers in the test tree (rather than committing
 * opaque binaries) means every byte the decoder asserts about is legible and
 * reproducible.
 */
final class AnimatedImageFixtures
{
    private function __construct() {}

    /**
     * Build a GIF89a with the given full-screen frames.
     *
     * Each frame is a flat list of palette indices (length w×h) plus a delay in
     * centiseconds, an optional transparent index, and a disposal method (0-3).
     * A single global colour table of `$colors` entries backs every frame.
     *
     * The LZW image data is not hand-rolled: each frame is rendered through GD's
     * own `imagegif()` and its Image Descriptor + LZW sub-blocks are spliced into
     * the assembly verbatim (mirroring the recipe {@see
     * \SugarCraft\Flip\Decoder} is built and tested against). A correct-by-
     * construction encoder here would still be undecodable by the sibling's
     * header-walk, which locates frame boundaries by following sub-block lengths —
     * only real, length-terminated LZW streams chain reliably under that scan.
     *
     * Palette index 0 MUST be opaque black: GD's `imagecreate()` claims index 0
     * for its implicit background, so the shared colour table is only faithful
     * when its first entry is that reserved black.
     *
     * @param int                 $w      Logical screen width.
     * @param int                 $h      Logical screen height.
     * @param list<array{0:int,1:int,2:int}> $colors Global colour table (RGB triples), colours[0] = [0,0,0].
     * @param list<array{pixels: list<int>, delay: int, transparent?: int, disposal?: int}> $frames
     */
    public static function gif(int $w, int $h, array $colors, array $frames): string
    {
        if ($colors === [] || $colors[0] !== [0, 0, 0]) {
            throw new \InvalidArgumentException('GIF fixture global colour table must start with opaque black at index 0.');
        }

        // GCT size field v encodes 2^(v+1) entries; start at 2 (v=0) and double.
        $entries = 2;
        $sizeField = 0;
        while ($entries < count($colors)) {
            $entries <<= 1;
            $sizeField++;
        }
        // Pad the table to the power-of-two length.
        $padded = $colors;
        while (count($padded) < $entries) {
            $padded[] = [0, 0, 0];
        }

        $packed = 0x80 | (($sizeField & 0x07)); // global table present, no sort, colour res 0
        $out = 'GIF89a'
            . pack('v', $w) . pack('v', $h)
            . chr($packed) . chr(0) . chr(0);
        foreach ($padded as [$r, $g, $b]) {
            $out .= chr($r) . chr($g) . chr($b);
        }

        foreach ($frames as $frame) {
            $gd = self::gdFrameBytes($w, $h, $padded, $frame['pixels']);
            $out .= self::composeFrameBlock(
                $gd,
                $frame['disposal'] ?? 0,
                ($frame['transparent'] ?? null) !== null,
                $frame['transparent'] ?? 0,
                $frame['delay'],
            );
        }

        return $out . "\x3B";
    }

    /**
     * Render one full-screen frame through GD and return its raw single-frame GIF.
     *
     * Colours are allocated in palette order so GD's internal index for a entry
     * equals its position — the shared global table then maps every spliced frame
     * back to the same RGB triples.
     *
     * @param list<array{0:int,1:int,2:int}> $palette
     * @param list<int>                      $indices  flat w×h palette indices
     */
    private static function gdFrameBytes(int $w, int $h, array $palette, array $indices): string
    {
        $image = imagecreate($w, $h);
        foreach ($palette as [$r, $g, $b]) {
            imagecolorallocate($image, $r, $g, $b);
        }
        foreach ($indices as $position => $index) {
            imagesetpixel($image, $position % $w, intdiv($position, $w), $index);
        }

        ob_start();
        imagegif($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * Re-wrap GD's Image Descriptor + LZW block with our own Graphics Control
     * Extension (disposal, transparency, delay) and a cleared descriptor packed
     * byte (no local table, no interlace) so the shared global table applies.
     */
    private static function composeFrameBlock(string $gd, int $disposal, bool $transparent, int $transparentIndex, int $delayCs): string
    {
        [$descriptor, $lzw] = self::extractDescriptorAndLzw($gd);

        $graphicsControl = "\x21\xF9\x04"
            . chr((($disposal & 0x07) << 2) | ($transparent ? 0x01 : 0x00))
            . pack('v', $delayCs)
            . chr($transparent ? $transparentIndex : 0)
            . "\x00";

        return $graphicsControl . substr($descriptor, 0, 9) . "\x00" . $lzw;
    }

    /**
     * Pull the 10-byte Image Descriptor and its LZW data (min-code byte through
     * the terminating sub-block, excluding the trailing 0x3B) out of a GD single-
     * frame GIF, skipping any extension blocks GD may have emitted.
     *
     * @return array{0: string, 1: string}
     */
    private static function extractDescriptorAndLzw(string $gif): array
    {
        $packed = ord($gif[10]);
        $tableBytes = ($packed & 0x80) ? ((1 << (($packed & 0x07) + 1)) * 3) : 0;
        $cursor = 13 + $tableBytes;
        $length = strlen($gif);

        while ($cursor < $length) {
            $marker = ord($gif[$cursor]);
            if ($marker === 0x3B) {
                break;
            }
            if ($marker === 0x21) {
                if (ord($gif[$cursor + 1]) === 0xF9) {
                    $cursor += 8; // fixed-size Graphics Control Extension
                } else {
                    $walker = $cursor + 2;
                    while ($walker < $length) {
                        $subLen = ord($gif[$walker]);
                        $walker++;
                        if ($subLen === 0) {
                            break;
                        }
                        $walker += $subLen;
                    }
                    $cursor = $walker;
                }
                continue;
            }
            if ($marker === 0x2C) {
                return [
                    substr($gif, $cursor, 10),
                    substr($gif, $cursor + 10, ($length - 1) - ($cursor + 10)),
                ];
            }
            $cursor++;
        }

        throw new \RuntimeException('GD GIF carries no Image Descriptor.');
    }

    /**
     * Build an APNG (colour type 6, bit depth 8) from full-canvas-sized frames.
     *
     * Each frame declares its own rectangle, delay fraction, dispose op and blend
     * op so the decoder's state machine can be exercised precisely. The base PNG
     * stream (IHDR/IDAT/IEND) is itself a valid still image, and `acTL` + the
     * per-frame `fcTL`/`fdAT` chunks turn it into an animation.
     *
     * @param int $canvasW
     * @param int $canvasH
     * @param list<array{
     *     w: int, h: int, x: int, y: int,
     *     delayNum: int, delayDen: int, dispose: int, blend: int,
     *     pixels: list<array{0:int,1:int,2:int,3:int}>
     * }> $frames
     */
    public static function apng(int $canvasW, int $canvasH, array $frames): string
    {
        $bytes = "\x89PNG\r\n\x1a\n";
        $bytes .= self::chunk('IHDR', pack('NN', $canvasW, $canvasH) . chr(8) . chr(6) . chr(0) . chr(0) . chr(0));
        $bytes .= self::chunk('acTL', pack('N', count($frames)) . pack('N', 0));

        $seq = 0;
        foreach ($frames as $i => $frame) {
            $bytes .= self::chunk('fcTL', pack('N', $seq++)
                . pack('N', $frame['w']) . pack('N', $frame['h'])
                . pack('N', $frame['x']) . pack('N', $frame['y'])
                . pack('n', $frame['delayNum']) . pack('n', $frame['delayDen'])
                . chr($frame['dispose']) . chr($frame['blend']));

            $filtered = self::filteredScanlines($frame['pixels'], $frame['w'], $frame['h']);
            $compressed = gzcompress($filtered);

            if ($i === 0) {
                // First frame reuses the base image data (IDAT).
                $bytes .= self::chunk('IDAT', $compressed);
            } else {
                $bytes .= self::chunk('fdAT', pack('N', $seq++) . $compressed);
            }
        }

        return $bytes . self::chunk('IEND', '');
    }

    /**
     * RGBA pixels → filter-type-0 (None) scanlines with a leading byte per row.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $pixels  w×h RGBA
     */
    private static function filteredScanlines(array $pixels, int $w, int $h): string
    {
        $out = '';
        for ($y = 0; $y < $h; $y++) {
            $out .= "\x00"; // filter None
            for ($x = 0; $x < $w; $x++) {
                [$r, $g, $b, $a] = $pixels[$y * $w + $x];
                $out .= chr($r) . chr($g) . chr($b) . chr($a);
            }
        }

        return $out;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /**
     * Public chunk assembler so a test can hand-craft an adversarial stream
     * (a lying `acTL`, an oversized inflate, a non-ASCII chunk type, a torn
     * `PLTE`) without the decoder's normal-bytes path masking the defect.
     *
     * @param list<array{type:string,data:string}> $chunks  ordered chunks after the signature
     */
    public static function png(array $chunks): string
    {
        $bytes = "\x89PNG\r\n\x1a\n";
        foreach ($chunks as $c) {
            $bytes .= self::chunk($c['type'], $c['data']);
        }

        return $bytes;
    }

    /**
     * IHDR payload for a colour-type-6 / depth-8 / non-interlaced canvas.
     */
    public static function ihdr(int $w, int $h, int $colorType = 6): string
    {
        return pack('NN', $w, $h) . chr(8) . chr($colorType) . chr(0) . chr(0) . chr(0);
    }

    /**
     * acTL payload declaring a frame count that may DISAGREE with the frames the
     * stream actually ships — the C1 decompression-bomb vector.
     */
    public static function actl(int $declaredFrames, int $plays = 0): string
    {
        return pack('N', $declaredFrames) . pack('N', $plays);
    }

    /**
     * fcTL payload with explicit geometry + ops, for hand-built adversarial frames.
     */
    public static function fctl(
        int $seq,
        int $w,
        int $h,
        int $x,
        int $y,
        int $delayNum,
        int $delayDen,
        int $dispose = 0,
        int $blend = 0,
    ): string {
        return pack('N', $seq) . pack('N', $w) . pack('N', $h) . pack('N', $x) . pack('N', $y)
            . pack('n', $delayNum) . pack('n', $delayDen) . chr($dispose) . chr($blend);
    }

    /**
     * A valid full-canvas colour-type-6 frame's scanline data (filter None rows),
     * zlib-compressed — reused as IDAT (frame 0) or the body of an fdAT.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $pixels
     */
    public static function frameStream(array $pixels, int $w, int $h, int $channels = 4, int $filterByte = 0): string
    {
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= chr($filterByte);
            for ($x = 0; $x < $w; $x++) {
                $px = $pixels[$y * $w + $x];
                $raw .= chr($px[0]);
                for ($c = 1; $c < $channels; $c++) {
                    $raw .= chr($px[$c] ?? 0);
                }
            }
        }

        return gzcompress($raw);
    }
}
