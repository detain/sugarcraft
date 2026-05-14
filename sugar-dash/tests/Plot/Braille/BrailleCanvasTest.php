<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Braille;

use SugarCraft\Dash\Plot\Braille\BrailleCanvas;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class BrailleCanvasTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testImplementsSizer(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $this->assertInstanceOf(Sizer::class, $canvas);
    }

    // ═══════════════════════════════════════════════════════════════
    // Construction
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesCanvas(): void
    {
        $canvas = BrailleCanvas::new(40, 12);
        $this->assertNotNull($canvas);
    }

    public function testConstructorSetsPixelDimensions(): void
    {
        $canvas = BrailleCanvas::new(80, 24);
        [$w, $h] = $canvas->getPixelSize();

        $this->assertSame(80, $w);
        $this->assertSame(24, $h);
    }

    public function testConstructorCalculatesCellDimensions(): void
    {
        // 80 pixels wide = 40 braille cells (each cell is 2 pixels)
        // 24 pixels tall = 6 braille cells (each cell is 4 pixels)
        $canvas = BrailleCanvas::new(80, 24);
        [$w, $h] = $canvas->getInnerSize();

        $this->assertSame(40, $w);
        $this->assertSame(6, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // setPoint - behavior
    // ═══════════════════════════════════════════════════════════════

    public function testSetPointReturnsNewInstance(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $updated = $canvas->setPoint(0, 0);

        $this->assertNotSame($canvas, $updated);
        // Original unchanged
        $this->assertSame(0, $canvas->getCell(0, 0));
    }

    public function testSetPointOutOfBoundsNoOp(): void
    {
        $canvas = BrailleCanvas::new(10, 10);

        // Negative coordinates
        $result = $canvas->setPoint(-1, 5);
        $this->assertSame($canvas, $result); // Returns same instance (no-op)

        // Beyond width
        $result = $canvas->setPoint(10, 5);
        $this->assertSame($canvas, $result);

        // Beyond height
        $result = $canvas->setPoint(5, 10);
        $this->assertSame($canvas, $result);
    }

    public function testSetPointWithinBoundsUpdatesCell(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $updated = $canvas->setPoint(0, 0);

        // Cell (0,0) should now have bits
        $this->assertNotSame(0, $updated->getCell(0, 0));
    }

    // ═══════════════════════════════════════════════════════════════
    // setPoint - coercion
    // ═══════════════════════════════════════════════════════════════

    public function testSetPointNegativeXYNoOp(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $result = $canvas->setPoint(-5, -5);

        // Should return same instance, no changes made
        $this->assertSame($canvas, $result);
    }

    public function testSetPointBeyondWidthNoOp(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $result = $canvas->setPoint(15, 5);

        $this->assertSame($canvas, $result);
    }

    public function testSetPointBeyondHeightNoOp(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $result = $canvas->setPoint(5, 15);

        $this->assertSame($canvas, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // setLine
    // ═══════════════════════════════════════════════════════════════

    public function testSetLineReturnsNewInstance(): void
    {
        $canvas = BrailleCanvas::new(20, 20);
        $updated = $canvas->setLine(0, 0, 10, 10);

        $this->assertNotSame($canvas, $updated);
    }

    public function testSetLineBresenhamAlgorithm(): void
    {
        // Horizontal line at y=0 from x=0 to x=4
        $canvas = BrailleCanvas::new(10, 10);
        $updated = $canvas->setLine(0, 0, 4, 0);

        // Should have set cells along the line
        $this->assertNotSame(0, $updated->getCell(0, 0));
    }

    public function testSetLineVertical(): void
    {
        // Vertical line at x=2 from y=0 to y=8
        $canvas = BrailleCanvas::new(10, 12);
        $updated = $canvas->setLine(2, 0, 2, 8);

        // Should have cells set along the vertical
        $bits = $updated->getCell(1, 0); // x=2 -> cellX=1
        $this->assertNotSame(0, $bits);
    }

    public function testSetLineDiagonal(): void
    {
        // 45-degree diagonal line
        $canvas = BrailleCanvas::new(20, 20);
        $updated = $canvas->setLine(0, 0, 8, 8);

        // Should have cells set along the diagonal
        $this->assertNotSame(0, $updated->getCell(0, 0));

        // Check intermediate point (should be around cell 2,2)
        $bits = $updated->getCell(2, 2);
        $this->assertNotSame(0, $bits);
    }

    // ═══════════════════════════════════════════════════════════════
    // clear
    // ═══════════════════════════════════════════════════════════════

    public function testClearReturnsNewInstance(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $canvas = $canvas->setPoint(0, 0);
        $cleared = $canvas->clear();

        $this->assertNotSame($canvas, $cleared);
    }

    public function testClearEmptiesCanvas(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $canvas = $canvas->setPoint(0, 0);
        $cleared = $canvas->clear();

        $this->assertSame(0, $cleared->getCell(0, 0));
    }

    public function testClearThenSetPoint(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $canvas = $canvas->setPoint(0, 0);
        $canvas = $canvas->setPoint(1, 0);
        $cleared = $canvas->clear();

        $this->assertSame(0, $cleared->getCell(0, 0));

        // Now set a new point on cleared canvas
        $newCanvas = $cleared->setPoint(5, 5);
        $this->assertNotSame(0, $newCanvas->getCell(2, 1)); // x=5->cellX=2, y=5->cellY=1
    }

    // ═══════════════════════════════════════════════════════════════
    // Rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyCanvasReturnsSpaces(): void
    {
        $canvas = BrailleCanvas::new(4, 4); // 2x1 cells
        $rendered = $canvas->render();

        // Should be 1 line of 2 spaces (4 pixels / 2 = 2 cells wide)
        $this->assertSame('  ', $rendered);
    }

    public function testRenderWithPointsRendersBraille(): void
    {
        $canvas = BrailleCanvas::new(4, 4); // 2x1 cells
        $canvas = $canvas->setPoint(0, 0); // Set top-left dot
        $rendered = $canvas->render();

        // Should contain braille character, not space
        $this->assertNotSame('  ', $rendered);
        // Should be valid UTF-8
        $this->assertNotFalse(mb_strlen($rendered, 'UTF-8'));
    }

    public function testRenderWithLineRendersBraille(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $canvas = $canvas->setLine(0, 0, 8, 0);
        $rendered = $canvas->render();

        // Should have multiple braille characters
        $this->assertNotSame('', $rendered);
        // Should not be all spaces
        $this->assertNotSame(str_repeat(' ', 80), $rendered);
    }

    public function testRenderWithColorRendersAnsi(): void
    {
        $canvas = BrailleCanvas::new(4, 4);
        $canvas = $canvas->setPoint(0, 0, Color::ansi(9)); // Red
        $rendered = $canvas->render();

        // Should contain ANSI escape codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testRenderWithColorEndsWithReset(): void
    {
        $canvas = BrailleCanvas::new(4, 4);
        $canvas = $canvas->setPoint(0, 0, Color::ansi(9));
        $rendered = $canvas->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Cell accumulation
    // ═══════════════════════════════════════════════════════════════

    public function testGetCellReturnsAccumulatedBits(): void
    {
        $canvas = BrailleCanvas::new(4, 4); // 1 cell
        $canvas = $canvas->setPoint(0, 0); // dot at (0,0) = bit 0x01
        $canvas = $canvas->setPoint(1, 0); // dot at (1,0) = bit 0x08

        // Cell should have combined bits
        $bits = $canvas->getCell(0, 0);
        $this->assertSame(0x01 | 0x08, $bits);
    }

    public function testMultipleSetPointOnSameCellAccumulates(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $canvas = $canvas->setPoint(0, 0); // bit 0x01
        $canvas = $canvas->setPoint(0, 1); // bit 0x01 (same cell)
        $canvas = $canvas->setPoint(0, 2); // bit 0x02
        $canvas = $canvas->setPoint(0, 3); // bit 0x02

        $bits = $canvas->getCell(0, 0);
        // Should have accumulated bits from same cell
        $this->assertSame(0x01 | 0x02, $bits);
    }

    public function testGetCellOutOfBoundsReturnsZero(): void
    {
        $canvas = BrailleCanvas::new(10, 10);

        $this->assertSame(0, $canvas->getCell(-1, 0));
        $this->assertSame(0, $canvas->getCell(0, -1));
        $this->assertSame(0, $canvas->getCell(100, 100));
    }

    public function testMultipleSetPointDifferentCellsIndependent(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $canvas = $canvas->setPoint(0, 0); // cell (0,0)
        $canvas = $canvas->setPoint(4, 0); // cell (2,0) - different cell

        // cell (0,0) should have bits
        $this->assertNotSame(0, $canvas->getCell(0, 0));
        // cell (1,0) should be empty
        $this->assertSame(0, $canvas->getCell(1, 0));
        // cell (2,0) should have bits
        $this->assertNotSame(0, $canvas->getCell(2, 0));
    }

    // ═══════════════════════════════════════════════════════════════
    // setSize
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $resized = $canvas->setSize(20, 20);

        $this->assertNotSame($canvas, $resized);
    }

    public function testSetSizeCreatesDifferentDimensions(): void
    {
        $canvas = BrailleCanvas::new(10, 10);
        $resized = $canvas->setSize(20, 20);

        [$w, $h] = $resized->getPixelSize();
        $this->assertSame(20, $w);
        $this->assertSame(20, $h);
    }
}
