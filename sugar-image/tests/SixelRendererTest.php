<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\SixelRenderer;

final class SixelRendererTest extends TestCase
{
    private const ESC = "\x1b";

    private SixelRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new SixelRenderer();
    }

    private function red(): ImageSource
    {
        return ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
    }

    private function noise(): ImageSource
    {
        return ImageSource::fromFile(__DIR__ . '/fixtures/500x400_noise.png');
    }

    public function testName(): void
    {
        $this->assertSame('sixel', $this->renderer->name());
    }

    public function testSupportsAlpha(): void
    {
        // Sixel declares a transparent background register (#0;2;P).
        $this->assertTrue($this->renderer->supportsAlpha());
    }

    public function testIsNotInline(): void
    {
        // Sixel is a graphics blob, not tiling cell text.
        $this->assertFalse($this->renderer->isInline());
    }

    public function testBeginsWithDcsAndRasterAttributes(): void
    {
        $out = $this->renderer->render($this->red(), 8, 4);

        // DCS `ESC P P1;P2;P3 q` then raster `"Pan;Pad;Ph;Pv`.
        $this->assertStringStartsWith(self::ESC . 'P', $out);
        $this->assertStringContainsString('q"1;1;', $out, 'raster attributes follow the q');
    }

    public function testEndsWithStringTerminatorNotBel(): void
    {
        $out = $this->renderer->render($this->red(), 8, 4);

        // A DCS ends with ST (ESC \), never BEL.
        $this->assertStringEndsWith(self::ESC . '\\', $out);
        $this->assertStringNotContainsString("\x07", $out, 'no BEL anywhere');
    }

    public function testRasterCarriesThePixelCanvasSize(): void
    {
        // 8x4 cells × default 10x20 cell size → 80x80 pixel canvas.
        $out = $this->renderer->render($this->red(), 8, 4);

        $this->assertStringContainsString('"1;1;80;80', $out);
    }

    public function testEffectiveHeightComputedFromAspectRatio(): void
    {
        // 8×4 source → aspect 2.0; width=10 cells → height 5 cells → 100×100 px.
        $out = $this->renderer->render($this->red(), 10);

        $this->assertStringContainsString('"1;1;100;100', $out);
    }

    public function testColorIntroducerUsesHashRgbForm(): void
    {
        $out = $this->renderer->render($this->red(), 8, 4);

        // DECGCI `# Pc ; 2 ; r ; g ; b` (RGB, 0-100). Pure red → 100;0;0.
        $this->assertStringContainsString('#0;2;100;0;0', $out);
        // …and NOT the old per-colour DCS form.
        $this->assertStringNotContainsString(self::ESC . 'P0;100;0;0', $out);
    }

    public function testAllRedImageYieldsASinglePaletteEntry(): void
    {
        $out = $this->renderer->render($this->red(), 8, 4);

        preg_match_all('/#(\d+);2;/', $out, $m);
        $this->assertCount(1, array_unique($m[1]));
    }

    public function testMultiColorImageHasMultiplePaletteEntries(): void
    {
        $out = $this->renderer->render($this->noise(), 16, 12);

        preg_match_all('/#(\d+);2;/', $out, $m);
        $this->assertGreaterThan(1, count(array_unique($m[1])));
    }

    public function testBandsAreSeparatedByGraphicsNewlineNotLineFeed(): void
    {
        // A canvas taller than 6 px has multiple bands, joined with `-`, never "\n".
        $out = $this->renderer->render($this->noise(), 10, 8);

        $this->assertGreaterThanOrEqual(3, substr_count($out, '-'));
        $this->assertStringNotContainsString("\n", $out, 'no literal line feeds inside the DCS');
    }

    public function testEveryByteInTheDcsBodyIsPrintableAscii(): void
    {
        $out = $this->renderer->render($this->red(), 8, 4);

        // Strip the DCS intro (ESC P) and the ST terminator (ESC \).
        $body = substr($out, 2, -2);
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $cp = ord($body[$i]);
            if ($cp < 0x20 || $cp > 0x7E) {
                $this->fail('Non-printable byte 0x' . dechex($cp) . " at $i");
            }
        }
        $this->assertNotSame('', $body);
    }

    public function testZeroWidthThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($this->red(), 0);
    }

    public function testNegativeWidthThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($this->red(), -1);
    }

    public function testZeroHeightThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($this->red(), 8, 0);
    }

    public function testNegativeHeightThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($this->red(), 8, -1);
    }

    // ─── Dither enum ─────────────────────────────────────────────────────────

    public function testDitherEnumCases(): void
    {
        $this->assertSame('none', Dither::None->value);
        $this->assertSame('floyd-steinberg', Dither::FloydSteinberg->value);
        $this->assertSame('stucki', Dither::Stucki->value);
        $this->assertSame('atkinson', Dither::Atkinson->value);
    }

    public function testDitherEnumLabels(): void
    {
        $this->assertSame('None', Dither::None->label());
        $this->assertSame('Floyd–Steinberg', Dither::FloydSteinberg->label());
        $this->assertSame('Stucki', Dither::Stucki->label());
        $this->assertSame('Atkinson', Dither::Atkinson->label());
    }

    public function testSixelRendererDefaultIsFloydSteinberg(): void
    {
        $this->assertSame(Dither::FloydSteinberg, (new SixelRenderer())->dither());
    }

    /** @return iterable<string, array{Dither}> */
    public static function ditherProvider(): iterable
    {
        yield 'none' => [Dither::None];
        yield 'floyd-steinberg' => [Dither::FloydSteinberg];
        yield 'stucki' => [Dither::Stucki];
        yield 'atkinson' => [Dither::Atkinson];
    }

    /**
     * @dataProvider ditherProvider
     */
    public function testAllDitherModesRenderValidSixel(Dither $dither): void
    {
        $out = (new SixelRenderer($dither))->render($this->noise(), 14, 10);

        $this->assertStringStartsWith(self::ESC . 'P', $out);
        $this->assertStringEndsWith(self::ESC . '\\', $out);
    }

    public function testFloydSteinbergDiffersFromNoDither(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/gradient_64x64.png');

        $none = (new SixelRenderer(Dither::None))->render($source, 16, 12);
        $fs   = (new SixelRenderer(Dither::FloydSteinberg))->render($source, 16, 12);

        $this->assertStringStartsWith(self::ESC . 'P', $none);
        $this->assertNotSame($none, $fs, 'dithering changes quantisation');
    }

    public function testStuckiDitherDiffersFromNoDither(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/gradient_64x64.png');

        $none   = (new SixelRenderer(Dither::None))->render($source, 16, 12);
        $stucki = (new SixelRenderer(Dither::Stucki))->render($source, 16, 12);

        $this->assertNotSame($none, $stucki);

        // C-01: with the y+1 row fixed to the canonical 2,4,8,4,2 the Stucki
        // arm diffuses the full 42/42, so its gradient bytes are now a locked
        // canonical vector. Digest captured by hand on the fixed tree at the
        // C-01+C-02/B-13 tip (no golden-update env in this lib — see
        // plan_chafa §4.11); any drift in the kernel, the walk, or the memo
        // reddens here on top of the unit-level DitherKernelTest pins.
        $this->assertSame(
            '65dbd3c6bf1d7ac45791084aff0b47533221d150',
            sha1($stucki),
            'Stucki gradient stream must match the canonical-kernel encoding',
        );

        // The three diffusion arms are pairwise distinct — a dither setting
        // that silently collapses onto another is a wiring bug, not a style
        // choice.
        $fs       = (new SixelRenderer(Dither::FloydSteinberg))->render($source, 16, 12);
        $atkinson = (new SixelRenderer(Dither::Atkinson))->render($source, 16, 12);
        $this->assertNotSame($fs, $stucki);
        $this->assertNotSame($stucki, $atkinson);
        $this->assertNotSame($fs, $atkinson);
    }

    /**
     * B-13: the 5-bit nearest-colour memo must answer with the nearest colour
     * of its 8×8×8 block CENTRE for every pixel sharing the block. Pre-B-13
     * it stored the nearest colour of whichever pixel warmed the key first,
     * so two same-block pixels split across palette entries silently became
     * the same colour — and that colour depended on scan arrival order.
     * Pin: the fixture pair genuinely splits under per-pixel nearest lookups,
     * yet both orderings of the same two pixels agree.
     */
    public function testColorMemoAnswersTheBlockCentreNotTheFirstPixel(): void
    {
        $palette = [[250, 0, 0], [255, 7, 7]];

        // Ground truth first: the two pixels are exact matches of DIFFERENT
        // palette entries, and they share one 8³ block — without this the
        // pin below would be vacuous.
        $nearest = new \ReflectionMethod(SixelRenderer::class, 'nearestColor');
        $nearest->setAccessible(true);
        $this->assertSame(0, $nearest->invoke($this->renderer, 250, 0, 0, $palette));
        $this->assertSame(1, $nearest->invoke($this->renderer, 255, 7, 7, $palette));
        $this->assertSame(250 >> 3, 255 >> 3, 'fixture pixels must share the memo block');

        // Fixed memo: both cells answer nearest(colour of block centre),
        // identical for either arrival order.
        $centreR = (250 & ~7) | 4;
        $expected = $nearest->invoke($this->renderer, $centreR, 4, 4, $palette);
        $this->assertSame([$expected, $expected], $this->indexFirstRow(250, 0, 0, 255, 7, 7, $palette));
        $this->assertSame([$expected, $expected], $this->indexFirstRow(255, 7, 7, 250, 0, 0, $palette));
    }

    /**
     * Drive the private indexRows() over a 2×1 truecolour strip.
     *
     * @param list<array{int,int,int}> $palette
     * @return list<int>
     */
    private function indexFirstRow(int $r0, int $g0, int $b0, int $r1, int $g1, int $b1, array $palette): array
    {
        $gd = imagecreatetruecolor(2, 1);
        $this->assertNotFalse($gd);
        imagesetpixel($gd, 0, 0, imagecolorallocate($gd, $r0, $g0, $b0));
        imagesetpixel($gd, 1, 0, imagecolorallocate($gd, $r1, $g1, $b1));

        $method = new \ReflectionMethod(SixelRenderer::class, 'indexRows');
        $method->setAccessible(true);
        $rows = $method->invoke($this->renderer, $gd, $palette, Dither::None, 0);

        return iterator_to_array($rows)[0];
    }

    public function testAtkinsonDitherDiffersFromNoDither(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/gradient_64x64.png');

        $none     = (new SixelRenderer(Dither::None))->render($source, 16, 12);
        $atkinson = (new SixelRenderer(Dither::Atkinson))->render($source, 16, 12);

        $this->assertNotSame($none, $atkinson);
    }

    // ─── Transparent background (DEBT #28) ─────────────────────────────────

    /** 4×4 PNG: transparent left column pair, opaque red right column pair. */
    private function halfTransparent(): ImageSource
    {
        $gd = imagecreatetruecolor(4, 4);
        $this->assertNotFalse($gd);
        imagealphablending($gd, false);
        imagesavealpha($gd, true);

        $transparent = imagecolorallocatealpha($gd, 0, 0, 0, 127);
        $red         = imagecolorallocate($gd, 255, 0, 0);
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                imagesetpixel($gd, $x, $y, $x < 2 ? $transparent : $red);
            }
        }

        return ImageSource::fromGd($gd, 'image/png');
    }

    public function testTransparentImageDeclaresBackgroundRegisterAndShiftsPalette(): void
    {
        $out = $this->renderer->render($this->halfTransparent(), 2, 2);

        // Register 0 becomes the transparent background (DEC `#0;2;P`)…
        $this->assertStringContainsString('#0;2;P', $out);
        // …the red palette entry moves to register 1…
        $this->assertStringContainsString('#1;2;100;0;0', $out);
        // …and register 0 is never declared with colour components.
        $this->assertStringNotContainsString('#0;2;100', $out);

        $this->assertStringStartsWith(self::ESC . 'P', $out);
        $this->assertStringEndsWith(self::ESC . '\\', $out);
    }

    public function testOpaqueImageEncodingIsUnchangedByTheAlphaPath(): void
    {
        $out = $this->renderer->render($this->red(), 8, 4);

        // No holes → no background register, palette stays 0-based.
        $this->assertStringNotContainsString('#0;2;P', $out);
        $this->assertStringContainsString('#0;2;100;0;0', $out);
    }

    public function testTransparentImageDithersWithoutBreakingTheBackground(): void
    {
        // Default renderer dithers (Floyd–Steinberg); holes must not diffuse
        // error, but the encoding must still carry the background register.
        $out = (new SixelRenderer(Dither::FloydSteinberg))
            ->render($this->halfTransparent(), 2, 2);

        $this->assertStringContainsString('#0;2;P', $out);
        $this->assertStringEndsWith(self::ESC . '\\', $out);
    }

    public function testFullyTransparentImageEmitsOnlyTheBackground(): void
    {
        $gd = imagecreatetruecolor(2, 2);
        $this->assertNotFalse($gd);
        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        $clear = imagecolorallocatealpha($gd, 0, 0, 0, 127);
        imagesetpixel($gd, 0, 0, $clear);
        imagesetpixel($gd, 1, 0, $clear);
        imagesetpixel($gd, 0, 1, $clear);
        imagesetpixel($gd, 1, 1, $clear);

        $out = (new SixelRenderer(Dither::None))->render(ImageSource::fromGd($gd, 'image/png'), 1, 1);

        $this->assertStringContainsString('#0;2;P', $out);
        $this->assertStringEndsWith(self::ESC . '\\', $out);
    }

    public function testTransparentSaturatedPaletteNeverAddressesRegister256(): void
    {
        // The background register is paid for out of the colour budget: with
        // any transparent pixel present and 256 distinct colours to spend,
        // the shifted palette may reach #255 at most — #256 is outside DEC's
        // 0..255 range and decoders that alias it onto 0 would paint over
        // the transparent background. 1×1 cells keep the pixel canvas at the
        // source's exact 17×16 grid, so resampling preserves the colour set.
        $gd = imagecreatetruecolor(17, 16);
        $this->assertNotFalse($gd);
        imagealphablending($gd, false);
        imagesavealpha($gd, true);

        imagesetpixel($gd, 0, 0, imagecolorallocatealpha($gd, 0, 0, 0, 127));
        for ($i = 0; $i < 271; $i++) {
            $x = ($i + 1) % 17;
            $y = intdiv($i + 1, 17);
            imagesetpixel($gd, $x, $y, imagecolorallocate($gd, $i % 256, intdiv($i, 256) * 4, ($i * 7) % 256));
        }

        $out = (new SixelRenderer(Dither::None, 256, 1, 1))->render(ImageSource::fromGd($gd, 'image/png'), 17, 16);

        $this->assertStringContainsString('#0;2;P', $out);
        $this->assertStringContainsString('#255;', $out, 'the palette must saturate the reduced budget');
        $this->assertStringNotContainsString('#256', $out);
    }
}
