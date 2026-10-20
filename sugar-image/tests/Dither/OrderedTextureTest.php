<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Dither;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\OrderedTexture;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * C-04: ordered-dither texture pins — the Bayer recursion ported from chafa
 * (chafa-util.c fill_matrix_r/fill_matrix) is checked against an INDEPENDENT
 * bit-interleave oracle, the magnitude/recenter law is pinned, and the band
 * split equality property (D-02 precondition) proves texture-phase grid rows
 * are invariant to how the canvas is chunked into bands. The default
 * diffusion path additionally carries a byte-frozen digest pin per the
 * orchestrator micro-4 ruling (Ordered is strictly opt-in). C-05 extends
 * every layer of that record to the 64x64 three-channel blue-noise texture.
 */
final class OrderedTextureTest extends TestCase
{
    /** Default-config Sixel of the gradient fixture as merged post-C-01/W2. */
    private const FROZEN_DEFAULT_SHA1 = '47d7e3d7b2e1a1a3e21e363f836d6d03410987f8';

    /** Dither::None Sixel of the same fixture — the no-bias reference. */
    private const FROZEN_NONE_SHA1 = 'c65c8465e501645f8eb6c76bb46a97cf38e948d0';

    /**
     * C-04: the recentered ordered render of the gradient fixture. Captured
     * by hand on the fixed tree (this lib ships no golden-update env —
     * plan_chafa §4.11); the value follows the C-07 depth ladder (the
     * C-04 commit predates the ladder, so the pre-ladder magnitude 1.0
     * digest is re-captured there — disclosed).
     */
    private const ORDERED_GRADIENT_SHA1 = '7c1e6fd63bb3643b049249f98028486730c31ecf';

    /**
     * C-05: the recentered blue-noise render of the gradient fixture at the
     * default (null) intensity. Captured by hand on the fixed tree; unlike
     * the ordered digest this one is ladder-immune — chafa-canvas.c applies
     * the depth ladder to ORDERED only, so C-07 must leave it untouched.
     */
    private const NOISE_GRADIENT_SHA1 = '22d77ec1f39c9a4fbed45db07989355b0189a100';

    /** chafa-util.c fill_matrix_r quadrant codes for (bx, by) bit pairs. */
    private const QUADRANT_CODE = [0, 3, 2, 1]; // index = bx*2 + by

