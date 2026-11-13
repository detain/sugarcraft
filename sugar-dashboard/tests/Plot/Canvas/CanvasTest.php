<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plot\Canvas;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Plot\Canvas\Canvas;
use SugarCraft\Dashboard\Plot\Canvas\DrawingOps;

/**
 * Tests for the 2D pixel drawing canvas.
 */
final class CanvasTest extends TestCase
{
    /**
     * D-96 (finding sugar-dashboard-189): DrawingOps::drawLine was a
     * published-by-accident API broken by construction - it ASSIGNED the
     * $setPixel closure to $canvas and only ever returned the last pixel
     * write (or the raw closure itself when every pixel fell out of
     * bounds). Zero callers in src/tests/examples/docs; the honest Bresenham
     * route arrives with the D-89 single walker. This pin keeps a future
     * resurrection from slipping back in unexamined.
     */
    public function testBrokenDrawLineApiStaysDeleted(): void
    {
        $this->assertFalse(
            method_exists(DrawingOps::class, 'drawLine'),
            'DrawingOps::drawLine was deleted for being broken by construction (finding 189); use the D-89 walker route'
        );
    }
    public function testNewFactory(): void
    {
        $c = Canvas::new(40, 20);
        $this->assertInstanceOf(Canvas::class, $c);
        $this->assertSame([40, 20], $c->innerSize());
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
        $this->assertInstanceOf(\SugarCraft\Dashboard\Foundation\Sizer::class, $s);
    }

    public function testWithWidthReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->withWidth(20);
        $this->assertNotSame($c, $c2);
        $this->assertSame([20, 10], $c2->innerSize());
    }

    public function testWithHeightReturnsNewInstance(): void
    {
        $c = Canvas::new(10, 10);
        $c2 = $c->withHeight(30);
        $this->assertNotSame($c, $c2);
        $this->assertSame([10, 30], $c2->innerSize());
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
