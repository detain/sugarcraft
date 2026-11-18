<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Media;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Media\ImageIngress;
use SugarCraft\Code\Media\MaskIngest;

/**
 * Plan W4.3 mask-ingress law: a mask file is either a luminance map
 * (strictly black-and-white, white = inpaint) or carries transparency
 * (ANY even slightly transparent area becomes the mask — §2.7), and a
 * file that is neither is refused loudly. Classification rides a
 * byte-exact PNG walk so a single alpha=254 pixel is caught even though
 * GD quantises 8-bit alpha down to 7 bits on load and would read that
 * pixel opaque; exotic PNG variants (interlaced, 16-bit, palette) fall
 * through to the GD sweep. The payload is always the raw bytes, and the
 * mode/advisory are presentation-only — never serialized to the wire.
 */
final class MaskIngestTest extends TestCase
{
    private const MASK_ALPHA = __DIR__ . '/../fixtures/img/mask-alpha.png';
    private const MASK_LUMA = __DIR__ . '/../fixtures/img/mask-luma.png';
    private const MASK_NEITHER = __DIR__ . '/../fixtures/img/mask-neither.png';
    private const MASK_ALPHA254 = __DIR__ . '/../fixtures/img/mask-alpha254.png';
    private const CORRUPT = __DIR__ . '/../fixtures/img/corrupt.png';

    public function testATransparentFixtureClassifiesAsAlpha(): void
    {
        $mask = MaskIngest::fromPath(self::MASK_ALPHA);

        $this->assertSame(MaskIngest::MODE_ALPHA, $mask->mode);
        $this->assertNull($mask->advisory, 'a varied mask carries no advisory');
        $this->assertSame('8×8', $mask->dimensions());
        $this->assertSame('mask-alpha.png', $mask->name());
    }

    public function testAGrayscaleFixtureClassifiesAsLuminance(): void
    {
        $mask = MaskIngest::fromPath(self::MASK_LUMA);

        $this->assertSame(MaskIngest::MODE_LUMINANCE, $mask->mode);
        $this->assertNull($mask->advisory);
    }

    public function testAnOpaqueColourFixtureIsRefusedLoudlyWithTheNeitherMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(MaskIngest::NEITHER_REFUSAL);

