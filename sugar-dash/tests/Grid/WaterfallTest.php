<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Waterfall;
use SugarCraft\Dash\Grid\WaterfallItem;
use SugarCraft\Dash\Grid\WaterfallBarType;

final class WaterfallTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $waterfall = Waterfall::new();
        $this->assertInstanceOf(Waterfall::class, $waterfall);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $waterfall = Waterfall::new();
        $result = $waterfall->setSize(70, 15);
        $this->assertInstanceOf(\SugarCraft\Dash\Foundation\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $waterfall = Waterfall::new()->setSize(70, 15);
        $rendered = $waterfall->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $waterfall = Waterfall::new()->setSize(70, 15);
        $rendered = $waterfall->render();
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testWithItem(): void
    {
        $waterfall = Waterfall::new();
        $item = WaterfallItem::positive('Revenue', 1000.0);
        $result = $waterfall->withItem($item);
        $this->assertInstanceOf(Waterfall::class, $result);
    }

    public function testAddItem(): void
    {
        $waterfall = Waterfall::new();
        $result = $waterfall->addItem('Revenue', 1000.0, WaterfallBarType::Positive);
        $this->assertInstanceOf(Waterfall::class, $result);
    }

    public function testWithItems(): void
    {
        $waterfall = Waterfall::new();
        $items = [
            WaterfallItem::positive('Revenue', 1000.0),
            WaterfallItem::negative('Costs', 400.0),
            WaterfallItem::total('Profit', 600.0),
        ];
        $result = $waterfall->withItems($items);
        $this->assertInstanceOf(Waterfall::class, $result);
    }

    public function testWithShowConnectors(): void
    {
        $waterfall = Waterfall::new();
        $result = $waterfall->withShowConnectors(false);
        $this->assertInstanceOf(Waterfall::class, $result);
    }

    public function testWithShowValues(): void
    {
        $waterfall = Waterfall::new();
        $result = $waterfall->withShowValues(false);
        $this->assertInstanceOf(Waterfall::class, $result);
    }

    public function testWithShowGrid(): void
    {
        $waterfall = Waterfall::new();
        $result = $waterfall->withShowGrid(false);
        $this->assertInstanceOf(Waterfall::class, $result);
    }

    public function testWithValueRange(): void
    {
        $waterfall = Waterfall::new();
        $result = $waterfall->withValueRange(0, 1000);
        $this->assertInstanceOf(Waterfall::class, $result);
    }

    public function testWaterfallItemHelpers(): void
    {
        $positive = WaterfallItem::positive('Test', 100.0);
        $this->assertEquals(WaterfallBarType::Positive, $positive->type);
        $this->assertEquals(100.0, $positive->value);

        $negative = WaterfallItem::negative('Test', 50.0);
        $this->assertEquals(WaterfallBarType::Negative, $negative->type);

        $total = WaterfallItem::total('Test', 50.0);
        $this->assertEquals(WaterfallBarType::Total, $total->type);

        $subtotal = WaterfallItem::subtotal('Test', 25.0);
        $this->assertEquals(WaterfallBarType::Subtotal, $subtotal->type);
    }

    public function testGetInnerSize(): void
    {
        $waterfall = Waterfall::new()->setSize(70, 15);
        $size = $waterfall->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(70, $size[0]);
        $this->assertEquals(15, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $waterfall = Waterfall::new()->setSize(10, 5);
        $rendered = $waterfall->render();
        $this->assertSame('', $rendered);
    }

    public function testWithStyle(): void
    {
        $waterfall = Waterfall::new();
        $result = $waterfall->withStyle('bold');
        $this->assertInstanceOf(Waterfall::class, $result);
    }

    public function testWithPositiveColor(): void
    {
        $waterfall = Waterfall::new();
        $result = $waterfall->withPositiveColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertInstanceOf(Waterfall::class, $result);
    }

    public function testWithNegativeColor(): void
    {
        $waterfall = Waterfall::new();
        $result = $waterfall->withNegativeColor(\SugarCraft\Core\Util\Color::hex('#00FF00'));
        $this->assertInstanceOf(Waterfall::class, $result);
    }

    public function testWithTotalColor(): void
    {
        $waterfall = Waterfall::new();
        $result = $waterfall->withTotalColor(\SugarCraft\Core\Util\Color::hex('#0000FF'));
        $this->assertInstanceOf(Waterfall::class, $result);
    }
}
