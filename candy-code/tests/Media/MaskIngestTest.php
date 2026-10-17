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
        // fully opaque. The byte walk sees the 254 and must win — the
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

    public function testNotAnImageAtAllIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GD failed to load image');

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

    // ---- GD fallback paths ----------------------------------------------------

    public function testAPalettePngWithTransparentIndexClassifiesAsAlphaViaGd(): void
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

    public function testAnInterlacedPngFallsThroughTheByteWalkToGdAndStillClassifies(): void
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

    public function testA16BitPngFallsThroughTheByteWalkToGd(): void
    {
        // depth-16 grayscale, all samples 0xFFFF → white. The walk refuses
        // depth != 8 and GD resolves the picture.
        $bytes = self::rawPng(2, 2, 0, "\x00\xFF\xFF\xFF\xFF\x00\xFF\xFF\xFF\xFF", depth: 16);

        $mask = MaskIngest::fromBytes($bytes, 'sixteen.png');

        $this->assertSame(MaskIngest::MODE_LUMINANCE, $mask->mode);
        $this->assertSame(MaskIngest::ADVISORY_COVERS_EVERYTHING, $mask->advisory);
    }

    // ---- W7 seam cure: capped IDAT inflate -------------------------------------

    public function testAnOverCapIdatBombRidesTheGdFallbackWithTheSameVerdict(): void
    {
        // MED-1 family (sibling of the Png tEXt cure): a 1×1 header paired
        // with a ratio-bomb IDAT. libpng inflates only the declared rows —
        // "Too much image data" is a warning, so ImageIngress ADMITS the
        // file — which left the byte walk's second inflate as the one place
        // the full expansion was minted. Post-cure the walk declines on the
        // capped inflate and GD answers; the verdict is deliberately the
        // pre-cure verdict (this pin proves the decline is wired, GD still
        // resolving the picture — the cap itself is pinned structurally
        // below, because cap-present and cap-absent are indistinguishable
        // through any verdict).
        $png = "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', \pack('NNCCCCC', 1, 1, 8, 0, 0, 0, 0))
            . self::chunk('IDAT', \gzcompress("\x00\xFF" . \str_repeat("\x00", 200_000)))
            . self::chunk('IEND', '');

        $mask = MaskIngest::fromBytes($png, 'bomb.png');

        $this->assertSame(MaskIngest::MODE_LUMINANCE, $mask->mode, 'one white pixel via GD');
        $this->assertSame(MaskIngest::ADVISORY_COVERS_EVERYTHING, $mask->advisory);
    }

    public function testTheIdatInflateCarriesTheCapAndBothHonestyGatesStructurally(): void
    {
        // The walkPngBytes inflate MUST carry the header-derived budget as
        // max_length, and the two gates keeping that argument honest must be
        // present: `< 1` (max_length 0 behaves as UNLIMITED — measured PHP
        // 8.3 — so a zero-dimension lie must never reach the call) and the
        // ceiling (an absurd declaration is declined before inflating). No
        // functional mode detects their absence — the ceiling branch is not
        // reachable through fromBytes at all for gray masks, since every
        // header over the ceiling ALSO trips ImageIngress's own pixel/container
        // doors first, and the <1 gate is defense-in-depth behind the door
        // that refuses zero-dimension files. The source slice is the
        // discriminator (same doctrine as PngTest's tEXt census).
        $src = (string) \file_get_contents(__DIR__ . '/../../src/Media/MaskIngest.php');

        $this->assertSame(1, \substr_count($src, 'gzuncompress($idat, $expected)'), 'the inflate is capped, once, nowhere uncapped');
        $this->assertSame(1, \substr_count($src, '$expected < 1'), 'zero-dimension gate present');
        $this->assertSame(1, \substr_count($src, '$expected > self::MAX_IDAT_RAW_BYTES'), 'ceiling gate present');
        $this->assertSame(67_108_864, MaskIngest::MAX_IDAT_RAW_BYTES, '64 MiB — no larger than the admitted container');
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
