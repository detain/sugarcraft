<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Dither;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\SixelRenderer;

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
        $this->assertFalse($this->renderer->supportsAlpha());
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
    }

    public function testAtkinsonDitherDiffersFromNoDither(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/gradient_64x64.png');

        $none     = (new SixelRenderer(Dither::None))->render($source, 16, 12);
        $atkinson = (new SixelRenderer(Dither::Atkinson))->render($source, 16, 12);

        $this->assertNotSame($none, $atkinson);
    }
}
