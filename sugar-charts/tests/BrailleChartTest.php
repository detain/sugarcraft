<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Dash\Plot\Braille\BrailleCanvas;
use SugarCraft\Sprinkles\Theme;

final class BrailleChartTest extends TestCase
{
    public function testLineChartWithBrailleCanvas(): void
    {
        $canvas = BrailleCanvas::new(80, 24);
        $chart = LineChart::new([1, 4, 2, 8, 6, 3, 7], 40, 8)
            ->withCanvas($canvas);

        $this->assertNotNull($chart->brailleCanvas);
        $output = $chart->view();
        // Braille rendering produces braille unicode characters
        $this->assertIsString($output);
    }

    public function testLineChartWithTheme(): void
    {
        $chart = LineChart::new([1, 4, 2, 8, 6, 3, 7], 40, 8)
            ->withTheme(Theme::dracula());

        $this->assertNotNull($chart->theme);
        $this->assertSame('dracula', $this->getThemeName($chart->theme));
    }

    public function testLineChartWithBothCanvasAndTheme(): void
    {
        $canvas = BrailleCanvas::new(80, 24);
        $chart = LineChart::new([1, 4, 2, 8, 6, 3, 7], 40, 8)
            ->withCanvas($canvas)
            ->withTheme(Theme::oneDark());

        $this->assertNotNull($chart->brailleCanvas);
        $this->assertNotNull($chart->theme);
    }

    public function testThemeDraculaHasCorrectColors(): void
    {
        $theme = Theme::dracula();
        $this->assertSame('#f8f8f2', $theme->foreground->toHex());
        $this->assertSame('#282a36', $theme->background->toHex());
        $this->assertSame('#bd93f9', $theme->primary->toHex());
    }

    public function testThemeOneDarkHasCorrectColors(): void
    {
        $theme = Theme::oneDark();
        $this->assertSame('#abb2bf', $theme->foreground->toHex());
        $this->assertSame('#282c34', $theme->background->toHex());
        $this->assertSame('#61afef', $theme->primary->toHex());
    }

    public function testThemeTokyoNightFactory(): void
    {
        $theme = Theme::tokyoNight();
        $this->assertSame('#c0caf5', $theme->foreground->toHex());
        $this->assertSame('#1a1b26', $theme->background->toHex());
    }

    public function testThemeAdaptive(): void
    {
        $theme = Theme::adaptive();
        // Should return either dark or light - just verify it doesn't throw
        $this->assertNotNull($theme);
    }

    public function testThemeAnsi(): void
    {
        $theme = Theme::ansi();
        // ANSI color 7 is bright white (not pure #ffffff in all terminals)
        $this->assertNotEmpty($theme->foreground->toHex());
        $this->assertNotEmpty($theme->background->toHex());
    }

    /**
     * Audit F11: with a canvas attached the series must actually render as
     * braille — real U+2800-block runes in the plot area, not the ASCII
     * '*'/'/' fallback (the old code threaded the canvas and ignored it).
     */
    public function testBrailleModeRendersBrailleRunes(): void
    {
        $chart = LineChart::new([1, 4, 2, 8, 6, 3, 7], 40, 8)
            ->withCanvas(BrailleCanvas::new(80, 24));
        $out = $chart->view();
        $this->assertSame(1, preg_match('/[\x{2800}-\x{28FF}]/u', $out), 'expected at least one braille rune');
        $this->assertStringNotContainsString('*', $out);
    }

    /**
     * Audit F11: per-dataset colors must reach the strokes. The first
     * named dataset cycles to 'red' — rendered as the xterm-256 derived
     * truecolor SGR (38;2;205;0;0 = #cd0000); an unnamed primary series
     * stays unstyled exactly like ASCII mode.
     */
    public function testBrailleModeHonoursPerDatasetColors(): void
    {
        $chart = LineChart::new([], 40, 8)
            ->withDataset('up', [1, 4, 2, 8, 6, 3, 7])
            ->withCanvas(BrailleCanvas::new(80, 24));
        $out = $chart->view();
        $this->assertSame(1, preg_match('/[\x{2800}-\x{28FF}]/u', $out));
        $this->assertStringContainsString("\x1b[38;2;205;0;0m", $out, 'red SGR expected on the first dataset stroke');
    }

    /**
     * Audit F11 (batched rasterizer law): the canvas instance handed to
     * withCanvas is never mutated — the chart rasterizes internally and
     * the object only selects the rendering mode (docblocked contract).
     */
    public function testBrailleModeDoesNotMutateTheGivenCanvas(): void
    {
        $canvas = BrailleCanvas::new(80, 24);
        $before = $canvas->render();
        LineChart::new([1, 4, 2, 8, 6, 3, 7], 40, 8)->withCanvas($canvas)->view();
        $this->assertSame($before, $canvas->render());
    }

    private function getThemeName(Theme $theme): string
    {
        // Check via hex values since themes don't expose a name property
        if ($theme->foreground->toHex() === '#f8f8f2') {
            return 'dracula';
        }
        if ($theme->foreground->toHex() === '#abb2bf') {
            return 'oneDark';
        }
        return 'unknown';
    }
}
