<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\OHLC\Bar;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Core\Util\Width;

/**
 * CH-09 (sugar-charts-033): Scatter and OHLCChart carry lightweight
 * quantitative context BY DEFAULT — a y-gutter naming the value-range
 * endpoints, opt-out withAxes(false). LineChart keeps its deliberate
 * bare-glyph frame (the pin below guards that split).
 */
final class AxesDefaultTest extends TestCase
{
    public function testScatterDefaultsToEndpointGutter(): void
    {
        $out = Scatter::new([[1.0, 2.0], [3.0, 7.0]], 20, 5)->view();
        $this->assertStringContainsString('│', $out);
        $this->assertStringContainsString('7', $out);
        $this->assertStringContainsString('2', $out);
    }

    public function testScatterAxesOptOutIsBare(): void
    {
        $out = Scatter::new([[1.0, 2.0], [3.0, 7.0]], 20, 5)->withAxes(false)->view();
        $this->assertStringNotContainsString('│', $out);
    }

    public function testOhlcDefaultsToPriceGutter(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar, $bar], 24, 8)->view();
        $this->assertStringContainsString('│', $out);
        $this->assertStringContainsString('110', $out);
        $this->assertStringContainsString('95', $out);
    }

    public function testOhlcAxesOptOutDropsThePriceGutter(): void
    {
        // Candles carry '│' wicks by design — the opt-out proof is the
        // disappearance of the endpoint digits, not of every frame glyph.
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar, $bar], 24, 8)->withAxes(false)->view();
        $this->assertStringNotContainsString('110', $out);
        $this->assertStringNotContainsString('95', $out);
    }

    public function testDefaultGutterKeepsDeclaredWidthContract(): void
    {
        foreach ([
            Scatter::new([[1.0, 2.0], [3.0, 7.0]], 20, 5)->view(),
            OHLCChart::new([new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0)], 20, 6)->view(),
        ] as $out) {
            foreach (explode("\n", $out) as $row) {
                $this->assertLessThanOrEqual(20, Width::string($row), 'gutter may not overrun width');
            }
        }
    }

    public function testLineChartFrameStaysUnaffected(): void
    {
        $out = \SugarCraft\Charts\LineChart\LineChart::new([1.0, 2.0], 20, 5)->withAxes()->view();
        // LineChart's own frame glyph is '└' (bottom axis) — the CH-09
        // default-gutter work must not have touched its geometry.
        $this->assertStringContainsString('└', $out);
        foreach (explode("\n", $out) as $row) {
            $this->assertSame(0, preg_match('/^\s*\d+(\.\d+)?│/', $row), 'LineChart must not gain a digits gutter');
        }
    }
}
