<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Canvas;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Plot\Canvas\Canvas;

/**
 * Tests for the 2D pixel drawing canvas.
 */
final class CanvasTest extends TestCase
{
    public function testNewFactory(): void
    {
        $c = Canvas::new(40, 20);
        $this->assertInstanceOf(Canvas::class, $c);
        $this->assertSame([40, 20], $c->getInnerSize());
    }

    public function testSetPixelReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->setPixel(5, 5, 'X');
        $this->assertNotSame($c, $c2);
    }

    public function testSetPixelOutOfBoundsIgnored(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->setPixel(100, 100, 'X'); // out of bounds
        $this->assertSame($c, $c2); // should return same instance when OOB
    }

    public function testSetPixelNegativeCoordsIgnored(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->setPixel(-1, 5, 'X');
        $this->assertSame($c, $c2);
    }

    public function testGetPixelOutOfBoundsReturnsNull(): void
    {
        $c = Canvas::new(10, 10);
        $this->assertNull($c->getPixel(100, 100));
        $this->assertNull($c->getPixel(-1, 5));
    }

    public function testGetPixelReturnsNullWhenNotSet(): void
    {
        $c = Canvas::new(10, 10);
        $this->assertNull($c->getPixel(5, 5));
    }

    public function testSetAndGetPixel(): void
    {
        $c = Canvas::new(10, 10)->setPixel(5, 5, 'X');
        $this->assertSame('X', $c->getPixel(5, 5));
    }

    public function testSetPixelWithColor(): void
    {
        $c = Canvas::new(10, 10)
            ->setPixel(3, 4, '●', Color::hex('#FF0000'), Color::hex('#0000FF'));
        $this->assertSame('●', $c->getPixel(3, 4));
    }

    public function testDrawLineReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10);
        // drawLine signature: (x1, y, x2, char='█', fg=null, bg=null)
        // Draw a horizontal line from x=0 to x=9 at y=5
        $c2 = $c->drawLine(0, 5, 9, '█', null, null);
        $this->assertNotSame($c, $c2);
    }

    public function testDrawVLineReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->drawVLine(5, 0, 9);
        $this->assertNotSame($c, $c2);
    }

    public function testDrawRectReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->drawRect(2, 2, 5, 4);
        $this->assertNotSame($c, $c2);
    }

    public function testFillRectReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->fillRect(2, 2, 5, 4);
        $this->assertNotSame($c, $c2);
    }

    public function testDrawCircleReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->drawCircle(5, 5, 3);
        $this->assertNotSame($c, $c2);
    }

    public function testFillCircleReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->fillCircle(5, 5, 3);
        $this->assertNotSame($c, $c2);
    }

    public function testDrawTextReturnsNewInstance(): void
    {
        $c = Canvas::new(20, 5);
        $c2 = $c->drawText(0, 0, 'hello');
        $this->assertNotSame($c, $c2);
    }

    public function testClearReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10)->setPixel(5, 5, 'X');
        $c2 = $c->clear();
        $this->assertNotSame($c, $c2);
    }

    public function testClearRemovesPixels(): void
    {
        $c = Canvas::new(10, 10)->setPixel(5, 5, 'X')->clear();
        $this->assertNull($c->getPixel(5, 5));
    }

    public function testRenderReturnsString(): void
    {
        $c = Canvas::new(5, 3);
        $rendered = $c->render();
        $this->assertIsString($rendered);
        // Should have 3 lines separated by newlines
        $lines = explode("\n", $rendered);
        $this->assertCount(3, $lines);
    }

    public function testRenderContainsSpacesForEmptyCanvas(): void
    {
        $c = Canvas::new(5, 3);
        $rendered = $c->render();
        // Empty canvas renders as spaces
        $this->assertStringContainsString('     ', $rendered); // 5 spaces
    }

    public function testRenderShowsSetPixels(): void
    {
        $c = Canvas::new(10, 3)->setPixel(2, 1, 'X');
        $rendered = $c->render();
        // The character X should appear in the rendered output
        $this->assertStringContainsString('X', $rendered);
    }

    public function testSetSizeReturnsSizer(): void
    {
        $c = Canvas::new(10, 10);
        $s = $c->setSize(80, 24);
        $this->assertInstanceOf(\SugarCraft\Dash\Foundation\Sizer::class, $s);
    }

    public function testWithWidthReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->withWidth(20);
        $this->assertNotSame($c, $c2);
        $this->assertSame([20, 10], $c2->getInnerSize());
    }

    public function testWithHeightReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->withHeight(30);
        $this->assertNotSame($c, $c2);
        $this->assertSame([10, 30], $c2->getInnerSize());
    }

    public function testWithDefaultFgReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->withDefaultFg(Color::hex('#FF0000'));
        $this->assertNotSame($c, $c2);
    }

    public function testWithDefaultBgReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->withDefaultBg(Color::hex('#0000FF'));
        $this->assertNotSame($c, $c2);
    }
}
