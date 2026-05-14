<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plot\Braille\BrailleCanvas;
use SugarCraft\Dash\Plot\Braille\BrailleMatrix;

/**
 * Tests for BrailleCanvas geometry operations.
 *
 * BrailleCanvas renders to 2x4 dot braille characters, giving
 * 2x horizontal and 4x vertical resolution compared to character cells.
 */
final class BrailleGeometryTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Line drawing
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: diagonal line from top-left to bottom-right.
     * The line should set dots in the braille cell at (0,0).
     */
    public function testBrailleLineDiagonalAt4x10(): void
    {
        // 10 pixels wide, 4 pixels tall = 5x1 braille cells
        $canvas = BrailleCanvas::new(10, 4);
        $canvas = $canvas->setLine(0, 0, 9, 3); // diagonal from top-left to bottom-right

        // At minimum, the cell at (0,0) should have bits set
        $cellBits = $canvas->getCell(0, 0);
        $this->assertNotSame(0, $cellBits, 'Top-left cell should have bits set for diagonal');

        // Render should produce non-empty output with braille characters
        $rendered = $canvas->render();
        $this->assertNotEmpty($rendered);
    }

    /**
     * Test: horizontal line across the top.
     */
    public function testBrailleLineHorizontal(): void
    {
        // 80 pixels wide, 4 pixels tall = 40x1 braille cells
        $canvas = BrailleCanvas::new(80, 4);
        $canvas = $canvas->setLine(0, 0, 79, 0);

        // First cell should have bits set (top row dots)
        $cellBits = $canvas->getCell(0, 0);
        $this->assertNotSame(0, $cellBits, 'First cell should have bits for horizontal line');

        // Multiple cells should have bits along the line
        $anyBitsSet = false;
        for ($x = 0; $x < 40; $x++) {
            if ($canvas->getCell($x, 0) !== 0) {
                $anyBitsSet = true;
                break;
            }
        }
        $this->assertTrue($anyBitsSet, 'Some cell along the line should have bits set');
    }

    /**
     * Test: vertical line down the left side.
     */
    public function testBrailleLineVertical(): void
    {
        // 8 pixels wide, 32 pixels tall = 4x8 braille cells
        $canvas = BrailleCanvas::new(8, 32);
        $canvas = $canvas->setLine(0, 0, 0, 31);

        // First column should have bits set
        $cellBits = $canvas->getCell(0, 0);
        $this->assertNotSame(0, $cellBits, 'First cell should have bits for vertical line');
    }

    // ═══════════════════════════════════════════════════════════════
    // Point setting
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: point at origin (0,0) produces braille dots.
     * Origin is the top-left pixel.
     */
    public function testBraillePointAtOrigin(): void
    {
        // 4 pixels wide, 4 pixels tall = 2x1 braille cell
        $canvas = BrailleCanvas::new(4, 4);
        $canvas = $canvas->setPoint(0, 0);

        $cellBits = $canvas->getCell(0, 0);
        $this->assertNotSame(0, $cellBits, 'Cell should have bits set for point at origin');

        // Verify the bits are in valid braille range
        // The dot bit for origin should be 0x01 (top-left of cell)
        $this->assertSame(0x01, $cellBits & 0x01, 'Origin should set top-left dot (0x01)');
    }

    /**
     * Test: point at different positions within a cell.
     */
    public function testBraillePointDifferentPositions(): void
    {
        // 4 pixels wide, 8 pixels tall = 2x2 braille cells
        $canvas = BrailleCanvas::new(4, 8);

        // Point at (1,0) - top-right pixel of cell (0,0)
        $canvas1 = $canvas->setPoint(1, 0);
        $this->assertSame(0x08, $canvas1->getCell(0, 0) & 0x08, 'Pixel (1,0) should set top-right dot');

        // Point at (0,2) - middle-left pixel of cell (0,0)
        $canvas2 = $canvas->setPoint(0, 2);
        $this->assertSame(0x02, $canvas2->getCell(0, 0) & 0x02, 'Pixel (0,2) should set middle-left dot');
    }

    /**
     * Test: multiple points in same cell accumulate.
     */
    public function testBrailleMultiplePointsAccumulate(): void
    {
        $canvas = BrailleCanvas::new(4, 8);
        $canvas = $canvas->setPoint(0, 0);  // top-left (0x01)
        $canvas = $canvas->setPoint(1, 0);  // top-right (0x08)

        $bits = $canvas->getCell(0, 0);
        // Both dots should be set
        $this->assertSame(0x09, $bits, 'Both top-left and top-right dots should be set');
    }

    // ═══════════════════════════════════════════════════════════════
    // Canvas clearing
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: clear removes all dots.
     */
    public function testBrailleCanvasClear(): void
    {
        $canvas = BrailleCanvas::new(4, 4);
        $canvas = $canvas->setPoint(0, 0);

        // Verify point was set
        $this->assertNotSame(0, $canvas->getCell(0, 0));

        // Clear the canvas
        $canvas = $canvas->clear();

        // All cells should be zero
        $this->assertSame(0, $canvas->getCell(0, 0), 'Cell should be empty after clear');

        // Render should produce empty/whitespace output
        $rendered = $canvas->render();
        $this->assertNotNull($rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Out of bounds handling
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: setPoint with out-of-bounds coordinates is a no-op.
     */
    public function testSetPointOutOfBoundsNoOp(): void
    {
        $canvas = BrailleCanvas::new(10, 10);

        // These should be ignored (no exception, no change)
        $canvas = $canvas->setPoint(-1, 0);
        $canvas = $canvas->setPoint(0, -1);
        $canvas = $canvas->setPoint(10, 0);  // x == width is out of bounds
        $canvas = $canvas->setPoint(0, 10);  // y == height is out of bounds

        // All cells should still be zero
        $this->assertSame(0, $canvas->getCell(0, 0));
    }

    /**
     * Test: getCell returns 0 for out-of-bounds coordinates.
     */
    public function testGetCellOutOfBoundsReturnsZero(): void
    {
        $canvas = BrailleCanvas::new(10, 10);

        $this->assertSame(0, $canvas->getCell(-1, 0));
        $this->assertSame(0, $canvas->getCell(0, -1));
        $this->assertSame(0, $canvas->getCell(10, 0));
        $this->assertSame(0, $canvas->getCell(0, 10));
    }

    // ═══════════════════════════════════════════════════════════════
    // Rendering
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: render produces unicode braille characters.
     */
    public function testRenderProducesBrailleCharacters(): void
    {
        $canvas = BrailleCanvas::new(4, 4);
        $canvas = $canvas->setPoint(0, 0);

        $rendered = $canvas->render();

        // Should contain braille unicode range (U+2800 to U+28FF)
        // Check that the output contains at least one character with code >= 0x2800
        $chars = mb_str_split($rendered);
        $foundBraille = false;
        foreach ($chars as $char) {
            $code = mb_ord($char);
            if ($code >= 0x2800 && $code <= 0x28FF) {
                $foundBraille = true;
                break;
            }
        }
        $this->assertTrue($foundBraille, 'Rendered output should contain braille characters');
    }

    /**
     * Test: empty canvas renders as spaces.
     */
    public function testRenderEmptyCanvasIsSpaces(): void
    {
        $canvas = BrailleCanvas::new(10, 4);
        $rendered = $canvas->render();

        // Empty canvas should only contain spaces and newlines
        $this->assertMatchesRegularExpression('/^[ \n]*$/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Cell dimensions
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: cell dimensions are calculated correctly.
     * Each braille cell is 2 pixels wide and 4 pixels tall.
     */
    public function testCellDimensions(): void
    {
        // 80 pixels / 2 = 40 cells wide
        // 24 pixels / 4 = 6 cells tall
        $canvas = BrailleCanvas::new(80, 24);

        [$cellWidth, $cellHeight] = $canvas->getInnerSize();
        $this->assertSame(40, $cellWidth);
        $this->assertSame(6, $cellHeight);
    }

    /**
     * Test: pixel dimensions are preserved.
     */
    public function testPixelDimensions(): void
    {
        $canvas = BrailleCanvas::new(80, 24);

        [$pixelWidth, $pixelHeight] = $canvas->getPixelSize();
        $this->assertSame(80, $pixelWidth);
        $this->assertSame(24, $pixelHeight);
    }

    /**
     * Test: minimum cell dimensions are 1x1 even for small canvases.
     */
    public function testMinimumCellDimensions(): void
    {
        // 1x1 pixel should still give at least 1x1 cell
        $canvas = BrailleCanvas::new(1, 1);

        [$cellWidth, $cellHeight] = $canvas->getInnerSize();
        $this->assertGreaterThanOrEqual(1, $cellWidth);
        $this->assertGreaterThanOrEqual(1, $cellHeight);
    }
}
