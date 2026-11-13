<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Support;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\TimeSeries;
use SugarCraft\Charts\LineChart\Waveline;
use SugarCraft\Charts\OHLC\Bar;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;

/**
 * Audit F13: the empty-render shapes differ per class by design. Pin the
 * three families so a refactor cannot silently change one:
 *  - '' (no canvas at all):        BarChart, Scatter, Heatmap, OHLCChart
 *  - blank width×height canvas:    LineChart (and Chart-base subclasses)
 *  - one row of spaces:            Sparkline
 *
 * CH-08 (sugar-charts-087) adds the zero-dimension rule on top: with a
 * declared width or height below 1 EVERY renderer returns '' (chrome
 * included), and Canvas itself refuses to be constructed that small.
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

    /**
     * CH-08: zero dimensions render '' for every grid renderer, WITH DATA
     * loaded (the old off-switch quietly painted blank canvases here).
     *
     * @return array<string, array{0: callable(): string}>
     */
    public static function zeroDimProvider(): array
    {
        $stamp = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-01-01 00:00:00');
        return [
            'LineChart'      => [static fn(): string => LineChart::new([1.0, 2.0], 0, 4)->view()],
            'LineChart+chrome' => [static fn(): string => LineChart::new([1.0, 2.0], 0, 4)
                ->withTitle('T')->withYLabel('Y')->view()],
            'BarChart'       => [static fn(): string => BarChart::new([['a', 1.0]], 0, 4)->view()],
            'Scatter'        => [static fn(): string => Scatter::new([[1.0, 2.0]], 0, 4)->view()],
            'OHLCChart'      => [static fn(): string => OHLCChart::new(
                [new Bar(open: 1.0, high: 2.0, low: 0.5, close: 1.5)], 0, 4)->view()],
            'Heatmap'        => [static fn(): string => Heatmap::new([[1.0]])->withSize(0, 4)->view()],
            'Waveline'       => [static fn(): string => Waveline::new([[1.0, 2.0]], 0, 4)->view()],
            'TimeSeries'     => [static fn(): string => TimeSeries::new([[$stamp(), 1.0]], 0, 4)->view()],
            'TimeSeries-no-points' => [static fn(): string => TimeSeries::new([], 0, 4)->view()],
            'TimeSeries-empty-window' => [static fn(): string => TimeSeries::new([[$stamp(), 1.0]], 0, 4)
                ->withTimeRange($stamp()->modify('+10 hours'), $stamp()->modify('+11 hours'))->view()],
            'Sparkline'      => [static fn(): string => Sparkline::new([1.0], 0)->view()],
        ];
    }

    /** @dataProvider zeroDimProvider */
    public function testZeroDimensionsRenderEmptyString(callable $render): void
    {
        self::assertSame('', $render());
    }

    public function testCanvasRefusesSubUnitFrames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Canvas(1, 0);
    }
}