        MaskIngest::fromPath(self::MASK_NEITHER);
    }

    public function testASingleAlpha254PixelClassifiesAsAlphaWhereGdWouldReadOpaque(): void
    {
        // PHP 8.3 GD truncates the 8-bit→7-bit alpha conversion on load
        // (255-254=1 → 127*1/255 = 0), so imagecolorat reports this image
        // fully opaque. PngPixels reads the 254 and must win — the
        // §2.7 "any even slightly transparent area becomes mask" law in
        // its sharpest form: ONE pixel out of sixty-four is transparent.
        $mask = MaskIngest::fromPath(self::MASK_ALPHA254);

        $this->assertSame(MaskIngest::MODE_ALPHA, $mask->mode);
        $this->assertNull($mask->advisory, '63 opaque pixels and one 254 — varied, not uniform');
    }

    public function testThePayloadIsTheRawFileBytesByteForByte(): void
    {
        $bytes = (string) file_get_contents(self::MASK_LUMA);
        $mask = MaskIngest::fromPath(self::MASK_LUMA);

        $this->assertSame(base64_encode($bytes), $mask->base64());
        $this->assertSame($bytes, $mask->source->bytes, 'no re-encode, no resize (§4.3 raw)');
    }

    public function testCorruptBytesFallThroughTheImageIngressDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GD failed to load image');

        MaskIngest::fromBytes((string) file_get_contents(self::CORRUPT), 'corrupt.png');
    }

    public function testADamagedTransparencyChunkFallsThroughToGdAndIsNotReadAsAKey(): void
    {
        // PngPixels declines a stream with any failed CRC, so the damaged tRNS key
        // is never applied. libpng drops a bad ancillary chunk and still loads the
        // picture, so the GD pixel walk classifies the opaque gray pixels: luminance.
        // The intact file is alpha, which is what the damaged copy must NOT keep.
        $good = self::rawPng(2, 2, 0, str_repeat("\x00\x00\xff", 2), 8, "\x00\x00");
        $this->assertSame(MaskIngest::MODE_ALPHA, MaskIngest::fromBytes($good, 'good.png')->mode, 'the intact file still classifies');

        // Signature (8) + IHDR chunk (25) put tRNS at bytes 33..46: length, type,
        // two data bytes, then the CRC whose last byte is 46.
        $damaged = $good;
        $damaged[46] = chr(ord($good[46]) ^ 0x01);

        $mask = MaskIngest::fromBytes($damaged, 'damaged.png');
        $this->assertSame(MaskIngest::MODE_LUMINANCE, $mask->mode, 'the damaged key is dropped, not applied');
    }

    public function testADamagedAncillaryChunkStillClassifiesAsItDidBeforeTheCrcCheck(): void
    {
        // A bad CRC on a chunk the walk never reads (tEXt) must not refuse the file:
        // PngPixels declines it, GD drops the chunk and loads the picture, and the
        // pixels classify exactly as they do with the chunk intact.
        $rows = "\x00\x00\xff\x00\x00\xff";
        $text = self::chunk('tEXt', "Comment\x00hello");
        $text[strlen($text) - 1] = chr(ord($text[strlen($text) - 1]) ^ 0x01);
        $png = "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', \pack('NNCCCCC', 2, 2, 8, 0, 0, 0, 0))
            . $text
            . self::chunk('IDAT', \gzcompress($rows))
            . self::chunk('IEND', '');

        $mask = MaskIngest::fromBytes($png, 'damaged-text.png');

        $this->assertSame(MaskIngest::MODE_LUMINANCE, $mask->mode);
        $this->assertNull($mask->advisory, 'half black, half white: varied, so no advisory');
    }

    public function testNotAnImageAtAllIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image format:');

        MaskIngest::fromBytes('this is not a png at all', 'notes.txt');
    }

    public function testRemoteLookingPathsKeepTheUrlRefusal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ImageIngress::URL_REFUSAL);

        MaskIngest::fromPath('http://example.com/mask.png');
    }

    public function testFromBytesClassifiesTheSamePolaritiesAsFromPath(): void
    {
        $alpha = MaskIngest::fromBytes((string) file_get_contents(self::MASK_ALPHA), 'clip.png');
        $this->assertSame(MaskIngest::MODE_ALPHA, $alpha->mode);

        $luma = MaskIngest::fromBytes((string) file_get_contents(self::MASK_LUMA), 'clip.png');
        $this->assertSame(MaskIngest::MODE_LUMINANCE, $luma->mode);
    }

    // ---- uniform advisories (advisory, never a refusal) ---------------------

    public function testAFlatWhiteLuminanceMaskIsLegalWithACoversEverythingAdvisory(): void
    {
        $mask = MaskIngest::fromBytes(self::flatPng(2, 2, 0, "\xFF\xFF"), 'flat-white.png');

        $this->assertSame(MaskIngest::MODE_LUMINANCE, $mask->mode);
        $this->assertSame(MaskIngest::ADVISORY_COVERS_EVERYTHING, $mask->advisory);
    }

    public function testAFlatBlackLuminanceMaskIsLegalWithACoversNothingAdvisory(): void
    {
        $mask = MaskIngest::fromBytes(self::flatPng(2, 2, 0, "\x00\x00"), 'flat-black.png');

        $this->assertSame(MaskIngest::MODE_LUMINANCE, $mask->mode);
        $this->assertSame(MaskIngest::ADVISORY_COVERS_NOTHING, $mask->advisory);
    }

    public function testAFlatMidGrayMaskGetsAUniformLevelAdvisory(): void
    {
        $mask = MaskIngest::fromBytes(self::flatPng(2, 2, 0, "\x7F\x7F"), 'flat-127.png');

        $this->assertSame(MaskIngest::MODE_LUMINANCE, $mask->mode);
        $this->assertSame(MaskIngest::ADVISORY_UNIFORM_LEVEL, $mask->advisory);
    }

    public function testAUniformlyTransparentChannelMaskIsAlphaCoveringEverything(): void
    {
        // 2x2 gray+alpha, every sample (255, alpha 0) → fully transparent.
        $mask = MaskIngest::fromBytes(self::flatPng(2, 2, 4, "\xFF\x00\xFF\x00"), 'flat-open.png');

        $this->assertSame(MaskIngest::MODE_ALPHA, $mask->mode);
        $this->assertSame(MaskIngest::ADVISORY_COVERS_EVERYTHING, $mask->advisory);
    }

    public function testAKeyedTransparentColorWhereEveryPixelMatchesIsAlphaCoveringEverything(): void
    {
        $trns = "\x00\xFF\x00\xFF\x00\xFF"; // key = (255,255,255)
        $mask = MaskIngest::fromBytes(
            self::flatPng(2, 1, 2, "\xFF\xFF\xFF\xFF\xFF\xFF", trns: $trns),
            'keyed-all.png',
        );

        $this->assertSame(MaskIngest::MODE_ALPHA, $mask->mode);
        $this->assertSame(MaskIngest::ADVISORY_COVERS_EVERYTHING, $mask->advisory);
    }

    public function testAKeyedTransparentColorWithNoMatchesStaysLuminance(): void
    {
        $trns = "\x00\x7F\x00\x7F\x00\x7F"; // key = (127,127,127), unused
        $mask = MaskIngest::fromBytes(
            self::flatPng(2, 1, 2, "\xFF\xFF\xFF\x00\x00\x00", trns: $trns),
            'keyed-none.png',
        );

        $this->assertSame(MaskIngest::MODE_LUMINANCE, $mask->mode);
        $this->assertNull($mask->advisory);
    }

    public function testAKeyedTransparentColorWithSomeMatchesIsAlphaWithoutAdvisory(): void
    {
        $trns = "\x00\xFF\x00\xFF\x00\xFF";
        $mask = MaskIngest::fromBytes(
            self::flatPng(2, 1, 2, "\xFF\xFF\xFF\x00\x00\x00", trns: $trns),
            'keyed-some.png',
        );

        $this->assertSame(MaskIngest::MODE_ALPHA, $mask->mode);
        $this->assertNull($mask->advisory);
    }

    public function testAnOpaqueChromaticKeyedImageIsStillRefused(): void
    {
        $trns = "\x00\x7F\x00\x7F\x00\x7F";
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(MaskIngest::NEITHER_REFUSAL);

        MaskIngest::fromBytes(
            self::flatPng(2, 1, 2, "\xFF\x00\x00\x00\xFF\x00", trns: $trns),
            'keyed-red-green.png',
        );
    }

    // ---- PNG filter-byte coverage -------------------------------------------

    public function testSubAndUpFilteredScanlinesUnfilterIntoTheSameVerdict(): void
    {
        // 2x2 RGBA, every pixel (255,255,255,254) → uniform alpha.
        // Row 0 Sub: first pixel raw, rest delta-left. Row 1 Up: delta-above.
        $rows = "\x01\xFF\xFF\xFF\xFE\x00\x00\x00\x00" . "\x02\x00\x00\x00\x00\x00\x00\x00\x00";
        $mask = MaskIngest::fromBytes(self::rawPng(2, 2, 6, $rows), 'sub-up.png');

        $this->assertSame(MaskIngest::MODE_ALPHA, $mask->mode);
        $this->assertSame(MaskIngest::ADVISORY_COVERS_EVERYTHING, $mask->advisory);
    }

    public function testAverageAndPaethFilteredScanlinesUnfilterIntoTheSameVerdict(): void
    {
        // 2x2 gray, all 255. Row 0 Average: x0 pred=(0+0)/2=0 → 255;
        // x1 pred=(255+0)/2=127 → 128. Row 1 Paeth: x0 pred=b=255 → 0;
        // x1 pred=a=255 → 0.  (2 gray bytes per row + filter byte)
        $rows = "\x03\xFF\x80\x04\x00\x00";
        $mask = MaskIngest::fromBytes(self::rawPng(2, 2, 0, $rows), 'avg-paeth.png');

        $this->assertSame(MaskIngest::MODE_LUMINANCE, $mask->mode);
        $this->assertSame(MaskIngest::ADVISORY_COVERS_EVERYTHING, $mask->advisory);
    }

    public function testSubFilteredRgbaWithRealValuesKeepsPerChannelTruth(): void
    {
        // 1x2 RGBA: (255,0,0,255) then (0,255,0,128). Sub encodes per-byte
        // deltas with bpp=4 alignment: FF 00 00 FF, then 0-FF=01, FF-00=FF,
        // 0-00=00, 80-FF=E5.
        $rows = "\x01\xFF\x00\x00\xFF\x01\x01\xFF\x00\xE5";
        $mask = MaskIngest::fromBytes(self::rawPng(1, 2, 6, $rows), 'sub-colors.png');

        $this->assertSame(MaskIngest::MODE_ALPHA, $mask->mode, 'alpha 128 < 255 anywhere');
        $this->assertNull($mask->advisory);
    }

    public function testA1024SquareRgbaMaskClassifiesInLinearTime(): void
    {
        // MaskIngest audit row 2 (the unfilter that PngPixels now owns):
        // the per-scanline `[...$samples, ...$current]` re-copied every earlier
        // row, so a 1024x1024 RGBA mask took 31 s to walk (33 s on the measuring
        // box) and ~136 MB at peak. The offset-write walk does the same work in
        // ~3 s. The budget is 15 s: generous enough for a slow CI runner, and a
        // quadratic walk exceeds it on any machine that measured the original.
        $side = 1024;
        $row = "\x00" . \str_repeat("\x7F\x7F\x7F\xFE", $side);
        $png = self::rawPng($side, $side, 6, \str_repeat($row, $side));

        $start = \hrtime(true);
        $mask = MaskIngest::fromBytes($png, 'square.png');
        $seconds = (\hrtime(true) - $start) / 1e9;

        $this->assertSame(MaskIngest::MODE_ALPHA, $mask->mode);
        $this->assertSame(MaskIngest::ADVISORY_COVERS_EVERYTHING, $mask->advisory, 'every pixel carries alpha 254');
        $this->assertLessThan(15.0, $seconds, sprintf('1024x1024 RGBA classified in %.2f s; budget 15 s', $seconds));
    }

    // ---- GD fallback paths ----------------------------------------------------

    public function testAPalettePngWithTransparentIndexClassifiesAsAlpha(): void
    {
        $im = \imagecreate(2, 2) ?: self::fail('GD unavailable');
        $white = \imagecolorallocate($im, 255, 255, 255);
        $hole = \imagecolorallocate($im, 0, 0, 0);
        \imagefill($im, 0, 0, $white);
        \imagesetpixel($im, 0, 0, $hole);
        \imagecolortransparent($im, $hole);
        $bytes = self::capture($im);

        $mask = MaskIngest::fromBytes($bytes, 'palette.png');

        $this->assertSame(MaskIngest::MODE_ALPHA, $mask->mode);
    }

    public function testAnInterlacedPngFallsThroughPngPixelsToGdAndStillClassifies(): void
    {
        $im = \imagecreate(4, 4) ?: self::fail('GD unavailable');
        $white = \imagecolorallocate($im, 255, 255, 255);
        $black = \imagecolorallocate($im, 0, 0, 0);
        \imagefill($im, 0, 0, $white);
        \imagefilledrectangle($im, 0, 0, 1, 3, $black);
        \imageinterlace($im, true);
        $bytes = self::capture($im);

        $mask = MaskIngest::fromBytes($bytes, 'interlaced.png');

        $this->assertSame(MaskIngest::MODE_LUMINANCE, $mask->mode, 'opaque grayscale');
        $this->assertNull($mask->advisory, 'half black, half white');
    }

    public function testA16BitPngFallsThroughPngPixelsToGd(): void
    {
        // depth-16 grayscale, all samples 0xFFFF → white. PngPixels declines
        // depth != 8 and GD resolves the picture.
        $bytes = self::rawPng(2, 2, 0, "\x00\xFF\xFF\xFF\xFF\x00\xFF\xFF\xFF\xFF", depth: 16);

        $mask = MaskIngest::fromBytes($bytes, 'sixteen.png');

        $this->assertSame(MaskIngest::MODE_LUMINANCE, $mask->mode);
        $this->assertSame(MaskIngest::ADVISORY_COVERS_EVERYTHING, $mask->advisory);
    }

    // ---- W7 seam cure: capped IDAT inflate (PngPixels owns the cap) ---------------

    public function testAnOverCapIdatBombRidesTheGdFallbackWithTheSameVerdict(): void
    {
        // MED-1 family (sibling of the Png tEXt cure): a 1×1 header paired
        // with a ratio-bomb IDAT. libpng inflates only the declared rows —
        // "Too much image data" is a warning, so ImageIngress ADMITS the
        // file. PngPixels caps its inflate at the declared scanline budget and
        // declines, and GD answers; the verdict is the one GD gives the picture.
        $png = "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', \pack('NNCCCCC', 1, 1, 8, 0, 0, 0, 0))
            . self::chunk('IDAT', \gzcompress("\x00\xFF" . \str_repeat("\x00", 200_000)))
            . self::chunk('IEND', '');

        $mask = MaskIngest::fromBytes($png, 'bomb.png');

        $this->assertSame(MaskIngest::MODE_LUMINANCE, $mask->mode, 'one white pixel via GD');
        $this->assertSame(MaskIngest::ADVISORY_COVERS_EVERYTHING, $mask->advisory);
    }

    // ---- builders --------------------------------------------------------------

    /**
     * Hand-built PNG from ONE raw scanline (no filter byte): every row gets
     * filter 0 (None). Flat/advisory fixtures use this.
     *
     * @param 0|2|4|6 $colorType
     */
    private static function flatPng(int $w, int $h, int $colorType, string $rowSamples, int $depth = 8, ?string $trns = null): string
    {
        $channels = match ($colorType) { 0 => 1, 2 => 3, 4 => 2, default => 4 };
        \strlen($rowSamples) === $w * $channels * intdiv($depth, 8)
            || self::fail('row samples must be exactly one scanline');

        return self::rawPng($w, $h, $colorType, str_repeat("\x00" . $rowSamples, $h), $depth, $trns);
    }

    /**
     * Hand-built PNG with pre-encoded scanline bytes (each row already
     * carries its filter byte). Deterministic so the frozen fixtures and
     * these inline cases share one encoder.
     *
     * @param 0|2|4|6 $colorType
     */
    private static function rawPng(int $w, int $h, int $colorType, string $rows, int $depth = 8, ?string $trns = null): string
    {
        $png = "\x89PNG\r\n\x1a\n";
        $png .= self::chunk('IHDR', \pack('NNCCCCC', $w, $h, $depth, $colorType, 0, 0, 0));
        if ($trns !== null) {
            $png .= self::chunk('tRNS', $trns);
        }
        $png .= self::chunk('IDAT', \gzcompress($rows));
        $png .= self::chunk('IEND', '');

        return $png;
    }

    private static function chunk(string $type, string $data): string
    {
        return \pack('N', \strlen($data)) . $type . $data . \pack('N', \crc32($type . $data));
    }

    private static function capture(\GdImage $im): string
    {
        \ob_start();
        \imagepng($im);
        \imagedestroy($im);

        return (string) \ob_get_clean();
    }
}
