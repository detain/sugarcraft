<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plot\Chart\OHLC;
use SugarCraft\Dash\Plot\Chart\OHLCPoint;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;

final class OHLCTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testOHLCImplementsSizer(): void
    {
        $ohlc = OHLC::new();
        $this->assertInstanceOf(Sizer::class, $ohlc);
    }

    // ═══════════════════════════════════════════════════════════════
    // OHLCPoint
    // ═══════════════════════════════════════════════════════════════

    public function testOHLCPointCreation(): void
    {
        $point = new OHLCPoint('AAPL', 150.0, 155.0, 149.0, 153.0);

        $this->assertSame('AAPL', $point->label);
        $this->assertSame(150.0, $point->open);
        $this->assertSame(155.0, $point->high);
        $this->assertSame(149.0, $point->low);
        $this->assertSame(153.0, $point->close);
        $this->assertNull($point->color);
    }

    public function testOHLCPointBullish(): void
    {
        $point = OHLCPoint::bullish('AAPL', 150.0, 155.0, 149.0, 153.0);

        $this->assertTrue($point->isBullish());
        $this->assertNotNull($point->color);
    }

    public function testOHLCPointBearish(): void
    {
        $point = OHLCPoint::bearish('AAPL', 153.0, 155.0, 149.0, 150.0);

        $this->assertFalse($point->isBullish());
        $this->assertNotNull($point->color);
    }

    public function testOHLCPointGetRange(): void
    {
        $point = new OHLCPoint('AAPL', 150.0, 155.0, 149.0, 153.0);

        $this->assertSame(6.0, $point->getRange());
    }

    public function testOHLCPointGetBodySize(): void
    {
        $point = new OHLCPoint('AAPL', 150.0, 155.0, 149.0, 153.0);

        $this->assertSame(3.0, $point->getBodySize());
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesDefaultInstance(): void
    {
        $ohlc = OHLC::new();
        $this->assertInstanceOf(OHLC::class, $ohlc);
    }

    public function testRenderReturnsEmptyWithNoPoints(): void
    {
        $ohlc = OHLC::new();
        $this->assertSame('', $ohlc->render());
    }

    public function testRenderReturnsNonEmptyWithPoints(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0)
            ->addPoint('GOOG', 153.0, 157.0, 152.0, 155.0);

        $rendered = $ohlc->render();
        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0)
            ->setSize(65, 15);

        $rendered = $ohlc->render();
        $this->assertMatchesRegularExpression('/[╭╮╰╯│─]/', $rendered);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0)
            ->setSize(20, 5);

        $this->assertSame('', $ohlc->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Point operations
    // ═══════════════════════════════════════════════════════════════

    public function testWithPointAddsPoint(): void
    {
        $ohlc = OHLC::new()
            ->withPoint(OHLCPoint::bullish('AAPL', 150.0, 155.0, 149.0, 153.0));

        $this->assertNotSame('', $ohlc->render());
    }

    public function testAddPointByParams(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0);

        $this->assertNotSame('', $ohlc->render());
    }

    public function testWithPointsReplacesPoints(): void
    {
        $points = [
            OHLCPoint::bullish('AAPL', 150.0, 155.0, 149.0, 153.0),
            OHLCPoint::bearish('GOOG', 153.0, 155.0, 150.0, 151.0),
        ];
        $ohlc = OHLC::new()->withPoints($points);

        $rendered = $ohlc->render();

        $this->assertStringContainsString('AAPL', $rendered);
        $this->assertStringContainsString('GOOG', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Display options
    // ═══════════════════════════════════════════════════════════════

    public function testWithShowGrid(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0);

        $result = $ohlc->withShowGrid(false);
        $this->assertInstanceOf(OHLC::class, $result);
    }

    public function testWithShowLabels(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0);

        $result = $ohlc->withShowLabels(false);
        $this->assertInstanceOf(OHLC::class, $result);
    }

    public function testWithPriceRange(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0);

        $result = $ohlc->withPriceRange(100.0, 200.0);
        $this->assertInstanceOf(OHLC::class, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border styles
    // ═══════════════════════════════════════════════════════════════

    public function testWithStyle(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0);

        $result = $ohlc->withStyle('bold');
        $this->assertInstanceOf(OHLC::class, $result);
    }

    public function testBorderStyles(): void
    {
        $styles = ['rounded', 'single', 'double', 'bold', 'empty'];

        foreach ($styles as $style) {
            $ohlc = OHLC::new()
                ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0)
                ->withStyle($style);

            $rendered = $ohlc->render();
            $this->assertNotSame('', $rendered, "Style '$style' should render");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Sizer interface
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsSizer(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0);

        $result = $ohlc->setSize(65, 15);
        $this->assertInstanceOf(Sizer::class, $result);
    }

    public function testSetSizeAffectsRendered(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0)
            ->setSize(65, 15);

        $rendered = $ohlc->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSize(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0)
            ->setSize(65, 15);

        [$w, $h] = $ohlc->getInnerSize();
        $this->assertSame(65, $w);
        $this->assertSame(15, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithBullishColor(): void
    {
        $ohlc = OHLC::new();
        $result = $ohlc->withBullishColor(Color::hex('#00FF00'));
        $this->assertInstanceOf(OHLC::class, $result);
    }

    public function testWithBearishColor(): void
    {
        $ohlc = OHLC::new();
        $result = $ohlc->withBearishColor(Color::hex('#FF0000'));
        $this->assertInstanceOf(OHLC::class, $result);
    }

    public function testWithGridColor(): void
    {
        $ohlc = OHLC::new();
        $result = $ohlc->withGridColor(Color::hex('#0000FF'));
        $this->assertInstanceOf(OHLC::class, $result);
    }

    public function testWithTextColor(): void
    {
        $ohlc = OHLC::new();
        $result = $ohlc->withTextColor(Color::hex('#FFFFFF'));
        $this->assertInstanceOf(OHLC::class, $result);
    }

    public function testWithBackgroundColor(): void
    {
        $ohlc = OHLC::new();
        $result = $ohlc->withBackgroundColor(Color::hex('#000000'));
        $this->assertInstanceOf(OHLC::class, $result);
    }

    public function testWithersReturnNewInstance(): void
    {
        $original = OHLC::new()->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0);
        $updated = $original->withBullishColor(Color::hex('#00FF00'));

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testMinimumWidthRendersEmpty(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0)
            ->setSize(25, 15);

        $this->assertSame('', $ohlc->render());
    }

    public function testMinimumHeightRendersEmpty(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0)
            ->setSize(65, 6);

        $this->assertSame('', $ohlc->render());
    }

    public function testSinglePoint(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0);

        $rendered = $ohlc->render();
        $this->assertNotSame('', $rendered);
    }

    public function testMultiplePoints(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0)
            ->addPoint('GOOG', 153.0, 157.0, 152.0, 155.0)
            ->addPoint('MSFT', 155.0, 160.0, 154.0, 158.0)
            ->addPoint('AMZN', 158.0, 162.0, 157.0, 159.0);

        $rendered = $ohlc->render();
        $this->assertNotSame('', $rendered);
    }

    public function testColorAddsAnsiCodes(): void
    {
        $ohlc = OHLC::new()
            ->addPoint('AAPL', 150.0, 155.0, 149.0, 153.0)
            ->withBullishColor(Color::ansi(10));

        $rendered = $ohlc->render();
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testOhlcBorderCharacterArmsThroughChartBorderStyle(): void
    {
$points = [
            new OHLCPoint('Mon', 100.0, 110.0, 95.0, 108.0),
            new OHLCPoint('Tue', 108.0, 112.0, 101.0, 103.0),
        ];
        $render = fn(string $style): string => (new OHLC())->withPoints($points)->withStyle($style)->render();

        $double = $render('double');
        $this->assertStringContainsString('╔', $double);
        $this->assertStringNotContainsString('╭', $double);
                $this->assertStringNotContainsString('┌', $double);

        $this->assertStringNotContainsString('┏', $double);

        $bold = $render('bold');
        $this->assertStringContainsString('┏', $bold);
        $this->assertStringNotContainsString('╔', $bold);
        $this->assertStringNotContainsString('╭', $bold);

        $single = $render('single');
                $this->assertStringContainsString('┌', $single);

        $this->assertStringNotContainsString('╭', $single);
        $this->assertStringNotContainsString('╔', $single);
        $this->assertStringNotContainsString('┏', $single);

        $rounded = $render('rounded');
        $this->assertStringContainsString('╭', $rounded);
        $this->assertStringNotContainsString('╔', $rounded);

        $empty = $render('empty');
        foreach (['╔', '╭', '┌',  '┏'] as $corner) {
            $this->assertStringNotContainsString($corner, $empty, "empty style must not draw $corner");
        }

        $this->assertSame($rounded, $render('bogus'), 'unknown style falls through to rounded');
    }
    public function testOhlcPriceToYArmsThroughPriceAxisProjection(): void
    {
        $chart = (new OHLC())->withPriceRange(100.0, 200.0);
        $project = new \ReflectionMethod(OHLC::class, 'priceToY');

        $this->assertSame(0, $project->invoke($chart, 100.0, 11));
        $this->assertSame(10, $project->invoke($chart, 200.0, 11));
        $this->assertSame(5, $project->invoke($chart, 150.0, 11));
        $this->assertSame(3, $project->invoke($chart, 130.0, 11));

        $flat = (new OHLC())->withPriceRange(50.0, 50.0);
        $this->assertSame(5, $project->invoke($flat, 50.0, 11), 'zero range centers on intval(height/2)');
        $this->assertSame(5, $project->invoke($flat, 999.0, 11));
    }
}
