<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Dashboard\Plot\Braille\BrailleCanvas;
use SugarCraft\Style\Theme;

/**
 * Behavioral pins for the theme → render threading (docs-fix-1023 B1):
 * a `Theme` is not decoration-in-storage, its palette drives the pixels
 * of every LineChart series and legend indicator. The unthemed default
 * path must stay byte-identical (the render-dump family proves that at
 * suite scale; the pins here prove the feature exists and discriminates).
 */
final class ThemeRenderTest extends TestCase
{
    private const DATA = [1, 4, 2, 8, 6, 3, 7];

    private static function sgr(Color $color): string
    {
        return sprintf("\x1b[38;2;%d;%d;%dm", $color->r, $color->g, $color->b);
    }

    public function testThemedCellSeriesPaintThePrimarySlot(): void
    {
        $dracula = Theme::dracula();
        $output  = LineChart::new(self::DATA, 30, 6)
            ->withTheme($dracula)
            ->view();

        self::assertStringContainsString(self::sgr($dracula->primary), $output, 'series 0 must render in theme primary');
    }

    public function testThemedDatasetsWalkThePalette(): void
    {
        $dracula = Theme::dracula();
        $output  = LineChart::new(self::DATA, 30, 6)
            ->withDataset('beta', [2, 3, 5, 7, 6, 4, 5])
            ->withTheme($dracula)
            ->view();

        $palette = [
            $dracula->primary, $dracula->accent, $dracula->info,
            $dracula->success, $dracula->warning, $dracula->secondary, $dracula->error,
        ];
        // Primary series renders first (slot 0); datasets follow in
        // insertion order (slot 1..).
        self::assertStringContainsString(self::sgr($palette[0]), $output);
        self::assertStringContainsString(self::sgr($palette[1]), $output);
    }

    public function testTwoThemesRenderDifferentBytes(): void
    {
        $dracula = LineChart::new(self::DATA, 30, 6)->withTheme(Theme::dracula())->view();
        $oneDark = LineChart::new(self::DATA, 30, 6)->withTheme(Theme::oneDark())->view();

        self::assertNotSame($dracula, $oneDark, 'theme choice must be observable in the render');
        self::assertStringContainsString(self::sgr(Theme::oneDark()->primary), $oneDark);
        self::assertStringNotContainsString(self::sgr(Theme::dracula()->primary), $oneDark);
    }

    public function testUnthemedCellOutputCarriesNoPaletteSgr(): void
    {
        $output = LineChart::new(self::DATA, 30, 6)->view();

        self::assertStringNotContainsString("\x1b[38;2;", $output, 'default path stays unstyled (byte-identical rule)');
    }

    public function testThemedBrailleSeriesPaintThePaletteToo(): void
    {
        $dracula = Theme::dracula();
        $themed  = LineChart::new(self::DATA, 40, 8)
            ->withCanvas(BrailleCanvas::new(80, 24))
            ->withTheme($dracula)
            ->view();

        self::assertStringContainsString(self::sgr($dracula->primary), $themed);
    }

    public function testThemedLegendIndicatorsMatchTheirSeries(): void
    {
        $dracula = Theme::dracula();
        $output  = LineChart::new(self::DATA, 30, 6)
            ->withDataset('beta', [2, 3, 5, 7, 6, 4, 5])
            ->withTheme($dracula)
            ->withLegend(true)
            ->view();

        // Legend order is dataset-name order while render order starts
        // with the implicit primary series: "beta" is legend slot 0 but
        // render slot 1, so its indicator must carry palette[1] — the
        // same color as its stroke, not the CSS-name color path.
        $indicator = self::sgr($dracula->accent);
        self::assertStringContainsString($indicator, $output);
    }

    public function testPerPointResolverStillOverridesThemeSlot(): void
    {
        $hot     = Color::rgb(250, 100, 100);
        $dracula = Theme::dracula();
        $output  = LineChart::new(self::DATA, 30, 6)
            ->withTheme($dracula)
            ->withSeriesColorFn(static fn(string $dataset, int $x, float $value): ?Color
                => $value >= 8 ? $hot : null)
            ->view();

        self::assertStringContainsString(self::sgr($hot), $output, 'resolver wins for its points');
        self::assertStringContainsString(self::sgr($dracula->primary), $output, 'non-resolved points fall back to the theme slot');
    }
}