    /**
     * C-04: the recursion must equal an independent closed-form Bayer matrix
     * for every cell — base-4 digits of the interleaved x/y bit pairs, most
     * significant subdivision first (chafa's first split halves the FULL
     * width, so it contributes the LOW value bits while step quadruples with
     * recursion depth). Recomputing the layout from bit algebra rather than
     * reading the implementation back is the point.
     */
    public function testBayer16MatchesIndependentBitInterleaveOracle(): void
    {
        $texture = OrderedTexture::bayer16(1.0);

        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $raw = 0;
                for ($j = 0; $j < 4; $j++) {
                    $xb = ($x >> (3 - $j)) & 1;
                    $yb = ($y >> (3 - $j)) & 1;
                    $raw |= self::QUADRANT_CODE[$xb * 2 + $yb] << (2 * $j);
                }

                // fill_matrix() recenter: (v - 128) * (256/256) * 1.0 —
                // integer-valued, so NTC-2 round() is the identity here.
                $this->assertSame(
                    $raw - 128,
                    $texture->mod($x, $y, 0),
                    "bayer16 cell ($x,$y)",
                );
            }
        }
    }

    /**
     * C-04: recentre range + multiset — magnitude 1.0 yields exactly
     * -128..127 once each (chafa's own comment: "magnitude == 1.0 =>
     * -128..127", which its +0.5f-trunc actually misses by one unit — the
     * NTC-2 symmetric-rounding deviation makes the comment true here).
     */
    public function testBayer16RecentresToTheFullSignedRange(): void
    {
        $data = OrderedTexture::bayer16(1.0)->data;

        $this->assertCount(256, $data);
        $sorted = $data;
        sort($sorted);
        $this->assertSame(range(-128, 127), $sorted);
        // sum(0..255) - 256*128 = 32640 - 32768
        $this->assertSame(-128, array_sum($data));
    }

    /**
     * C-04: the magnitude scales the recentred offsets symmetrically
     * (round half away from zero — NTC-2), never the raw matrix.
     */
    public function testMagnitudeScalesRecentredOffsetsSymmetrically(): void
    {
        $full = OrderedTexture::bayer16(1.0)->data;
        $half = OrderedTexture::bayer16(0.5)->data;

        $this->assertCount(256, $half);
        foreach ($full as $i => $offset) {
            $this->assertSame((int) round($offset * 0.5), $half[$i], "cell $i");
        }
        // Symmetry leg: round(-0.5) == -1 (away from zero), where chafa's
        // (int)(-0.5f + 0.0f...) + 0.5 truncation would have produced 0.
        $negatives = array_filter($full, static fn(int $v): bool => $v === -1);
        $this->assertNotEmpty($negatives, 'fixture must contain the -1 offset');
    }

    /** C-04: pure function — identical inputs re-derive identical textures. */
    public function testTextureGenerationIsDeterministic(): void
    {
        $this->assertSame(
            OrderedTexture::bayer16(0.1)->data,
            OrderedTexture::bayer16(0.1)->data,
        );
    }

    /**
     * C-04: the ORDERED arm reads ONE offset for all three channels (chafa
     * gen_mods non-NOISE branch: texture_data[texture_index]); the wrap mask
     * tiles both axes.
     */
    public function testOrderedModIsChannelUniformAndTiled(): void
    {
        $texture = OrderedTexture::bayer16(1.0);

        $this->assertSame(1, $texture->channels);
        $this->assertSame(16, $texture->size);

        for ($y = 0; $y < 20; $y++) {
            for ($x = 0; $x < 20; $x++) {
                $this->assertSame($texture->mod($x, $y, 0), $texture->mod($x, $y, 1));
                $this->assertSame($texture->mod($x, $y, 0), $texture->mod($x, $y, 2));
                $this->assertSame($texture->mod($x - 16, $y - 16, 0), $texture->mod($x, $y, 0));
            }
        }
    }

    /**
     * C-04/D-02 precondition: grid rows depend only on ABSOLUTE canvas
     * position, never on band chunking. Full-canvas indexRows() must equal
     * a band stitched from stacked sub-images, each passed with its absolute
     * yOrigin phase. The second leg keeps the pin non-vacuous: re-encoding
     * the same sub-image at the WRONG phase must differ (this is the leg
     * mutation "drop the yOrigin term" reddens).
     */
    public function testOrderedGridRowsAreInvariantToBandSplitting(): void
    {
        $gd = $this->strip(32, 40);

        $full = $this->indexGrid($gd, Dither::Ordered, yOrigin: 0);

        $bottom = imagecrop($gd, ['x' => 0, 'y' => 20, 'width' => 32, 'height' => 20]);
        $this->assertNotFalse($bottom);

        $phaseRight = $this->indexGrid($bottom, Dither::Ordered, yOrigin: 20);
        $stitched = array_merge(array_slice($full, 0, 20), $phaseRight);
        $this->assertSame($full, $stitched, 'band-stitched texture rows must equal full-canvas rows');

        $phaseWrong = $this->indexGrid($bottom, Dither::Ordered, yOrigin: 0);
        $this->assertNotSame(
            array_slice($full, 20),
            $phaseWrong,
            'the fixture must actually cross texture phases — otherwise the equality leg is vacuous',
        );
    }

    /**
     * C-04: default path byte-frozen (orchestrator micro-4): with the
     * Ordered axis shipped, the untouched default renderer and the None
     * arm must reproduce the post-C-01/W2 merged bytes exactly, and the
     * default config fingerprint must stay byte-identical (no intensity
     * segment while intensity is null).
     */
    public function testDefaultSixelPathIsByteFrozen(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/../fixtures/gradient_64x64.png');

        $this->assertSame(
            self::FROZEN_DEFAULT_SHA1,
            sha1((new SixelRenderer())->render($source, 16, 12)),
            'the default (Floyd–Steinberg) sixel stream is frozen post-C-01',
        );
        $this->assertSame(
            self::FROZEN_NONE_SHA1,
            sha1((new SixelRenderer(Dither::None))->render($source, 16, 12)),
            'the None arm is frozen too',
        );
        $this->assertSame(
            'dither=floyd-steinberg|maxColors=256|cellWidth=10|cellHeight=20',
            (new SixelRenderer())->configFingerprint(),
        );
    }

    /**
     * C-04: the ordered render differs from no-dither and is digest-pinned;
     * its cache fingerprint is its own (D-04: a new dither kind must never
     * cross-serve the None/FS poster entries) and gains the intensity
     * segment only when the bias is non-default.
     */
    public function testOrderedRenderIsPinnedAndCacheDistinct(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/../fixtures/gradient_64x64.png');

        $ordered = new SixelRenderer(Dither::Ordered);
        $bytes = $ordered->render($source, 16, 12);

        $this->assertNotSame($bytes, (new SixelRenderer(Dither::None))->render($source, 16, 12));
        $this->assertSame(self::ORDERED_GRADIENT_SHA1, sha1($bytes));
        $this->assertSame(
            'dither=ordered|maxColors=256|cellWidth=10|cellHeight=20',
            $ordered->configFingerprint(),
        );
        $this->assertSame(
            'dither=ordered|maxColors=256|cellWidth=10|cellHeight=20|intensity=0.5',
            (new SixelRenderer(Dither::Ordered, intensity: 0.5))->configFingerprint(),
        );
    }

    /**
     * C-04: the ordered encoder reaches the same valid DCS envelope as the
     * diffusion arms (streaming shape untouched by the texture path).
     */
    public function testOrderedRenderCarriesSixelEnvelope(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/../fixtures/gradient_64x64.png');
        $bytes = (new SixelRenderer(Dither::Ordered))->render($source, 16, 12);

        $this->assertStringStartsWith("\x1bP", $bytes);
        $this->assertStringEndsWith("\x1b\\", $bytes);
    }

    /**
     * C-05: the embedded table must be chafa's blue_noise_src byte-for-byte
     * (chafa-noise.c:30-224) — 192 hex lines of 64 bytes, 12,288 samples in
     * total, read via reflection off the private const so the pin survives
     * any refactor of the generator.
     */
    public function testTheBlueNoiseTableMatchesChafaBytesVerbatim(): void
    {
        $hex = (new \ReflectionClass(OrderedTexture::class))->getConstant('BLUE_NOISE_HEX');
        $this->assertIsArray($hex);
        $this->assertCount(192, $hex);
        foreach ($hex as $i => $line) {
            $this->assertSame(128, strlen($line), "table line $i width");
            $this->assertTrue(ctype_xdigit($line), "table line $i is hex");
        }

        $bytes = hex2bin(implode('', $hex));
        $this->assertNotFalse($bytes);
        $this->assertSame(12288, strlen($bytes), '64 * 64 * 3 samples');
        // sha1/sums of Christoph Peters' CC0 LDR_RGB1_0.png table as embedded
        $this->assertSame('3f1877bbef85a8e088123e314ba136480d39c3a8', sha1($bytes));
        $samples = array_values(unpack('C*', $bytes));
        $this->assertSame(1566707, array_sum($samples));
        $channels = [[], [], []];
        foreach ($samples as $i => $byte) {
            $channels[$i % 3][] = $byte;
        }
        $this->assertSame(
            [522246, 522235, 522226],
            [array_sum($channels[0]), array_sum($channels[1]), array_sum($channels[2])],
            'per-channel byte sums (channel-interleaved layout intact)',
        );
    }

    /**
     * C-05: at magnitude 1.0 the recenter is exactly byte - 128 — chafa's
     * `((gfloat) src[i] - 128.0f) * 1.0 + 0.5f` truncated (NTC-2 symmetric
     * round makes the integers fall out identically) — and the layout is
     * [pixel][channel] with pixel = x + y * 64, matching gen_mods' NOISE arm
     * read texture_data[texture_index * channels + ch].
     */
    public function testBlueNoiseRecentringSubtractsTheMidpointAtUnitMagnitude(): void
    {
        $hex   = (new \ReflectionClass(OrderedTexture::class))->getConstant('BLUE_NOISE_HEX');
        $bytes = hex2bin(implode('', $hex));
        $this->assertNotFalse($bytes);

        $texture = OrderedTexture::blueNoise64(1.0);
        $this->assertSame(64, $texture->size);
        $this->assertSame(3, $texture->channels);
        $this->assertCount(12288, $texture->data);

        for ($i = 0; $i < 12288; $i++) {
            $this->assertSame(ord($bytes[$i]) - 128, $texture->data[$i], "sample $i");
        }
        // sum(bytes) - 12288 * 128
        $this->assertSame(-6157, array_sum($texture->data));
        $this->assertSame([-128, 127], [min($texture->data), max($texture->data)]);
    }

    /**
     * C-05: unlike the Bayer texture (one offset for all channels, gen_mods
     * non-NOISE arm), the NOISE arm is decorrelated per channel — across the
     * full 64x64 tiling no cell has all three offsets equal, every cell spans
     * a non-zero channel spread, and both axes wrap at 64.
     */
    public function testBlueNoiseChannelsAreDecorrelatedAndTiled(): void
    {
        $texture = OrderedTexture::blueNoise64(1.0);

        $allEqual = 0;
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $r = $texture->mod($x, $y, 0);
                $g = $texture->mod($x, $y, 1);
                $b = $texture->mod($x, $y, 2);
                if ($r === $g && $g === $b) {
                    $allEqual++;
                }
            }
        }
        $this->assertSame(0, $allEqual, 'blue-noise channels must be decorrelated');

        for ($y = 0; $y < 70; $y++) {
            for ($x = 0; $x < 70; $x++) {
                $this->assertSame($texture->mod($x - 64, $y, 0), $texture->mod($x, $y, 0));
                $this->assertSame($texture->mod($x, $y - 64, 1), $texture->mod($x, $y, 1));
            }
        }

        // Independent channels collide at chance level only: 17 of 4096 r/g
        // cells share an offset (a channel-copied texture would score 4096,
        // and the ordered Bayer arm does — its uniformity is pinned above).
        $rgEqual = 0;
        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                if ($texture->mod($x, $y, 0) === $texture->mod($x, $y, 1)) {
                    $rgEqual++;
                }
            }
        }
        $this->assertLessThan(100, $rgEqual, 'r/g streams must not be copies');
    }

    /** C-05: pure generator — deterministic, and magnitude scales symmetrically. */
    public function testBlueNoiseIsDeterministicAndScalesSymmetrically(): void
    {
        $this->assertSame(
            OrderedTexture::blueNoise64(0.1)->data,
            OrderedTexture::blueNoise64(0.1)->data,
        );

        $hex   = (new \ReflectionClass(OrderedTexture::class))->getConstant('BLUE_NOISE_HEX');
        $bytes = hex2bin(implode('', $hex));
        $this->assertNotFalse($bytes);

        $half = OrderedTexture::blueNoise64(0.5)->data;
        foreach ([0, 1, 7621, 12287] as $i) {
            $this->assertSame(
                (int) round((ord($bytes[$i]) - 128.0) * 0.5),
                $half[$i],
                "sample $i at magnitude 0.5",
            );
        }
    }

    /**
     * C-05/D-02 precondition: the NOISE texture (mask 63, magnitude
     * 0.1 at default intensity) must share the ordered arm's band-split
     * equality — absolute-phase reads make grid rows independent of
     * band chunking — with the wrong-phase leg pinning non-vacuity.
     */
    public function testNoiseGridRowsAreInvariantToBandSplitting(): void
    {
        $gd = $this->strip(32, 40);

        $full = $this->indexGrid($gd, Dither::Noise, yOrigin: 0);

        $bottom = imagecrop($gd, ['x' => 0, 'y' => 20, 'width' => 32, 'height' => 20]);
        $this->assertNotFalse($bottom);

        $phaseRight = $this->indexGrid($bottom, Dither::Noise, yOrigin: 20);
        $stitched   = array_merge(array_slice($full, 0, 20), $phaseRight);
        $this->assertSame($full, $stitched, 'band-stitched noise rows must equal full-canvas rows');

        $phaseWrong = $this->indexGrid($bottom, Dither::Noise, yOrigin: 0);
        $this->assertNotSame(
            array_slice($full, 20),
            $phaseWrong,
            'the fixture must actually cross noise phases — otherwise the equality leg is vacuous',
        );
    }

    /**
     * C-05: the noise render is digest-pinned, differs from None and
     * Ordered, and its cache fingerprint is its own (D-04 key-capture);
     * an explicit intensity of 1.0 must equal the null default (the
     * chafa config default is 1.0 — null means "asked for nothing").
     */
    public function testNoiseRenderIsPinnedAndCacheDistinct(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/../fixtures/gradient_64x64.png');

        $noise = new SixelRenderer(Dither::Noise);
        $bytes = $noise->render($source, 16, 12);

        $this->assertNotSame($bytes, (new SixelRenderer(Dither::None))->render($source, 16, 12));
        $this->assertNotSame($bytes, (new SixelRenderer(Dither::Ordered))->render($source, 16, 12));
        $this->assertSame(self::NOISE_GRADIENT_SHA1, sha1($bytes));
        $this->assertSame(
            $bytes,
            (new SixelRenderer(Dither::Noise, intensity: 1.0))->render($source, 16, 12),
            'intensity 1.0 is the null-default behaviour',
        );
        $this->assertSame(
            'dither=noise|maxColors=256|cellWidth=10|cellHeight=20',
            $noise->configFingerprint(),
        );
    }

    /**
     * Drive the private indexRows() and return its grid as a plain list.
     *
     * @param list<array{int,int,int}> $palette
     * @return list<list<int>>
     */
    private function indexGrid(\GdImage $gd, Dither $dither, int $yOrigin): array
    {
        $method = new \ReflectionMethod(SixelRenderer::class, 'indexRows');
        $method->setAccessible(true);

        return array_values(
            iterator_to_array($method->invoke(new SixelRenderer(), $gd, $this->greyRamp(), $dither, 0, $yOrigin)),
        );
    }

    /**
     * Deterministic diagonal-stripe truecolour image — spans every bayer
     * phase class across the 40-row canvas.
     */
    private function strip(int $w, int $h): \GdImage
    {
        $gd = imagecreatetruecolor($w, $h);
        $this->assertNotFalse($gd);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $v = ($x * 8 + $y * 3) % 256;
                imagesetpixel($gd, $x, $y, imagecolorallocate($gd, $v, $v, $v));
            }
        }

        return $gd;
    }

    /**
     * @return list<array{int,int,int}>
     */
    private function greyRamp(): array
    {
        return [[0, 0, 0], [85, 85, 85], [170, 170, 170], [255, 255, 255]];
    }
}
