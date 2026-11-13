<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Charts\Canvas\Graph;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\TimeSeries;

/**
 * CH-43 (sugar-charts-016): the bare spellings became primary and the
 * legacy `get*` names survive for exactly one minor as the house alias
 * window. This pins (a) value/byte parity between each pair and (b) the
 * @deprecated marker on every windowed name — the marker is what makes
 * the retirement date legal, so a silent re-promotion is a test failure.
 */
final class GetAliasWindowTest extends TestCase
{
    public function testTimeSeriesRangePairAgrees(): void
    {
        $ts = TimeSeries::new([])->withTimeRange(null, null);
        $this->assertSame($ts->getTimeRange(), $ts->timeRange());
    }

    public function testTimeSeriesSetterArmStillReturnsInstance(): void
    {
        $ts = TimeSeries::new([]);
        $start = new \DateTimeImmutable('2026-01-01');
        $end = new \DateTimeImmutable('2026-01-02');
        $set = $ts->timeRange($start, $end);
        $this->assertNotSame($ts, $set);
        $this->assertSame([$start, $end], $set->timeRange());
        $this->assertSame([$start, $end], $set->getTimeRange());
    }

    public function testAnimationPairAgreesAndSetterArmIsDistinct(): void
    {
        $c = LineChart::new([1.0, 2.0])->withAnimationProgress(0.5)->withAnimationDuration(250);
        $this->assertSame(0.5, $c->animationProgress());
        $this->assertSame($c->animationProgress(), $c->getAnimationProgress());
        $this->assertSame(250, $c->animationDuration());
        $this->assertSame($c->animationDuration(), $c->getAnimationDuration());
        $this->assertNotSame($c, $c->animationProgress(1.0));
    }

    /**
     * CH-58 (sugar-charts-092): withLegendIndicator/legendIndicator are
     * canonical; the style-named pair forwards to them on all five seats.
     *
     * @return list<array{0:object}>
     */
    public static function legendSeatProvider(): array
    {
        return [
            'LineChart' => [LineChart::new([1.0, 2.0])],
            'BarChart' => [\SugarCraft\Charts\BarChart\BarChart::new([['a', 1.0]])],
            'Scatter' => [\SugarCraft\Charts\Scatter\Scatter::new()],
            'OHLCChart' => [\SugarCraft\Charts\OHLC\OHLCChart::new([])],
        ];
    }

    /** @dataProvider legendSeatProvider */
    public function testLegendIndicatorCanonicalAndStyleForwarderAgree(object $c): void
    {
        $this->assertNull($c->legendIndicator());
        $set = $c->withLegendIndicator('◆');
        $this->assertSame('◆', $set->legendIndicator());
        $this->assertEquals($set, $c->withLegendStyle('◆'));
        $this->assertEquals($set, $c->legendStyle('◆'));
        $this->assertNotSame($c, $c->legendIndicator('●'));
        $this->assertSame('●', $c->legendIndicator('●')->legendIndicator());
        // explicit null is a clear, not a bare read
        $this->assertNull($set->legendIndicator(null)->legendIndicator());
    }

    public function testHeatmapKnobPairsAgree(): void
    {
        $h = Heatmap::new([[1.0]])->withMonoRamp(true)->withAutoValueRange(false);
        $this->assertTrue($h->monoRamp());
        $this->assertSame($h->monoRamp(), $h->getMonoRamp());
        $this->assertFalse($h->autoValueRange());
        $this->assertSame($h->autoValueRange(), $h->getAutoValueRange());
        $this->assertNull($h->cellStyle());
        $this->assertSame($h->cellStyle(), $h->getCellStyle());
        $styled = $h->cellStyle(\SugarCraft\Style\Style::new()->bold());
        $this->assertNotSame($h, $styled);
        $this->assertNotNull($styled->cellStyle());
        // explicit-null clear arm must still reach the setter (null != bare read)
        $this->assertNull($styled->cellStyle(null)->cellStyle());
    }

    /**
     * @dataProvider samplePairProvider
     */
    public function testGraphSamplingPairParity(string $get, string $bare, array $args): void
    {
        $this->assertSame(
            Graph::$get(...$args),
            Graph::$bare(...$args),
            $get . ' must stay value-identical to ' . $bare . ' through the window',
        );
    }

    /** @return list<array{0:string,1:string,2:array<int,int>}> */
    public static function samplePairProvider(): array
    {
        return [
            ['getCirclePoints', 'circlePoints', [4, 4, 3]],
            ['getCirclePointsWithLimit', 'circlePointsWithLimit', [4, 4, 3, 6]],
            ['getFullCirclePoints', 'fullCirclePoints', [4, 4, 3]],
            ['getFullCirclePointsWithLimit', 'fullCirclePointsWithLimit', [4, 4, 3, 6]],
            ['getLinePointsWithLimit', 'linePointsWithLimit', [0, 0, 9, 5, 40]],
        ];
    }

    /**
     * @dataProvider windowedNameProvider
     */
    public function testWindowedNamesCarryDeprecatedMarker(string $class, string $method): void
    {
        $doc = (new ReflectionMethod($class, $method))->getDocComment();
        $this->assertIsString($doc, $class . '::' . $method . ' lost its docblock');
        $this->assertStringContainsString('@deprecated', $doc);
    }

    /** @return list<array{0:string,1:string}> */
    public static function windowedNameProvider(): array
    {
        return [
            [TimeSeries::class, 'getTimeRange'],
            [LineChart::class, 'getAnimationProgress'],
            [LineChart::class, 'getAnimationDuration'],
            [Heatmap::class, 'getCellStyle'],
            [Heatmap::class, 'getMonoRamp'],
            [Heatmap::class, 'getAutoValueRange'],
            [\SugarCraft\Charts\Chart\Chart::class, 'withLegendStyle'],
            [\SugarCraft\Charts\Chart\Chart::class, 'legendStyle'],
            [LineChart::class, 'withLegendStyle'],
            [\SugarCraft\Charts\BarChart\BarChart::class, 'withLegendStyle'],
            [\SugarCraft\Charts\BarChart\BarChart::class, 'legendStyle'],
            [\SugarCraft\Charts\Scatter\Scatter::class, 'withLegendStyle'],
            [\SugarCraft\Charts\Scatter\Scatter::class, 'legendStyle'],
            [\SugarCraft\Charts\OHLC\OHLCChart::class, 'withLegendStyle'],
            [\SugarCraft\Charts\OHLC\OHLCChart::class, 'legendStyle'],
            [Graph::class, 'getCirclePoints'],
            [Graph::class, 'getCirclePointsWithLimit'],
            [Graph::class, 'getFullCirclePoints'],
            [Graph::class, 'getFullCirclePointsWithLimit'],
            [Graph::class, 'getLinePointsWithLimit'],
            [\SugarCraft\Charts\Canvas\Canvas::class, 'getCell'],
            [\SugarCraft\Charts\Canvas\Canvas::class, 'getCellStyle'],
        ];
    }
}
