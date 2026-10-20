<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\AsciiColorMode;
use SugarCraft\Image\Renderer\AsciiRenderer;

/**
 * C-06: luma dithering wired into the ascii ramp.
 *
 * Chafa dithers in its common prepare_pixels pass BEFORE the picker
 * thresholds a value (chafa-pixops.c:486-694); this renderer quantised cold
 * (nearest ramp char, zero error control), so gradients band to a single
 * step whenever their span is smaller than one ramp bin — exactly the
 * collapse the survey pinned (:368-373, "assert gradients produce ≥2
 * distinct ramp steps where the un-dithered path collapses to 1"). The
 * default path stays byte-frozen (Dither::None is what every existing
 * golden, and the DithererTest arm map, pins); these tests cover only the
 * opt-in arms and their refusal edges.
 */
final class AsciiLumaDitherTest extends TestCase
{
    private const WIDTH = 64;
    private const HEIGHT = 3;

    public function testDefaultsColdQuantiseAndCollapseASubBinGradient(): void
    {
        $renderer = new AsciiRenderer(AsciiColorMode::Mono);
        $this->assertSame(Dither::None, $renderer->dither());
        $this->assertNull($renderer->intensity());
        $this->assertFalse($renderer->serpentine());

        $cold = $renderer->render(self::subBinGradient(), self::WIDTH, self::HEIGHT);
        $this->assertSame(
            1,
            count($this->distinctRunes($cold)),
            'the un-dithered path must collapse this sub-bin gradient to one ramp step',
        );
    }

    public function testFloydSteinbergBreaksTheRampBanding(): void
    {
        $out = (new AsciiRenderer(AsciiColorMode::Mono, dither: Dither::FloydSteinberg))
            ->render(self::subBinGradient(), self::WIDTH, self::HEIGHT);

        $distinct = count($this->distinctRunes($out));
        $this->assertGreaterThanOrEqual(2, $distinct, 'FS must walk the ramp where the cold path stuck');
        $this->assertStringNotContainsString("\r", $out, 'rows still join with bare newlines');

        foreach (explode("\n", $out) as $row) {
            $this->assertSame(self::WIDTH, strlen($row));
        }
    }

    public function testOrderedAndNoiseBiasArmsAlsoBreakTheCollapse(): void
    {
        $img = self::subBinGradient();
        $bias = [];
        foreach ([Dither::Ordered, Dither::Noise] as $dither) {
            $out = (new AsciiRenderer(AsciiColorMode::Mono, dither: $dither))
                ->render($img, self::WIDTH, self::HEIGHT);
            $this->assertGreaterThanOrEqual(2, count($this->distinctRunes($out)), $dither->value);
            $bias[$dither->value] = $out;
        }
        $this->assertNotSame($bias['ordered'], $bias['noise'], 'the two bias tables must not render identically');
    }

    public function testSerpentineMirrorsOddRows(): void
    {
        $img = self::subBinGradient();
        $straight = (new AsciiRenderer(AsciiColorMode::Mono, dither: Dither::FloydSteinberg))
            ->render($img, self::WIDTH, self::HEIGHT);
        $snaked = (new AsciiRenderer(AsciiColorMode::Mono, dither: Dither::FloydSteinberg, serpentine: true))
            ->render($img, self::WIDTH, self::HEIGHT);

        $sRows = explode("\n", $straight);
        $nRows = explode("\n", $snaked);
        $this->assertSame($sRows[0], $nRows[0], 'even rows walk identically');
        $this->assertNotSame($sRows[1], $nRows[1], 'odd rows must reverse-walk under serpentine');
    }

    public function testColourPensCarryTheRawPixelNotTheDiffusedLuma(): void
    {
        // The dither chooses glyphs; the tint stays per-pixel — the renderer
        // contract is one source pixel per cell on the colour axis.
        $gd = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        self::assertInstanceOf(\GdImage::class, $gd);
        imagealphablending($gd, false);
        for ($y = 0; $y < self::HEIGHT; $y++) {
            for ($x = 0; $x < self::WIDTH; $x++) {
                $v = 70 + intdiv($x * 5, self::WIDTH);
                // Red carries a visible per-column hue offset so SGR bytes
                // are column-dependent even though only luma is diffused.
                imagesetpixel($gd, $x, $y, (($v + 15) << 16) | ($v << 8) | $v);
            }
        }
        $img = ImageSource::fromGd($gd, 'image/png');
        imagedestroy($gd);

        $out = (new AsciiRenderer(AsciiColorMode::TrueColor, dither: Dither::FloydSteinberg))
            ->render($img, self::WIDTH, self::HEIGHT);

        $introducers = substr_count($out, "\x1b[38;2;");
        $this->assertGreaterThan(0, $introducers);
        // Every column shift changes red, so the pen for column x must name
        // that column's raw red value — never a luma-neighbour's.
        $this->assertStringContainsString("\x1b[38;2;85;", $out, 'raw red 85 (=70+15) must appear at column 0');
        $this->assertStringContainsString("\x1b[38;2;89;", $out, 'raw red 89 must appear at the last column');
    }

    public function testIntensityEdgesAreRefused(): void
    {
        foreach (['negative' => -0.1, 'nan' => NAN] as $label => $bad) {
            $thrown = null;
            try {
                new AsciiRenderer(intensity: $bad);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown, "intensity $label must be refused");
            $this->assertSame('ascii intensity must be between 0.0 and 1.0', $thrown->getMessage());
        }
    }

    public function testIntensityAboveOneClampsWithoutRefusal(): void
    {
        $renderer = new AsciiRenderer(intensity: 1.5);
        $this->assertSame(1.0, $renderer->intensity());
    }

    /**
     * A gradient whose span (70..74) sits entirely inside one 15-step ramp
     * bin — the cold quantiser has no threshold to cross, FS does.
     */
    private static function subBinGradient(): ImageSource
    {
        $gd = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (!$gd instanceof \GdImage) {
            throw new \RuntimeException('GD fixture creation failed');
        }
        imagealphablending($gd, false);
        for ($y = 0; $y < self::HEIGHT; $y++) {
            for ($x = 0; $x < self::WIDTH; $x++) {
                $v = 70 + intdiv($x * 4, self::WIDTH);
                imagesetpixel($gd, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }
        $img = ImageSource::fromGd($gd, 'image/png');
        imagedestroy($gd);

        return $img;
    }

    /**
     * Distinct rune bytes in a Mono render (newlines are not runes).
     *
     * @return list<string>
     */
    private function distinctRunes(string $mono): array
    {
        return array_values(array_unique(array_filter(
            str_split($mono),
            static fn(string $c): bool => $c !== "\n",
        )));
    }
}
