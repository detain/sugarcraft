<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Dither;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\SixelRenderer;

final class SixelRendererTest extends TestCase
{
    private SixelRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new SixelRenderer();
    }

    public function testName(): void
    {
        $this->assertSame('sixel', $this->renderer->name());
    }

    public function testSupportsAlpha(): void
    {
        // Sixel has no alpha channel (1-bit bg at best).
        $this->assertFalse($this->renderer->supportsAlpha());
    }

    public function testRendersDcsHeader(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 8, 4);

        // Must begin with DCS (ESC P).
        $this->assertStringStartsWith("\x1bP", $out);
    }

    public function testRendersSixelTerminator(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 8, 4);

        // Must end with BEL.
        $this->assertStringEndsWith("\x07", $out);
    }

    public function testPaletteEntriesAreUnique(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 8, 4);

        // All-red image → median-cut should produce exactly 1 palette entry.
        preg_match_all('/\x1bP(\d+);\d+;\d+;\d+\$/S', $out, $m);
        $this->assertCount(1, array_unique($m[1]));
    }

    public function testDcsHeaderContainsPixelDimensions(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 8, 4);

        // The DCS header includes width;height params after the q.
        $this->assertStringContainsString('8;4', $out);
    }

    public function testEffectiveHeightComputedFromAspectRatio(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        // Source: 8×4 → aspect 2.0. With width=10 → expected height=5.
        $out = $this->renderer->render($source, 10);

        $this->assertStringContainsString('10;5', $out);
    }

    public function testExplicitHeightOverridesComputed(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 10, 7);

        $this->assertStringContainsString('10;7', $out);
    }

    public function testZeroWidthThrows(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($source, 0);
    }

    public function testNegativeWidthThrows(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($source, -1);
    }

    public function testZeroHeightThrows(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($source, 8, 0);
    }

    public function testNegativeHeightThrows(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($source, 8, -1);
    }

    public function testMultiColorImageHasMultiplePaletteEntries(): void
    {
        // The 500×400 noise fixture has many colors → median-cut should produce > 1 entry.
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/500x400_noise.png');
        $out    = $this->renderer->render($source, 40, 30);

        preg_match_all('/\x1bP(\d+);\d+;\d+;\d+\$/S', $out, $m);
        $unique = array_unique($m[1]);
        $this->assertGreaterThan(1, count($unique));
    }

    public function testSixelBytesArePrintable(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 8, 4);

        // Extract everything after the palette (last DCS ...$ ST) up to the final BEL.
        $lastDcsSt = strrpos($out, "\x1b\\");
        if ($lastDcsSt !== false) {
            $sixelData = substr($out, $lastDcsSt + 2, -1); // strip final BEL
        } else {
            // Fallback: strip DCS header and palette, take the rest minus terminator BEL
            $firstDcs = strpos($out, "\x1bP");
            $afterPalette = strrpos($out, "\x1b\\");
            $sixelData = substr($out, $afterPalette + 2, -1);
        }

        // All non-DCS bytes must be printable ASCII (range 32-126) per Sixel spec.
        for ($i = 0; $i < strlen($sixelData); $i++) {
            $cp = ord($sixelData[$i]);
            if ($cp < 32 || $cp > 126) {
                $this->fail("Non-printable byte at pos $i: 0x" . dechex($cp));
            }
        }
        $this->assertNotEmpty($sixelData);
    }

    public function testColorIntroducerFormat(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 8, 4);

        // Color introducer format: DCS Pn;r;g;b$ ST
        // Red = (255/255*100) ≈ 100 for each component.
        $this->assertStringContainsString("\x1bP0;100;0;0$\x1b\\", $out);
    }

    public function testSixelDataContainsNewlinesForMultiBand(): void
    {
        // For an image taller than 6 pixels, we get multiple bands.
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/500x400_noise.png');
        $out    = $this->renderer->render($source, 10, 20); // 20px tall → 4 bands

        // Each band advance (after band 0,1,2) emits a newline.
        $newlines = substr_count($out, "\n");
        $this->assertGreaterThanOrEqual(3, $newlines);
    }

    // ─── Dither enum ─────────────────────────────────────────────────────────

    public function testDitherEnumCases(): void
    {
        $this->assertSame('none',           Dither::None->value);
        $this->assertSame('floyd-steinberg', Dither::FloydSteinberg->value);
        $this->assertSame('stucki',         Dither::Stucki->value);
        $this->assertSame('atkinson',       Dither::Atkinson->value);
    }

    public function testDitherEnumLabels(): void
    {
        $this->assertSame('None',            Dither::None->label());
        $this->assertSame('Floyd–Steinberg', Dither::FloydSteinberg->label());
        $this->assertSame('Stucki',          Dither::Stucki->label());
        $this->assertSame('Atkinson',        Dither::Atkinson->label());
    }

    // ─── Dither rendering ────────────────────────────────────────────────────

    public function testSixelRendererDefaultIsFloydSteinberg(): void
    {
        // Default SixelRenderer construction uses Floyd–Steinberg.
        $r = new SixelRenderer();
        $this->assertSame(Dither::FloydSteinberg, $r->dither());
    }

    /** @return iterable<string, array{Dither}> */
    public static function ditherProvider(): iterable
    {
        yield 'none'           => [Dither::None];
        yield 'floyd-steinberg' => [Dither::FloydSteinberg];
        yield 'stucki'         => [Dither::Stucki];
        yield 'atkinson'       => [Dither::Atkinson];
    }

    /**
     * @dataProvider ditherProvider
     */
    public function testAllDitherModesRenderWithoutError(Dither $dither): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/500x400_noise.png');
        $r      = new SixelRenderer($dither);

        $out = $r->render($source, 20, 15);

        $this->assertStringStartsWith("\x1bP", $out);
        $this->assertStringEndsWith("\x07", $out);
    }

    public function testFloydSteinbergDiffersFromNoDither(): void
    {
        // Floyd–Steinberg error-diffusion must produce different output than
        // no-dithering on a non-trivial image (the gradient has smooth tonal
        // transitions where dithering creates visible palette patterns).
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/gradient_64x64.png');

        $none = (new SixelRenderer(Dither::None))->render($source, 40, 40);
        $fs   = (new SixelRenderer(Dither::FloydSteinberg))->render($source, 40, 40);

        // Both must produce valid sixel output (same structure).
        $this->assertStringStartsWith("\x1bP", $none);
        $this->assertStringStartsWith("\x1bP", $fs);

        // And they must differ (FS dithering changes pixel quantisation).
        $this->assertNotSame($none, $fs,
            'Floyd–Steinberg dithering should produce different output from no dithering'
        );
    }

    public function testStuckiDitherDiffersFromNoDither(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/gradient_64x64.png');

        $none  = (new SixelRenderer(Dither::None))->render($source, 40, 40);
        $stucki = (new SixelRenderer(Dither::Stucki))->render($source, 40, 40);

        $this->assertNotSame($none, $stucki,
            'Stucki dithering should produce different output from no dithering'
        );
    }

    public function testAtkinsonDitherDiffersFromNoDither(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/gradient_64x64.png');

        $none     = (new SixelRenderer(Dither::None))->render($source, 40, 40);
        $atkinson = (new SixelRenderer(Dither::Atkinson))->render($source, 40, 40);

        $this->assertNotSame($none, $atkinson,
            'Atkinson dithering should produce different output from no dithering'
        );
    }
}
