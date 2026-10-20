<?php

/**
 * B-10: alpha threshold + opt-in pre-sampling flatten for the Sixel path,
 * plus the PixelGrid threshold mirror. Pins, in order: the byte-frozen
 * default on a semi-transparent fixture (sha1 captured at base 53ecc09f1,
 * before the B-10 knob existed — the exact pre-B-10 output the hole test
 * `=== 127` produced), the lowered-threshold hole equivalence, the flatten
 * equivalence against a hand-pre-composited fixture, the sample-census
 * ordering law pinned structurally, refusal of out-of-range thresholds,
 * and the wither/fingerprint carry discipline.
 */

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\PixelGrid;
use SugarCraft\Image\Renderer\SixelRenderer;

final class SixelAlphaTest extends TestCase
{
    /**
     * sha1 of the default (Dither::None, 1×1 cells) encoding of the
     * b10SemiTransparentFixture captured against the BASE tree at
     * 53ecc09f1 — the pre-B-10 byte stream. Any change to the default
     * hole/flatten behaviour on this fixture reddens the byte-frozen pin.
     */
    private const BASE_DEFAULT_SHA1 = 'f563cfd981d20ef818db497512856a8db6032f32';

    private const RING_RGB = [10, 20, 30];
    private const RING_ALPHA = 100;

    /**
     * B-10: the semi-transparent fringe colour after flattening toward the
     * test background (200, 220, 240) at GD alpha 100, computed with the
     * src-over formula `c + (bg - c) * alpha / 127` (rounded):
     * r = 10 + 190*100/127 ≈ 160, g = 20 + 200*100/127 ≈ 177,
     * b = 30 + 210*100/127 ≈ 195.
     */
    private const FLATTEN_BG = [200, 220, 240];
    private const RING_FLATTENED = [160, 177, 195];

    /**
     * 12×8 GD canvas: fully-transparent (a=127) black background, a ring
     * row at y=3 x=2..5 with the given channels/alpha, and an opaque red
     * block at rows 5..7. The a=100 ring survives the PNG round-trip with
     * exact channel and alpha values (asserted by
     * {@see testFixtureAlphaSurvivesThePngRoundTrip()} so every pin below
     * rests on a verified premise).
     */
    private function b10Fixture(int $ringR, int $ringG, int $ringB, int $ringAlpha): ImageSource
    {
        $im = imagecreatetruecolor(12, 8);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        $ring = imagecolorallocatealpha($im, $ringR, $ringG, $ringB, $ringAlpha);
        for ($x = 2; $x <= 5; $x++) {
            imagesetpixel($im, $x, 3, $ring);
        }
        $red = imagecolorallocatealpha($im, 255, 0, 0, 0);
        for ($y = 5; $y <= 7; $y++) {
            for ($x = 0; $x <= 11; $x++) {
                imagesetpixel($im, $x, $y, $red);
            }
        }
        $source = ImageSource::fromGd($im, 'image/png');
        imagedestroy($im);

        return $source;
    }

    /**
     * Same canvas with the ring already composited to
     * {@see RING_FLATTENED} at alpha 0 — the fixture the encoder must
     * agree with once flattening is armed (equivalence oracle).
     */
    private function b10PreCompositedFixture(): ImageSource
    {
        [$r, $g, $b] = self::RING_FLATTENED;

        return $this->b10Fixture($r, $g, $b, 0);
    }

    /**
     * The fixture builder as a fully-transparent ring (a=127, same stored
     * RGB) — the oracle for the lowered-threshold hole equivalence.
     */
    private function b10HoledRingFixture(): ImageSource
    {
        [$r, $g, $b] = self::RING_RGB;

        return $this->b10Fixture($r, $g, $b, 127);
    }

    /**
     * The canonical a=100 fringe fixture used by most pins.
     */
    private function b10SemiTransparentFixture(): ImageSource
    {
        [$r, $g, $b] = self::RING_RGB;

        return $this->b10Fixture($r, $g, $b, self::RING_ALPHA);
    }

    private function b10CellRenderer(): SixelRenderer
    {
        // 1×1 cell geometry: the pixel canvas equals the fixture size, so
        // the 1:1 resample is a verbatim copy and every pin below exercises
        // quantisation alone, never a filter-blended resample.
        return new SixelRenderer(Dither::None, 256, 1, 1);
    }

