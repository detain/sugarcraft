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
        // Empty chart returns '' per unified empty-output behavior (task 1.4)
        $this->assertSame('', $out);
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

    // ─── Error-Path Tests ─────────────────────────────────────────────────

    public function testNegativeWidthInConstructorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OHLCChart::new([], -1, 10);
    }

    public function testNegativeHeightInConstructorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OHLCChart::new([], 10, -1);
    }

    public function testWithSizeNegativeDimensionsThrows(): void
    {
        $chart = OHLCChart::new([new Bar(1, 5, 0, 3)]);
        $this->expectException(\InvalidArgumentException::class);
        $chart->withSize(-5, 10);
    }

    public function testWithSizeNegativeHeightThrows(): void
    {
        $chart = OHLCChart::new([new Bar(1, 5, 0, 3)]);
        $this->expectException(\InvalidArgumentException::class);
        $chart->withSize(10, -5);
    }

    public function testToStringRendersView(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 6, 3);
        $this->assertSame($chart->view(), (string) $chart);
    }

    // ─── Legend Position Tests ────────────────────────────────────────────

    public function testWithLegendTopPosition(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 6, 3)
            ->withLegend(true)
            ->withLegendPosition(Position::Top)
            ->withLegendItems([['label' => 'AAPL', 'color' => 'green']]);
        $this->assertSame(Position::Top, $chart->legendPosition);
    }

    public function testWithLegendBottomPosition(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 6, 3)
            ->withLegend(true)
            ->withLegendPosition(Position::Bottom)
            ->withLegendItems([['label' => 'AAPL', 'color' => 'green']]);
        $this->assertSame(Position::Bottom, $chart->legendPosition);
    }

    public function testWithLegendLeftPosition(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 6, 3)
            ->withLegend(true)
            ->withLegendPosition(Position::Left)
            ->withLegendItems([['label' => 'AAPL', 'color' => 'green']]);
        $this->assertSame(Position::Left, $chart->legendPosition);
    }

    public function testViewWithLegendTopRendersLegendAboveChart(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 20, 3)
            ->withLegend(true)
            ->withLegendPosition(Position::Top)
            ->withLegendItems([['label' => 'AAPL', 'color' => 'green']])
            ->view();
        // Legend should appear in output (Top position renders above chart)
        $this->assertStringContainsString('AAPL', $out);
    }

    public function testViewWithLegendBottomRendersLegendBelowChart(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 20, 3)
            ->withLegend(true)
            ->withLegendPosition(Position::Bottom)
            ->withLegendItems([['label' => 'AAPL', 'color' => 'green']])
            ->view();
        // Legend should appear in output (Bottom position renders below chart)
        $this->assertStringContainsString('AAPL', $out);
    }

    public function testViewWithLegendLeftRendersLegendAlongsideChart(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 20, 3)
            ->withLegend(true)
            ->withLegendPosition(Position::Left)
            ->withLegendItems([['label' => 'AAPL', 'color' => 'green']])
            ->view();
        $this->assertStringContainsString('AAPL', $out);
    }

    // ─── Title Position Tests ─────────────────────────────────────────────

    public function testWithTitleBottomPosition(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 20, 3)
            ->withTitle('Bottom Title', Position::Bottom)
            ->view();
        // Title at Bottom appears at end
        $this->assertStringEndsWith("Bottom Title", $out);
    }

    public function testWithTitleLeftPositionNotRendered(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 20, 3)
            ->withTitle('Left Title', Position::Left)
            ->view();
        // Title at Left/Right is NOT rendered (returns lines unchanged)
        $this->assertStringNotContainsString('Left Title', $out);
    }

    public function testWithTitleRightPositionNotRendered(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 20, 3)
            ->withTitle('Right Title', Position::Right)
            ->view();
        // Title at Left/Right is NOT rendered (returns lines unchanged)
        $this->assertStringNotContainsString('Right Title', $out);
    }

    public function testWithTitleTopPositionRendered(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 20, 3)
            ->withTitle('Top Title', Position::Top)
            ->view();
        // Title at Top should appear
        $this->assertStringContainsString('Top Title', $out);
    }

    // ─── Min/Max Boundary Tests ──────────────────────────────────────────

    public function testWithMinSetsBoundary(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 6, 3)->withMin(50.0);
        $this->assertNotNull($chart->min);
    }

    public function testWithMaxSetsBoundary(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 6, 3)->withMax(200.0);
        $this->assertNotNull($chart->max);
    }

    // ─── Wick and Color Tests ─────────────────────────────────────────────

    public function testWithWickRuneChangesWick(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 3, 16)->withWickRune('!')->view();
        $this->assertStringContainsString('!', $out);
    }

    public function testWithColorsSetsBothColors(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 3, 16)
            ->withColors(\SugarCraft\Core\Util\Color::ansi(12), \SugarCraft\Core\Util\Color::ansi(9));
        $this->assertNotNull($chart->bullishColor);
        $this->assertNotNull($chart->bearishColor);
    }

    // ─── Legend Items Tests ───────────────────────────────────────────────

    public function testWithLegendItemsAppearsInView(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 20, 3)
            ->withLegend(true)
            ->withLegendItems([
                ['label' => 'Series A', 'color' => 'red'],
                ['label' => 'Series B', 'color' => 'blue'],
            ])
            ->view();
        $this->assertStringContainsString('Series A', $out);
        $this->assertStringContainsString('Series B', $out);
    }

    public function testShortFormAliasesAll(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $chart = OHLCChart::new([$bar], 20, 6)
            ->bars([$bar])
            ->size(20, 6)
            ->min(0.0)
            ->max(200.0)
            ->bodyRunes('+', '-')
            ->wickRune('|')
            ->colors(null, null)
            ->legend(true)
            ->legendPos(Position::Top)
            ->legendStyle('●')
            ->title('Test', Position::Bottom)
            ->xLabel('X')
            ->yLabel('Y')
            ->legendItems([['label' => 'A', 'color' => 'red']]);
        $this->assertCount(1, $chart->bars);
    }

    // ─── Render Edge Cases ─────────────────────────────────────────────────

    public function testViewWithZeroWidthReturnsEmpty(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 0, 10)->view();
        $this->assertSame('', $out);
    }

    public function testViewWithZeroHeightReturnsEmpty(): void
    {
        $bar = new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0);
        $out = OHLCChart::new([$bar], 10, 0)->view();
        $this->assertSame('', $out);
    }

    public function testBarsExceedingWidthAreTruncated(): void
    {
        $bars = [];
        for ($i = 0; $i < 20; $i++) {
            $bars[] = new Bar(open: $i * 10, high: $i * 10 + 5, low: $i * 10 - 5, close: $i * 10 + 3);
        }
        // With width=5, only last 5 bars should be rendered
        $out = OHLCChart::new($bars, 5, 16)->view();
        $this->assertNotEmpty($out);
    }

    public function testEqualMinMaxAdjustsRange(): void
    {
        // When all bars have same high/low values, max == min
        $bar = new Bar(open: 100.0, high: 100.0, low: 100.0, close: 100.0);
        $out = OHLCChart::new([$bar], 3, 16)->view();
        // Should still render without division-by-zero
        $this->assertNotEmpty($out);
    }
}
