<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Style\Theme;

final class BrailleChartTest extends TestCase
{
    public function testLineChartWithBrailleCanvas(): void
    {
        // CH-04: braille mode is selected by withBraille(); the render
        // target is an optional CellGrid bridge, not a mode flag.
        $canvas = new \SugarCraft\Charts\Canvas\Canvas(80, 24);
        $chart = LineChart::new([1, 4, 2, 8, 6, 3, 7], 40, 8)
            ->withBraille(true)
            ->withCanvas($canvas);

        $this->assertTrue($chart->braille);
        $this->assertSame($canvas, $chart->canvas);
        $output = $chart->view();
        // Braille rendering produces braille unicode characters
        $this->assertMatchesRegularExpression('/[\x{2800}-\x{28FF}]/u', $output);
        // CH-04 bridge: the render actually landed in the provided grid.
        $this->assertSame($output, $canvas->view());
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
        $canvas = new \SugarCraft\Charts\Canvas\Canvas(80, 24);
        $chart = LineChart::new([1, 4, 2, 8, 6, 3, 7], 40, 8)
            ->withBraille(true)
            ->withCanvas($canvas)
            ->withTheme(Theme::oneDark());

        $this->assertNotNull($chart->canvas);
        $this->assertTrue($chart->braille);
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
            ->withBraille(true);
        $out = $chart->view();
        $this->assertSame(1, preg_match('/[\x{2800}-\x{28FF}]/u', $out), 'expected at least one braille rune');
        $this->assertStringNotContainsString('*', $out);
    }

    /**
     * Audit F11: per-dataset colors must reach the strokes. The first
     * named dataset cycles to the lead palette color — 'blue' since the
     * lib-reviews-1103 color-blind-safe reorder (M13; the old lead pair
     * red/green collided for deutan vision) — rendered as its truecolor
     * SGR (38;2;0;0;238); an unnamed primary series stays unstyled exactly
     * like ASCII mode.
     */
    public function testBrailleModeHonoursPerDatasetColors(): void
    {
        $chart = LineChart::new([], 40, 8)
            ->withDataset('up', [1, 4, 2, 8, 6, 3, 7])
            ->withBraille(true);
        $out = $chart->view();
        $this->assertSame(1, preg_match('/[\x{2800}-\x{28FF}]/u', $out));
        $this->assertStringContainsString("\x1b[38;2;0;0;238m", $out, 'blue SGR expected on the first dataset stroke');
    }

    /**
     * CH-04 (inverts audit F11's old "never mutated" confession): the grid
     * handed to withCanvas IS the render target — its content equals the
     * chart's view(), and braille mode composes with the bridge.
     */
    public function testBrailleModePaintsTheGivenCanvas(): void
    {
        $canvas = new \SugarCraft\Charts\Canvas\Canvas(80, 24);
        $blank = (new \SugarCraft\Charts\Canvas\Canvas(80, 24))->view();
        $out = LineChart::new([1, 4, 2, 8, 6, 3, 7], 40, 8)->withBraille(true)->withCanvas($canvas)->view();
        $this->assertSame($out, $canvas->view());
        $this->assertNotSame($blank, $canvas->view(), 'the provided grid received the render');
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
