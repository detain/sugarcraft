<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plot\Canvas;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\ColorMode;
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

    // ═════════════════════════════════════════════════════════════
    // Allocation, defaults and honest degradation (finding 079, D-84)
    // ═════════════════════════════════════════════════════════════

    public function testRenderClipsToAllocation(): void
    {
        $lines = explode("\n", Canvas::new(6, 3)->setSize(4, 2)->render());
        $this->assertCount(2, $lines, 'the allocation clips rows');
        foreach ($lines as $line) {
            $this->assertSame(4, Width::string($line), 'the allocation clips columns');
        }
    }

    public function testRenderPadsBeyondTheGrid(): void
    {
        $lines = explode("\n", Canvas::new(3, 2)->setSize(5, 4)->render());
        $this->assertCount(4, $lines, 'the allocation pads rows');
        foreach ($lines as $line) {
            $this->assertSame(5, Width::string($line), 'the allocation pads columns');
        }
    }

    public function testInnerSizeReportsTheAllocation(): void
    {
        $this->assertSame([10, 5], Canvas::new(4, 3)->setSize(10, 5)->innerSize());
        $this->assertSame([4, 3], Canvas::new(4, 3)->innerSize(), 'unset allocation falls back to the constraints');
    }

    public function testDefaultFgDecoratesGlyphCells(): void
    {
        $sgr = Color::ansi(1)->toFg(ColorMode::profile());
        // setPixel stores colors as hex and the render path re-emits from
        // the hex, so the explicit pin must be built the same way.
        $explicit = Color::hex((string) Color::ansi(2)->toHex())->toFg(ColorMode::profile());
        $canvas = Canvas::new(4, 2)
            ->withDefaultFg(Color::ansi(1))
            ->setPixel(0, 0, 'X')
            ->setPixel(1, 0, 'Y', Color::ansi(2));
        $render = $canvas->render();
        $this->assertStringContainsString($sgr, $render, 'the default foreground paints glyph cells');
        $this->assertStringContainsString($explicit, $render, 'an explicit pixel color wins over the default');
        $first = explode("\n", $render)[0];
        $this->assertStringStartsWith($sgr . 'X', $first, 'X carries the default');
        $this->assertStringContainsString($explicit . 'Y', $first, 'Y carries the explicit color, after the default run');
    }

    public function testDefaultBgDecoratesOnlyGlyphCells(): void
    {
        $sgr = Color::hex('#112233')->toBg(ColorMode::profile());
        $canvas = Canvas::new(3, 2)->withDefaultBg(Color::hex('#112233'))->setPixel(1, 0, 'X');
        $lines = explode("\n", $canvas->render());
        $this->assertStringContainsString($sgr, $lines[0], 'the default background paints the glyph cell');
        $this->assertStringNotContainsString($sgr, $lines[1], 'blank cells stay plain - no wall of background color');
    }

    public function testCorruptColorDegradesWithoutPhantomColumn(): void
    {
        $canvas = Canvas::new(3, 1)->setPixel(0, 0, 'X');

        $bg = new \ReflectionProperty(Canvas::class, 'bgColors');
        $bg->setAccessible(true);
        $bgCanvas = clone $canvas;
        $bg->setValue($bgCanvas, [['bogus', null, null]]);
        $line = explode("\n", $bgCanvas->render())[0];
        $this->assertSame(3, Width::string($line), 'a corrupt background must not add a cell (the old phantom space)');
        $this->assertStringContainsString('X', $line, 'the corrupt cell still paints');

        $fg = new \ReflectionProperty(Canvas::class, 'fgColors');
        $fg->setAccessible(true);
        $fgCanvas = clone $canvas;
        $fg->setValue($fgCanvas, [['bogus', null, null]]);
        $line = explode("\n", $fgCanvas->render())[0];
        $this->assertSame(3, Width::string($line), 'a corrupt foreground keeps the row geometry');
        $this->assertStringContainsString('X', $line, 'the corrupt foreground falls back, not silently eats the glyph');
    }

    public function testWithersCarryTheAllocation(): void
    {
        $canvas = Canvas::new(4, 3)->setSize(9, 6);
        $variants = [
            $canvas->withWidth(5),
            $canvas->withHeight(7),
            $canvas->withDefaultFg(Color::hex('#010203')),
            $canvas->withDefaultBg(Color::hex('#040506')),
        ];
        foreach ($variants as $i => $variant) {
            $this->assertSame([9, 6], $variant->innerSize(), "wither #{$i} must carry the setSize allocation (D-56/D-77 drop class)");
        }
    }
}