    public function testFixtureAlphaSurvivesThePngRoundTrip(): void
    {
        $decoded = imagecreatefromstring($this->b10SemiTransparentFixture()->bytes);
        $this->assertNotFalse($decoded);

        $ring = imagecolorsforindex($decoded, imagecolorat($decoded, 2, 3));
        $bg = imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0));
        $opaque = imagecolorsforindex($decoded, imagecolorat($decoded, 0, 6));
        imagedestroy($decoded);

        // Premise pins: GD alpha 100 must be PNG-round-trip stable on this
        // build, or the equivalence tests below would be vacuous.
        $this->assertSame(self::RING_RGB[0], $ring['red']);
        $this->assertSame(self::RING_RGB[1], $ring['green']);
        $this->assertSame(self::RING_RGB[2], $ring['blue']);
        $this->assertSame(self::RING_ALPHA, $ring['alpha']);
        $this->assertSame(127, $bg['alpha']);
        $this->assertSame(0, $opaque['alpha']);
    }

    public function testDefaultThresholdReproducesThePreB10Bytes(): void
    {
        $image = $this->b10SemiTransparentFixture();

        $default = $this->b10CellRenderer()->render($image, 12, 8);
        $explicit = $this->b10CellRenderer()
            ->withAlphaThreshold(SixelRenderer::DEFAULT_ALPHA_THRESHOLD)
            ->render($image, 12, 8);

        // The byte-frozen pin: captured against the base tree (no B-10 code
        // existed), so a mutated default (e.g. neutering the threshold to
        // 0/100) reddens here on top of the lib-wide digest pins.
        $this->assertSame(self::BASE_DEFAULT_SHA1, sha1($default));
        $this->assertSame($default, $explicit, 'explicit 127 must equal the ctor default');
        $this->assertSame(
            SixelRenderer::DEFAULT_ALPHA_THRESHOLD,
            $this->b10CellRenderer()->alphaThreshold(),
            'the shipped default is GD-fully-transparent',
        );
    }

    public function testLoweredThresholdTurnsTheSemiTransparentRingIntoHoles(): void
    {
        $semi = $this->b10SemiTransparentFixture();
        $holed = $this->b10HoledRingFixture();

        // Hole equivalence: threshold 100 treats the a=100 ring exactly as
        // if it had been fully transparent — same palette census (the ring
        // colour never reaches median-cut), same grid, same bytes.
        $lowered = $this->b10CellRenderer()->withAlphaThreshold(100)->render($semi, 12, 8);
        $oracle = $this->b10CellRenderer()->render($holed, 12, 8);

        $this->assertSame($oracle, $lowered);
        $this->assertNotSame(
            $this->b10CellRenderer()->render($semi, 12, 8),
            $lowered,
            'the default keeps the fringe painted (the dark-halo defect stays as today) — '
            . 'if these ever agree, the threshold knob is not wired into the hole test',
        );
    }

    public function testFlattenMatchesAPreCompositedFixtureByteForByte(): void
    {
        $bg = Color::rgb(...self::FLATTEN_BG);

        $flattened = $this->b10CellRenderer()
            ->withFlatten($bg)
            ->render($this->b10SemiTransparentFixture(), 12, 8);
        $oracle = $this->b10CellRenderer()
            ->render($this->b10PreCompositedFixture(), 12, 8);

        $this->assertSame(
            $oracle,
            $flattened,
            'flattening must hand the encoder exactly the colours src-over compositing yields',
        );
        $this->assertNotSame(
            $this->b10CellRenderer()->render($this->b10SemiTransparentFixture(), 12, 8),
            $flattened,
            'flatten is opt-in — the default path must not composite',
        );
    }

    public function testFlattenNullClearsAndCarriesOtherConfig(): void
    {
        $bg = Color::rgb(...self::FLATTEN_BG);
        $renderer = (new SixelRenderer(Dither::Stucki, 16, 1, 1))
            ->withFlatten($bg);

        $this->assertSame($bg, $renderer->flattenBackground());
        $this->assertNull($renderer->withFlatten(null)->flattenBackground());

        // withDither must carry threshold and flatten (clone-site drift pin).
        $carried = $renderer->withDither(Dither::Atkinson);
        $this->assertSame(Dither::Atkinson, $carried->dither());
        $this->assertSame(16, $carried->maxColors());
        $this->assertSame(127, $carried->alphaThreshold());
        $this->assertSame($bg, $carried->flattenBackground());
    }

    public function testSampleCensusExcludesTransparencyBeforeFlattenPushes(): void
    {
        // ORDERING LAW, pinned structurally: in both the palette sampler and
        // the base read, the hole census runs FIRST (transparent pixels are
        // dropped unpainted, never composited), the flatten composite runs
        // on survivors, and only then does the colour enter the census /
        // accumulator. If a refactor moves the flatten ahead of the hole
        // test, semi-transparent holes would silently re-enter as bg colour.
        $source = (new \ReflectionClass(SixelRenderer::class))->getFileName();
        $code = file_get_contents($source);
        $this->assertIsString($code);

        // samplePixels leg: hole continue < flattenOnto < $pixels[] push.
        $sampler = substr(
            $code,
            (int) strpos($code, 'private function samplePixels'),
            (int) strpos($code, 'private static function flattenOnto'),
        );
        $holeDrop = strpos($sampler, '>= $this->alphaThreshold');
        $composite = strpos($sampler, 'flattenOnto(');
        $push = strpos($sampler, '$pixels[] =');
        $this->assertLessThan(
            (int) $composite,
            (int) $holeDrop,
            'the sample census must drop holes BEFORE any compositing',
        );
        $this->assertLessThan((int) $push, (int) $composite, 'flatten must run before the push');

        // indexRows loadBase leg: $isHole decision < flattenOnto < $row[] push.
        $rows = substr(
            $code,
            (int) strpos($code, '$loadBase = function'),
            (int) strpos($code, '// Prime the window'),
        );
        $holeSet = strpos($rows, '$isHole =');
        $rowsComposite = strpos($rows, 'flattenOnto(');
        $rowsPush = strpos($rows, '$row[] =');
        $this->assertLessThan((int) $rowsComposite, (int) $holeSet);
        $this->assertLessThan((int) $rowsPush, (int) $rowsComposite);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function refusedThresholds(): array
    {
        return [
            'negative' => [-1],
            'above fully transparent' => [128],
            'far below' => [PHP_INT_MIN],
            'far above' => [PHP_INT_MAX],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedThresholds')]
    public function testOutOfRangeThresholdsAreRefused(int $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SixelRenderer())->withAlphaThreshold($bad);
    }

    public function testFingerprintStaysPinnedAtDefaultsAndSegmentsOnlyOnDeviation(): void
    {
        $frozen = 'dither=none|maxColors=256|cellWidth=1|cellHeight=1';

        $this->assertSame($frozen, $this->b10CellRenderer()->configFingerprint());
        $this->assertSame(
            $frozen,
            $this->b10CellRenderer()->withAlphaThreshold(127)->configFingerprint(),
            'the DEFAULT threshold must not add a segment',
        );

        $bg = Color::rgb(...self::FLATTEN_BG);
        $this->assertSame(
            $frozen . '|alphaThreshold=100',
            $this->b10CellRenderer()->withAlphaThreshold(100)->configFingerprint(),
        );
        $this->assertSame(
            $frozen . '|flatten=#C8DCF0',
            $this->b10CellRenderer()->withFlatten($bg)->configFingerprint(),
            'the flatten segment must carry the background hex',
        );
    }

    public function testPixelGridMirrorsTheThresholdOnBothBuilders(): void
    {
        // fromGd maps its destination box to (cellW × cellH*2); a 1×2
        // source at 1×1 cells keeps the resample 1:1, so channel and alpha
        // values below are verbatim, not filter-blended.
        $tall = imagecreatetruecolor(1, 2);
        imagesavealpha($tall, true);
        imagealphablending($tall, false);
        imagesetpixel($tall, 0, 0, imagecolorallocatealpha($tall, 40, 50, 60, 100));
        imagesetpixel($tall, 0, 1, imagecolorallocatealpha($tall, 0, 0, 0, 127));

        // Default: semi-transparent stays in the opaque slot (100, not
        // null) and fully-transparent reads null — the historical shapes.
        $grid = PixelGrid::fromGd($tall, 1, 1);
        $this->assertSame(100, $grid->cells[0][0][0][3], 'a=100 must NOT be a hole at the default');
        $this->assertNull($grid->cells[0][0][1][3], 'a=127 stays a hole at the default');

        // Threshold 50: a=100 crosses the cut-off → null hole.
        $lowered = PixelGrid::fromGd($tall, 1, 1, 50);
        $this->assertNull($lowered->cells[0][0][0][3], 'a=100 >= 50 must read as a hole');

        // Quarter builder mirrors the same test: fromGdQuarter's box is
        // (cellW*2 × cellH*2), so a 2×2 source at 1×1 cells is 1:1 again.
        $quad = imagecreatetruecolor(2, 2);
        imagesavealpha($quad, true);
        imagealphablending($quad, false);
        imagesetpixel($quad, 0, 0, imagecolorallocatealpha($quad, 40, 50, 60, 100));
        $opaque = imagecolorallocatealpha($quad, 200, 0, 0, 0);
        imagesetpixel($quad, 1, 0, $opaque);
        imagesetpixel($quad, 0, 1, $opaque);
        imagesetpixel($quad, 1, 1, $opaque);

        $quarterDefault = PixelGrid::fromGdQuarter($quad, 1, 1);
        $quarterLowered = PixelGrid::fromGdQuarter($quad, 1, 1, 50);
        $this->assertSame(100, $quarterDefault->cells[0][0][0][3]);
        $this->assertNull($quarterLowered->cells[0][0][0][3]);
        $this->assertSame(0, $quarterDefault->cells[0][0][3][3], 'opaque quads never null out');

        imagedestroy($tall);
        imagedestroy($quad);
    }
}
