<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\TimeSeries;
use SugarCraft\Charts\LineChart\Waveline;
use SugarCraft\Charts\OHLC\Candle;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Charts\Support\Range;

/**
 * CH-07 (sugar-charts-056): a lone pinned axis end that leaves the
 * effective domain empty — pinned min above every sample — must refuse
 * loudly at render, never silently clamp to all-zero bars, flat candles,
 * or a misleading steady mid-glyph.
 */
final class RangePinTest extends TestCase
{
    public function testResolveFullAutoUsesScanExtents(): void
    {
        $this->assertSame([1.0, 5.0], Range::resolve(null, null, 1.0, 5.0, 'Y'));
    }

    public function testResolveHonorsValidLoneEnds(): void
    {
        $this->assertSame([0.5, 5.0], Range::resolve(0.5, null, 1.0, 5.0, 'Y'));
        $this->assertSame([1.0, 9.0], Range::resolve(null, 9.0, 1.0, 5.0, 'Y'));
    }

    public function testResolveRefusesLoneMinAboveData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('min (10) must not exceed max (5)');
        Range::resolve(10.0, null, 1.0, 5.0, 'Y');
    }

    public function testResolveRefusesLoneMaxBelowData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Range::resolve(null, 0.0, 1.0, 5.0, 'X');
    }

    public function testResolveAllowsDegenerateEqualPair(): void
    {
        // equal min/max is NOT inverted — renderers bump the window width
        // themselves (all-equal data honesty belongs to the render shape,
        // not to range validation).
        $this->assertSame([4.0, 4.0], Range::resolve(4.0, 4.0, 1.0, 9.0, 'Y'));
    }

    /** @return array<string, array{0: callable(): string, 1: string}> */
    public static function loneEndAboveDataProvider(): array
    {
        return [
            'LineChart min above data' => [
                static fn(): string => LineChart::new([1.0, 2.0, 3.0], 20, 5)->withMin(10.0)->view(),
                'Y',
            ],
            'BarChart min above data' => [
                static fn(): string => BarChart::new([['a', 1.0], ['b', 2.0]], 20, 5)->withMin(10.0)->view(),
                'value',
            ],
            'Sparkline min above data' => [
                static fn(): string => Sparkline::new([1.0, 2.0, 3.0])->withMin(10.0)->view(),
                'value',
            ],
            'OHLC min above candles' => [
                static fn(): string => OHLCChart::new(
                    [new Candle(open: 100.0, high: 110.0, low: 95.0, close: 108.0)],
                    20,
                    5,
                )->withMin(500.0)->view(),
                'Y',
            ],
            // CH-09 (sugar-charts-018): Scatter used to fold lone pins
            // toward the data (unobservable); now the pin is honored and
            // a lone end that excludes the data refuses the domain at
            // render, same family as the rest.
            'Scatter minX above points' => [
                static fn(): string => Scatter::new([[1.0, 2.0], [3.0, 4.0]], 20, 5)->withXRange(10.0, null)->view(),
                'X',
            ],
            'Scatter minY above points' => [
                static fn(): string => Scatter::new([[1.0, 2.0], [3.0, 4.0]], 20, 5)->withYRange(10.0, null)->view(),
                'Y',
            ],
            'Heatmap min above grid' => [
                static fn(): string => Heatmap::new([[1.0, 2.0]], 4, 4)->withAutoValueRange(false)->withMin(10.0)->view(),
                'value',
            ],
            'Waveline xMin above points' => [
                static fn(): string => Waveline::new([[0.0, 1.0], [1.0, 2.0]], 20, 5)
                    ->withXYRange(10.0, null, null, null)
                    ->view(),
                'X',
            ],
            'TimeSeries min above values' => [
                static fn(): string => TimeSeries::new([
                    [new \DateTimeImmutable('2026-01-01 00:00:00'), 1.0],
                    [new \DateTimeImmutable('2026-01-01 01:00:00'), 2.0],
                ], 20, 5)->withMin(10.0)->view(),
                'Y',
            ],
        ];
    }

    /**
     * Acceptance table: every renderer family refuses an empty pinned
     * domain at render time. Before CH-07 these calls returned bytes —
     * all-zero bars, flat candles, a mid-row of ● — with no signal.
     *
     * @param callable(): string $render
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('loneEndAboveDataProvider')]
    public function testLonePinnedEndAboveDataThrowsPerFamily(callable $render, string $axis): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $render();
    }

    public function testValidLoneEndsStillRender(): void
    {
        // widening lone ends keep their old (correct) behavior untouched
        $this->assertNotSame('', BarChart::new([['a', 1.0], ['b', 2.0]], 20, 5)->withMax(10.0)->view());
        $this->assertNotSame('', Sparkline::new([1.0, 2.0, 3.0])->withMin(0.0)->view());
        $this->assertNotSame('', LineChart::new([1.0, 2.0, 3.0], 20, 5)->withMin(-5.0)->withMax(50.0)->view());
    }

    public function testEqualPinnedWindowStillBumpsNotThrows(): void
    {
        // all-equal data inside a pinned equal window used to render via
        // the +1.0 bump; CH-07 must not turn that legitimate degenerate
        // case into an error.
        $out = BarChart::new([["a", 5.0], ["b", 5.0]], 20, 5)->withMin(5.0)->withMax(5.0)->view();
        $this->assertNotSame('', $out);
    }

    public function testScatterHonorsLonePinInsideData(): void
    {
        // CH-09 (sugar-charts-018): a lone pinned min below the data is
        // HONORED — the point shifts right compared to the auto domain.
        $points = [[6.0, 1.0], [8.0, 2.0]];
        $auto   = Scatter::new($points, 20, 5)->withAxes(false)->view();
        $pinned = Scatter::new($points, 20, 5)->withAxes(false)->withXRange(5.0, null)->view();
        $this->assertNotSame($auto, $pinned, 'the honored pin must move the plot');
        $strip = static fn (string $s): string => (string) preg_replace('/\e\[[0-9;]*m/', '', $s);
        $autoFirst   = [];
        $pinFirst    = [];
        foreach (explode("\n", $strip($auto)) as $r) {
            $at = strpos($r, '*');
            if ($at !== false) { $autoFirst[] = $at; }
        }
        foreach (explode("\n", $strip($pinned)) as $r) {
            $at = strpos($r, '*');
            if ($at !== false) { $pinFirst[] = $at; }
        }
        $this->assertNotEmpty($autoFirst);
        $this->assertSame(min($autoFirst), 0, 'auto domain hugs the left edge');
        $this->assertGreaterThan(min($autoFirst), min($pinFirst), 'pinned domain pushes the first point inward');
    }

    public function testScatterCtorDoorRefusesFullInversion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Scatter::new([[1.0, 1.0]], 20, 5)->withXRange(10.0, 2.0);
    }
}
