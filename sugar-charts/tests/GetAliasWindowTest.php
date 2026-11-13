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
