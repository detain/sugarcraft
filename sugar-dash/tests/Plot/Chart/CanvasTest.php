<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use SugarCraft\Dash\Plot\Canvas\Canvas;
use SugarCraft\Dash\Plot\Canvas\CanvasPoint;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class CanvasTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testCanvasImplementsSizer(): void
    {
        $canvas = Canvas::new();
        $this->assertInstanceOf(Sizer::class, $canvas);
    }

    public function testCanvasImplementsItem(): void
    {
        $canvas = Canvas::new();
        $this->assertInstanceOf(Item::class, $canvas);
    }

    // ═══════════════════════════════════════════════════════════════
    // Creation
    // ═══════════════════════════════════════════════════════════════

    public function testCanvasNewFactory(): void
    {
        $canvas = Canvas::new(10, 10);

        $this->assertSame(10, $canvas->getInnerSize()[0]);
        $this->assertSame(10, $canvas->getInnerSize()[1]);
    }

    public function testCanvasConstructorDefaults(): void
    {
        $canvas = new Canvas();

        $this->assertSame(40, $canvas->getInnerSize()[0]);
        $this->assertSame(20, $canvas->getInnerSize()[1]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsSizer(): void
    {
        $canvas = Canvas::new();
        $result = $canvas->setSize(60, 30);

        $this->assertInstanceOf(Sizer::class, $result);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $canvas = Canvas::new(10, 10);
        $resized = $canvas->setSize(60, 30);

        $this->assertNotSame($canvas, $resized);
    }

    public function testWithWidthReturnsNewWidth(): void
    {
        $canvas = Canvas::new(10, 10);
        $wider = $canvas->withWidth(20);

        $this->assertSame(20, $wider->getInnerSize()[0]);
        $this->assertSame(10, $wider->getInnerSize()[1]);
    }

    public function testWithHeightReturnsNewHeight(): void
    {
        $canvas = Canvas::new(10, 10);
        $taller = $canvas->withHeight(20);

        $this->assertSame(10, $taller->getInnerSize()[0]);
        $this->assertSame(20, $taller->getInnerSize()[1]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Pixel operations
    // ═══════════════════════════════════════════════════════════════

    public function testSetPixelReturnsNewInstance(): void
    {
        $canvas = Canvas::new(10, 10);
        $changed = $canvas->setPixel(5, 5, 'X');

        $this->assertNotSame($canvas, $changed);
    }

    public function testSetPixelInBounds(): void
    {
        $canvas = Canvas::new(10, 10)
            ->setPixel(5, 5, 'X');

        $this->assertSame('X', $canvas->getPixel(5, 5));
    }

    public function testSetPixelOutOfBoundsNoOp(): void
    {
        $canvas = Canvas::new(10, 10);
        $changed = $canvas->setPixel(15, 15, 'X');

        // Should return same instance (no-op)
        $this->assertNull($changed->getPixel(15, 15));
    }

    public function testGetPixelOutOfBoundsReturnsNull(): void
    {
        $canvas = Canvas::new(10, 10);

        $this->assertNull($canvas->getPixel(-1, 0));
        $this->assertNull($canvas->getPixel(0, -1));
        $this->assertNull($canvas->getPixel(10, 5));
        $this->assertNull($canvas->getPixel(5, 10));
    }

    public function testSetPixelWithColor(): void
    {
        $canvas = Canvas::new(10, 10)
            ->setPixel(5, 5, 'X', Color::hex('#FF0000'), Color::hex('#0000FF'));

        $this->assertSame('X', $canvas->getPixel(5, 5));
    }

    // ═══════════════════════════════════════════════════════════════
    // Line drawing
    // ═══════════════════════════════════════════════════════════════

    public function testDrawLineHorizontal(): void
    {
        $canvas = Canvas::new(10, 10)
            ->drawLine(2, 5, 7, '█');

        // All pixels along the line should be set
        for ($x = 2; $x <= 7; $x++) {
            $this->assertSame('█', $canvas->getPixel($x, 5));
        }
    }

    public function testDrawLineReversedDirection(): void
    {
        $canvas = Canvas::new(10, 10)
            ->drawLine(7, 5, 2, '█');

        for ($x = 2; $x <= 7; $x++) {
            $this->assertSame('█', $canvas->getPixel($x, 5));
        }
    }

    public function testDrawVLineVertical(): void
    {
        $canvas = Canvas::new(10, 10)
            ->drawVLine(5, 2, 7, '█');

        for ($y = 2; $y <= 7; $y++) {
            $this->assertSame('█', $canvas->getPixel(5, $y));
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Rectangle drawing
    // ═══════════════════════════════════════════════════════════════

    public function testDrawRect(): void
    {
        $canvas = Canvas::new(20, 10)
            ->drawRect(5, 3, 8, 4, '█');

        // Top and bottom edges
        for ($x = 5; $x < 13; $x++) {
            $this->assertSame('█', $canvas->getPixel($x, 3));
            $this->assertSame('█', $canvas->getPixel($x, 6));
        }

        // Left and right edges
        for ($y = 3; $y <= 6; $y++) {
            $this->assertSame('█', $canvas->getPixel(5, $y));
            $this->assertSame('█', $canvas->getPixel(12, $y));
        }

        // Center should be empty
        $this->assertNull($canvas->getPixel(8, 4));
    }

    public function testFillRect(): void
    {
        $canvas = Canvas::new(20, 10)
            ->fillRect(5, 3, 4, 3, '█');

        // Fill area should be filled
        for ($x = 5; $x < 9; $x++) {
            for ($y = 3; $y < 6; $y++) {
                $this->assertSame('█', $canvas->getPixel($x, $y));
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Circle drawing
    // ═══════════════════════════════════════════════════════════════

    public function testDrawCircle(): void
    {
        $canvas = Canvas::new(20, 20)
            ->drawCircle(10, 10, 5, '█');

        // Outline-only circle: cardinal points are set, center is not
        $this->assertSame('█', $canvas->getPixel(10, 5));
        $this->assertSame('█', $canvas->getPixel(10, 15));
        $this->assertSame('█', $canvas->getPixel(5, 10));
        $this->assertSame('█', $canvas->getPixel(15, 10));
    }

    public function testDrawCircleZeroRadiusNoOp(): void
    {
        $canvas = Canvas::new(10, 10);
        $result = $canvas->drawCircle(5, 5, 0, '█');

        $this->assertSame($canvas, $result);
    }

    public function testFillCircle(): void
    {
        $canvas = Canvas::new(20, 20)
            ->fillCircle(10, 10, 3, '█');

        // Center should be filled
        $this->assertSame('█', $canvas->getPixel(10, 10));
        $this->assertSame('█', $canvas->getPixel(9, 10));
        $this->assertSame('█', $canvas->getPixel(11, 10));
        $this->assertSame('█', $canvas->getPixel(10, 9));
        $this->assertSame('█', $canvas->getPixel(10, 11));
    }

    // ═══════════════════════════════════════════════════════════════
    // Text drawing
    // ═══════════════════════════════════════════════════════════════

    public function testDrawText(): void
    {
        $canvas = Canvas::new(20, 5)
            ->drawText(5, 2, 'Hello');

        $this->assertSame('H', $canvas->getPixel(5, 2));
        $this->assertSame('e', $canvas->getPixel(6, 2));
        $this->assertSame('l', $canvas->getPixel(7, 2));
        $this->assertSame('l', $canvas->getPixel(8, 2));
        $this->assertSame('o', $canvas->getPixel(9, 2));
    }

    // ═══════════════════════════════════════════════════════════════
    // Clear operation
    // ═══════════════════════════════════════════════════════════════

    public function testClearReturnsNewInstance(): void
    {
        $canvas = Canvas::new(10, 10)
            ->setPixel(5, 5, 'X');
        $cleared = $canvas->clear();

        $this->assertNotSame($canvas, $cleared);
    }

    public function testClearRemovesPixels(): void
    {
        $canvas = Canvas::new(10, 10)
            ->setPixel(5, 5, 'X')
            ->clear();

        $this->assertNull($canvas->getPixel(5, 5));
    }

    // ═══════════════════════════════════════════════════════════════
    // Rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyCanvas(): void
    {
        $canvas = Canvas::new(10, 3);
        $rendered = $canvas->render();

        // Should be 3 lines of 10 spaces each
        $lines = explode("\n", $rendered);
        $this->assertCount(3, $lines);
        $this->assertSame(10, strlen($lines[0]));
    }

    public function testRenderWithPixel(): void
    {
        $canvas = Canvas::new(10, 3)
            ->setPixel(5, 1, '█');
        $rendered = $canvas->render();

        $lines = explode("\n", $rendered);
        $this->assertStringContainsString('     █', $lines[1]);
    }

    public function testRenderWithColor(): void
    {
        $canvas = Canvas::new(10, 3)
            ->setPixel(5, 1, '█', Color::hex('#FF0000'));
        $rendered = $canvas->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithDefaultFgReturnsNewInstance(): void
    {
        $canvas = Canvas::new(10, 10);
        $changed = $canvas->withDefaultFg(Color::hex('#FF0000'));

        $this->assertNotSame($canvas, $changed);
    }

    public function testWithDefaultBgReturnsNewInstance(): void
    {
        $canvas = Canvas::new(10, 10);
        $changed = $canvas->withDefaultBg(Color::hex('#0000FF'));

        $this->assertNotSame($canvas, $changed);
    }

    // ═══════════════════════════════════════════════════════════════
    // CanvasPoint value object
    // ═══════════════════════════════════════════════════════════════

    public function testCanvasPointCreation(): void
    {
        $point = new CanvasPoint(5, 10);

        $this->assertSame(5, $point->x);
        $this->assertSame(10, $point->y);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testDrawRectZeroWidthHeight(): void
    {
        $canvas = Canvas::new(10, 10)
            ->drawRect(5, 5, 0, 0, '█');

        // Should not crash and return new instance
        $this->assertInstanceOf(Canvas::class, $canvas);
    }

    public function testFillRectNegativeCoordinatesNoOp(): void
    {
        $canvas = Canvas::new(10, 10)
            ->fillRect(-5, -5, 10, 10, '█');

        // Should handle gracefully without crashing
        $this->assertInstanceOf(Canvas::class, $canvas);
    }
}