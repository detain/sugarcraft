<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;

/**
 * Audit F13: the empty-render shapes differ per class by design. Pin the
 * three families so a refactor cannot silently change one:
 *  - '' (no canvas at all):        BarChart, Scatter, Heatmap, OHLCChart
 *  - blank width×height canvas:    LineChart (and Chart-base subclasses)
 *  - one row of spaces:            Sparkline
 */
final class EmptyRenderContractTest extends TestCase
{
    public function testBarChartRendersEmptyString(): void
    {
        self::assertSame('', BarChart::new([], 10, 4)->view());
    }

    public function testScatterRendersEmptyString(): void
    {
        self::assertSame('', Scatter::new([], 10, 4)->view());
    }

    public function testHeatmapRendersEmptyString(): void
    {
        self::assertSame('', Heatmap::new([], 10, 4)->view());
    }

    public function testOhlcChartRendersEmptyString(): void
    {
        self::assertSame('', OHLCChart::new([], 10, 4)->view());
    }

    public function testLineChartRendersBlankCanvas(): void
    {
        // Canvas rows are right-trimmed, so the blank 10×4 canvas is four
        // empty lines joined by newlines — not '' (BarChart family) and
        // not a space row (Sparkline).
        self::assertSame("\n\n\n", LineChart::new([], 10, 4)->view());
    }

    public function testSparklineRendersRowOfSpaces(): void
    {
        self::assertSame(str_repeat(' ', 10), Sparkline::new([], 10)->view());
    }
}
