<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plot\Chart\CandlestickChart;
use SugarCraft\Dash\Plot\Chart\Candlestick;

final class CandlestickChartTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $chart = CandlestickChart::new();
        $this->assertInstanceOf(CandlestickChart::class, $chart);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $chart = CandlestickChart::new();
        $result = $chart->setSize(65, 15);
        $this->assertInstanceOf(\SugarCraft\Dash\Foundation\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $chart = CandlestickChart::new()->setSize(65, 15);
        $rendered = $chart->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $chart = CandlestickChart::new()->setSize(65, 15);
        $rendered = $chart->render();
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testWithCandle(): void
    {
        $chart = CandlestickChart::new();
        $candle = Candlestick::bullish('AAPL', 150.0, 155.0, 149.0, 153.0);
        $result = $chart->withCandle($candle);
        $this->assertInstanceOf(CandlestickChart::class, $result);
    }

    public function testAddCandle(): void
    {
        $chart = CandlestickChart::new();
        $result = $chart->addCandle('AAPL', 150.0, 155.0, 149.0, 153.0);
        $this->assertInstanceOf(CandlestickChart::class, $result);
    }

    public function testWithCandles(): void
    {
        $chart = CandlestickChart::new();
        $candles = [
            Candlestick::bullish('AAPL', 150.0, 155.0, 149.0, 153.0),
            Candlestick::bearish('AAPL', 153.0, 154.0, 150.0, 151.0),
        ];
        $result = $chart->withCandles($candles);
        $this->assertInstanceOf(CandlestickChart::class, $result);
    }

    public function testWithShowGrid(): void
    {
        $chart = CandlestickChart::new();
        $result = $chart->withShowGrid(false);
        $this->assertInstanceOf(CandlestickChart::class, $result);
    }

    public function testWithShowVolume(): void
    {
        $chart = CandlestickChart::new();
        $result = $chart->withShowVolume(true);
        $this->assertInstanceOf(CandlestickChart::class, $result);
    }

    public function testWithShowLabels(): void
    {
        $chart = CandlestickChart::new();
        $result = $chart->withShowLabels(false);
        $this->assertInstanceOf(CandlestickChart::class, $result);
    }

    public function testWithPriceRange(): void
    {
        $chart = CandlestickChart::new();
        $result = $chart->withPriceRange(100.0, 200.0);
        $this->assertInstanceOf(CandlestickChart::class, $result);
    }

    public function testCandlestickHelpers(): void
    {
        $bullish = Candlestick::bullish('AAPL', 150.0, 155.0, 149.0, 153.0);
        $this->assertTrue($bullish->isBullish());
        $this->assertEquals(150.0, $bullish->open);
        $this->assertEquals(155.0, $bullish->high);
        $this->assertEquals(149.0, $bullish->low);
        $this->assertEquals(153.0, $bullish->close);

        $bearish = Candlestick::bearish('AAPL', 153.0, 154.0, 150.0, 151.0);
        $this->assertFalse($bearish->isBullish());
    }

    public function testGetInnerSize(): void
    {
        $chart = CandlestickChart::new()->setSize(65, 15);
        $size = $chart->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(65, $size[0]);
        $this->assertEquals(15, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $chart = CandlestickChart::new()->setSize(10, 5);
        $rendered = $chart->render();
        $this->assertSame('', $rendered);
    }

    public function testWithStyle(): void
    {
        $chart = CandlestickChart::new();
        $result = $chart->withStyle('bold');
        $this->assertInstanceOf(CandlestickChart::class, $result);
    }

    public function testWithBullishColor(): void
    {
        $chart = CandlestickChart::new();
        $result = $chart->withBullishColor(\SugarCraft\Core\Util\Color::hex('#00FF00'));
        $this->assertInstanceOf(CandlestickChart::class, $result);
    }

    public function testWithBearishColor(): void
    {
        $chart = CandlestickChart::new();
        $result = $chart->withBearishColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertInstanceOf(CandlestickChart::class, $result);
    }

    public function testWithWickColor(): void
    {
        $chart = CandlestickChart::new();
        $result = $chart->withWickColor(\SugarCraft\Core\Util\Color::hex('#0000FF'));
        $this->assertInstanceOf(CandlestickChart::class, $result);
    }

    /**
     * Regression test: withers must return a new instance with updated state,
     * not mutate a clone of a readonly property. Previously the clone-mutate
     * pattern `$clone->color = $color` threw "Cannot modify readonly property"
     * at runtime because constructor-promoted color params are readonly.
     */
    public function testWithersProduceNewInstancesWithUpdatedState(): void
    {
        $original = CandlestickChart::new()->setSize(65, 15);
        $candle = Candlestick::bullish('AAPL', 150.0, 155.0, 149.0, 153.0);
        $original = $original->withCandle($candle);

        $green = \SugarCraft\Core\Util\Color::hex('#00FF00');
        $red = \SugarCraft\Core\Util\Color::hex('#FF0000');

        $modified = $original
            ->withBullishColor($green)
            ->withBearishColor($red)
            ->withGridColor($green)
            ->withTextColor($red)
            ->withWickColor($green)
            ->withBackgroundColor($red)
            ->withShowGrid(false)
            ->withShowVolume(true)
            ->withShowLabels(false)
            ->withPriceRange(100.0, 200.0)
            ->withStyle('bold');

        // Original must be unchanged
        $this->assertNotSame($original, $modified);
        // Modified must have rendered (not throw)
        $rendered = $modified->render();
        $this->assertNotEmpty($rendered);
    }
}