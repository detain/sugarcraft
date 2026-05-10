<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\OHLC;

use SugarCraft\Charts\OHLC\Bar;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Chart\Position;
use PHPUnit\Framework\TestCase;

final class OHLCChartTest extends TestCase
{
    public function testEmptyRendersBlank(): void
    {
        $out = OHLCChart::new([], 6, 3)->view();
        $this->assertSame(3, substr_count($out, "\n") + 1);
    }

    public function testSingleBullishBarRendersBodyAndWick(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 1, 16)->view();
        // Bullish body glyph appears (within wick span).
        $this->assertStringContainsString('█', $out);
        $this->assertStringContainsString('│', $out);
    }

    public function testBearishUsesDifferentBodyGlyph(): void
    {
        $bull = new Bar(open: 100, high: 110, low: 95, close: 108);
        $bear = new Bar(open: 108, high: 112, low: 100, close: 102);
        $out = OHLCChart::new([$bull, $bear], 5, 16)->view();
        // Both ▒ (bearish) and █ (bullish) appear.
        $this->assertStringContainsString('▒', $out);
        $this->assertStringContainsString('█', $out);
    }

    public function testIsBullishAndBearishHelpers(): void
    {
        $this->assertTrue((new Bar(1, 5, 0, 3))->isBullish());
        $this->assertTrue((new Bar(3, 5, 0, 1))->isBearish());
    }

    public function testCustomBodyRunes(): void
    {
        $bar = new Bar(1, 5, 0, 3);
        $out = OHLCChart::new([$bar], 1, 12)
            ->withBodyRunes('+', '-')
            ->view();
        $this->assertStringContainsString('+', $out);
    }

    public function testPushAppendsBar(): void
    {
        $c = OHLCChart::new([])->push(new Bar(1, 5, 0, 3));
        $this->assertCount(1, $c->bars);
    }

    // ─── Axis Label Tests ────────────────────────────────────────────────

    public function testWithXLabelAddsLabelAtBottom(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 6, 3)
            ->withXLabel('Trading Day')
            ->view();
        $this->assertStringEndsWith("\nTrading Day", $out);
    }

    public function testWithYLabelPrependsToEachLine(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 6, 3)
            ->withYLabel('Price $')
            ->view();
        $lines = explode("\n", $out);
        foreach ($lines as $line) {
            if ($line !== '') {
                $this->assertStringStartsWith('Price $ ', $line);
            }
        }
    }

    // ─── Legend Tests ────────────────────────────────────────────────────

    public function testWithLegendShowsLegendWhenEnabled(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 6, 3)
            ->withLegend(true)
            ->withLegendItems([['label' => 'AAPL', 'color' => 'green']]);
        $this->assertTrue($chart->showLegend);
    }

    public function testWithLegendFalseDisablesLegend(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 6, 3)
            ->withLegend(true)
            ->withLegend(false);
        $this->assertFalse($chart->showLegend);
    }

    public function testWithLegendPositionChangesPosition(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 6, 3)
            ->withLegend(true)
            ->withLegendPosition(Position::Top);
        $this->assertSame(Position::Top, $chart->legendPosition);
    }

    public function testWithLegendStyleCustomizesIndicator(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 6, 3)
            ->withLegend(true)
            ->withLegendStyle('◆');
        $this->assertSame('◆', $chart->legendIndicatorChar);
    }

    // ─── Title Tests ─────────────────────────────────────────────────────

    public function testWithTitleSetsTitle(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 6, 3)
            ->withTitle('AAPL Stock');
        $this->assertSame('AAPL Stock', $chart->title);
    }

    // ─── Fluent Interface Tests ──────────────────────────────────────────

    public function testFluentInterfaceChaining(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 20, 6)
            ->withXLabel('Trading Day')
            ->withYLabel('Price $')
            ->withLegend(true)
            ->withLegendPosition(Position::Right)
            ->withLegendStyle('●')
            ->withTitle('AAPL Daily');

        $this->assertSame('Trading Day', $chart->xLabel);
        $this->assertSame('Price $', $chart->yLabel);
        $this->assertTrue($chart->showLegend);
        $this->assertSame(Position::Right, $chart->legendPosition);
        $this->assertSame('●', $chart->legendIndicatorChar);
        $this->assertSame('AAPL Daily', $chart->title);
    }
}
