<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Charts\Canvas\Graph;
use SugarCraft\Charts\Chart\Chart;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\TimeSeries;
use SugarCraft\Charts\MarkLine;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;

/**
 * The legacy alias windows (sugar-charts-016, CH-43, CH-58) closed in C2:
 * every `get*` / style-named / `show*` spelling below was a forwarder to a
 * canonical method and had no production caller. This pins that each one
 * is gone, and that the canonical surface they forwarded to still behaves.
 * The per-method value coverage now lives with the canonical tests
 * (GraphTest, CanvasTest, HeatmapTest, LineChartAnimationTest, ...).
 */
final class GetAliasWindowTest extends TestCase
{
    /** @return list<array{0:string,1:string}> */
    public static function retiredMethodProvider(): array
    {
        return [
            [TimeSeries::class, 'getTimeRange'],
            [Chart::class, 'getAnimationProgress'],
            [Chart::class, 'getAnimationDuration'],
            [LineChart::class, 'getAnimationProgress'],
            [LineChart::class, 'getAnimationDuration'],
            [Heatmap::class, 'getCellStyle'],
            [Heatmap::class, 'getMonoRamp'],
            [Heatmap::class, 'getAutoValueRange'],
            [Chart::class, 'withLegendStyle'],
            [Chart::class, 'legendStyle'],
            [LineChart::class, 'withLegendStyle'],
            [BarChart::class, 'withLegendStyle'],
            [BarChart::class, 'legendStyle'],
            [Scatter::class, 'withLegendStyle'],
            [Scatter::class, 'legendStyle'],
            [OHLCChart::class, 'withLegendStyle'],
            [OHLCChart::class, 'legendStyle'],
            [BarChart::class, 'withShowAxis'],
            [BarChart::class, 'showAxis'],
            [Sparkline::class, 'withNoAutoMaxValue'],
            [Sparkline::class, 'noAutoMaxValue'],
            [Graph::class, 'getCirclePoints'],
            [Graph::class, 'getCirclePointsWithLimit'],
            [Graph::class, 'getFullCirclePoints'],
            [Graph::class, 'getFullCirclePointsWithLimit'],
            [Graph::class, 'getLinePointsWithLimit'],
            [Canvas::class, 'getCell'],
            [Canvas::class, 'getCellStyle'],
        ];
    }

    /** @dataProvider retiredMethodProvider */
    public function testRetiredWindowNameIsGone(string $class, string $method): void
    {
        $this->assertFalse(
            method_exists($class, $method),
            $class . '::' . $method . '() is a retired alias window and must not come back',
        );
    }

    public function testRetiredMarkLineStyleConstantsAreGone(): void
    {
        foreach (['STYLE_SOLID', 'STYLE_DASHED', 'STYLE_DOTTED'] as $const) {
            $this->assertFalse(defined(MarkLine::class . '::' . $const), 'MarkLine::' . $const . ' was retired');
        }
    }

    public function testRetiredOhlcBarClassAliasIsGone(): void
    {
        $this->assertFalse(class_exists('SugarCraft\\Charts\\OHLC\\Bar', false));
    }

    public function testRetiredSparklineAutoMaxFlagIsGone(): void
    {
        $this->assertFalse(property_exists(Sparkline::class, 'autoMaxValue'));
    }

    public function testTimeSeriesRangeSetterArmReturnsAnInstance(): void
    {
        $ts = TimeSeries::new([]);
        $start = new \DateTimeImmutable('2026-01-01');
        $end = new \DateTimeImmutable('2026-01-02');
        $set = $ts->withTimeRange($start, $end);
        $this->assertNotSame($ts, $set);
        $this->assertSame([$start, $end], $set->timeRange());
    }

    public function testAnimationSetterArmIsDistinctFromTheRead(): void
    {
        $c = LineChart::new([1.0, 2.0])->withAnimationProgress(0.5)->withAnimationDuration(250);
        $this->assertSame(0.5, $c->animationProgress());
        $this->assertSame(250, $c->animationDuration());
        $this->assertNotSame($c, $c->animationProgress(1.0));
    }

    /**
     * CH-58 (sugar-charts-092): withLegendIndicator/legendIndicator are the
     * only legend-indicator spellings left on the five seats.
     *
     * @return list<array{0:object}>
     */
    public static function legendSeatProvider(): array
    {
        return [
            'LineChart' => [LineChart::new([1.0, 2.0])],
            'BarChart' => [BarChart::new([['a', 1.0]])],
            'Scatter' => [Scatter::new()],
            'OHLCChart' => [OHLCChart::new([])],
        ];
    }

    /** @dataProvider legendSeatProvider */
    public function testLegendIndicatorCanonicalRoundTrips(object $c): void
    {
        $this->assertNull($c->legendIndicator());
        $set = $c->withLegendIndicator('◆');
        $this->assertSame('◆', $set->legendIndicator());
        $this->assertNotSame($c, $c->legendIndicator('●'));
        $this->assertSame('●', $c->legendIndicator('●')->legendIndicator());
        // explicit null is a clear, not a bare read
        $this->assertNull($set->legendIndicator(null)->legendIndicator());
    }

    public function testHeatmapKnobsReadBackCanonically(): void
    {
        $h = Heatmap::new([[1.0]])->withMonoRamp(true)->withAutoValueRange(false);
        $this->assertTrue($h->monoRamp());
        $this->assertFalse($h->autoValueRange());
        $this->assertNull($h->cellStyle());
        $styled = $h->cellStyle(\SugarCraft\Style\Style::new()->bold());
        $this->assertNotSame($h, $styled);
        $this->assertNotNull($styled->cellStyle());
        // explicit-null clear arm must still reach the setter (null != bare read)
        $this->assertNull($styled->cellStyle(null)->cellStyle());
    }
}
