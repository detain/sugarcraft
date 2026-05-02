<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\OHLC;

use CandyCore\Charts\OHLC\Bar;
use CandyCore\Charts\OHLC\OHLCChart;
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
}
