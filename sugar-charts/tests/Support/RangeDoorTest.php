<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Aggregation\Resample;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\OHLC\Bar as OhlcBar;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Charts\Support\Range;

/**
 * Range::pin doors on every chart class: an inverted pinned range throws
 * (fail-loud — a silent axis clip is the defect this closes), null ends
 * stay legal (they mean "auto"), and autoAdjustRange still resets.
 */
final class RangeDoorTest extends TestCase
{
    public function testInvertedYRangeThrowsOnLineChart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LineChart::new([1, 2, 3])->withYRange(10.0, 5.0);
    }

    public function testInvertedChainThrowsOnSecondSetter(): void
    {
        $chart = LineChart::new([1, 2, 3])->withMin(10.0);
        $this->expectException(InvalidArgumentException::class);
        $chart->withMax(5.0);
    }

    public function testInvertedXRangeThrowsOnLineChart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LineChart::new([1, 2, 3])->withXRange(9.0, 1.0);
    }

    public function testValidPinnedRangeIsAccepted(): void
    {
        $chart = LineChart::new([1, 2, 3])->withYRange(0.0, 10.0);
        self::assertSame(0.0, $chart->min);
        self::assertSame(10.0, $chart->max);
    }

    public function testNullEndsStayLegal(): void
    {
        $chart = LineChart::new([1, 2, 3])->withMin(5.0)->withMin(null);
        self::assertNull($chart->min);
    }

    public function testAutoAdjustRangeStillResetsPins(): void
    {
        $chart = LineChart::new([1, 2, 3], 10, 4)->withYRange(10.0, 20.0)->autoAdjustRange();
        self::assertNull($chart->min);
        self::assertNull($chart->max);
        // After the all-null reset the door must still be open: pinning a
        // valid pair works, and only an inverted pair throws.
        $reopened = $chart->withMin(3.0)->withMax(4.0);
        self::assertSame(3.0, $reopened->min);
    }

    public function testInvertedRangeThrowsOnBarChart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BarChart::new([['a', 1.0]])->withMin(10.0)->withMax(5.0);
    }

    public function testInvertedRangeThrowsOnScatter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Scatter::new([[1, 1]])->withXRange(5.0, 1.0);
    }

    public function testInvertedRangeThrowsOnSparkline(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sparkline::new([1, 2, 3])->withMin(9)->withMax(1);
    }

    public function testInvertedRangeThrowsOnHeatmap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Heatmap::new([[1.0]])->withMin(9.0)->withMax(1.0);
    }

    public function testInvertedRangeThrowsOnOhlcChart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OHLCChart::new()->withMin(5.0)->withMax(1.0);
    }

    public function testPinRejectsEqualBoundsSilently(): void
    {
        // min === max is legal (degenerate axis, classes handle it).
        Range::pin(4.0, 4.0, 'y');
        $this->expectNotToPerformAssertions();
    }

    // ─── Audit F8: OHLC candle invariants ───────────────────────────────

    public function testOhlcBarRejectsHighBelowLow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OhlcBar(100.0, 101.0, 102.0, 100.5); // high 101 < low 102
    }

    public function testOhlcBarAcceptsValidCandle(): void
    {
        $bar = new OhlcBar(100.0, 105.0, 98.0, 103.0);
        self::assertSame(105.0, $bar->high);
        self::assertSame(98.0, $bar->low);
    }

    // ─── Audit F10: Resample infinite-loop door ─────────────────────────

    public function testResampleRejectsZeroInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Resample::new(0);
    }

    public function testResampleRejectsNegativeInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Resample::last(-5, [['ts' => 0, 'value' => 1.0]]);
    }

    public function testResampleAcceptsPositiveInterval(): void
    {
        $out = Resample::new(10)->add(0, 1.0);
        self::assertInstanceOf(Resample::class, $out);
    }

    // ─── Audit F12: Scatter clamps OOB points to the edge ────────────────

    public function testScatterClampsOutOfRangePointsToEdge(): void
    {
        // A point far below the pinned range must land on the bottom-left
        // cell, not be silently dropped (its in-range twin renders alike).
        $clamped = Scatter::new([[-100.0, -100.0]], 6, 4)
            ->withXRange(0.0, 10.0)->withYRange(0.0, 10.0)->view();
        $edge = Scatter::new([[0.0, 0.0]], 6, 4)
            ->withXRange(0.0, 10.0)->withYRange(0.0, 10.0)->view();
        self::assertSame($edge, $clamped);
    }
}
