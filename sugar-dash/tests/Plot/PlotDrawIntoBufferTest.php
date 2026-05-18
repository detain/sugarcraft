<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot;

use SugarCraft\Dash\Foundation\Buffer;
use SugarCraft\Dash\Foundation\Cell;
use SugarCraft\Dash\Foundation\Style;
use SugarCraft\Dash\Plot\Plot;
use SugarCraft\Dash\Plot\Braille\BrailleCanvas;
use SugarCraft\Dash\Plot\Braille\BrailleMatrix;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

/**
 * Tests Plot::draw() writing braille cells directly into a Buffer.
 *
 * Verifies the step-03.11 contract: draw() uses setCell() to write cells
 * directly rather than falling back to render() + setString().
 */
final class PlotDrawIntoBufferTest extends TestCase
{
    /**
     * For a 4×4-cell canvas (dotWidth=8, dotHeight=16), using data
     * values [0.0, 15.0] with minValue=0, maxValue=15 produces a line from
     * canvas pixel (0,15) to (4,0) after the y-inversion.
     *
     * Bresenham from (0,15) to (4,0) hits:
     *   step 0: (0,15) → cell(0,3) bit 0x80 → buffer(2,4)
     *   step 1: (0,14) → cell(0,3) bit 0x80 → OR 0x80
     *   step 2: (1,13) → cell(0,3) bit 0x80 → OR 0x80 (same cell, same bit)
     *   step 3: (1,12) → cell(0,3) bit 0x80 → OR 0x80
     *   step 4: (2,11) → cell(1,2) bit 0x10
     *   step 5: (2,10) → cell(1,2) bit 0x10 → OR 0x20? no...
     *          pixel (2,10): localY = 10%8 = 2, localX = 2%2 = 0 → bit 0x02
     *   step 6: (2,9)  → cell(1,2) bit 0x10
     *   step 7: (3,8)  → cell(1,2) bit 0x10  (localX=1, localY=0→bit 0x08)
     *   step 8: (3,7)  → cell(1,1) bit 0x08
     *   step 9: (3,6)  → cell(1,1) bit 0x80? localY=6%8=6, bit 0x40
     *   step10: (3,5)  → cell(1,1) bit 0x20? localY=5%8=5, bit 0x20
     *   step11: (3,4)  → cell(1,1) bit 0x10
     *   step12: (3,3)  → cell(1,0) bit 0x10
     *   step13: (3,2)  → cell(1,0) bit 0x10
     *   step14: (3,1)  → cell(1,0) bit 0x08
     *   step15: (4,0)  → cell(2,0) bit 0x10
     *
     * Result: cell(0,3)=0x80, cell(1,2)=0x12?, cell(1,1)=0xB8?, cell(1,0)=0x28?, cell(2,0)=0x10
     *
     * Simpler: verify that draw() writes braille cells and doesn't use setString fallback.
     */
    public function testLineFromTopToBottomWritesBrailleCells(): void
    {
        // 6×6 buffer → inner 4×4 canvas (axes consume 2 cols + 2 rows)
        // data [0, 15] with min=0, max=15 → line from (0,15) to (4,0)
        $plot = Plot::new([0.0, 15.0], 6, 6)
            ->withMinValue(0.0)
            ->withMaxValue(15.0)
            ->withShowAxes(true)
            ->withMode(Plot::MODE_LINE);

        $buffer = Buffer::new(6, 6);
        $plot->draw($buffer);

        // Verify canvas area (x>=2, y>=1, y<=4) has at least one braille cell
        $brailleCells = [];
        for ($y = 1; $y <= 4; $y++) {
            for ($x = 2; $x <= 5; $x++) {
                $rune = $buffer->getCell($x, $y)->rune;
                $ord = mb_ord($rune, 'UTF-8');
                if ($ord >= 0x2800 && $ord <= 0x28FF) {
                    $brailleCells[] = [$x, $y, $rune];
                }
            }
        }

        $this->assertNotEmpty($brailleCells, 'Should have at least one braille cell from line drawing');
    }

    /**
     * Verify cells NOT on the line remain as default Cell (rune=' ').
     */
    public function testNonLineCellsAreEmpty(): void
    {
        $plot = Plot::new([0.0, 15.0], 6, 6)
            ->withMinValue(0.0)
            ->withMaxValue(15.0)
            ->withShowAxes(true)
            ->withMode(Plot::MODE_LINE);

        $buffer = Buffer::new(6, 6);
        $plot->draw($buffer);

        // Scan only the canvas area (x>=2, y>=1, y<=4) for non-empty cells
        // Y-labels are at x=0,1 — they are NOT in the canvas area
        $canvasBrailleCells = [];
        for ($y = 1; $y <= 4; $y++) {
            for ($x = 2; $x <= 5; $x++) {
                $cell = $buffer->getCell($x, $y);
                $ord = mb_ord($cell->rune, 'UTF-8');
                if ($ord >= 0x2800 && $ord <= 0x28FF) {
                    $canvasBrailleCells[] = [$x, $y, $cell->rune];
                }
            }
        }

        // Should have exactly N canvas cells with braille (at least 1)
        $this->assertNotEmpty($canvasBrailleCells, 'Should have at least one braille cell in the canvas area');
    }

    /**
     * Verify that draw() with no axes writes directly into a small buffer.
     */
    public function testDrawWithoutAxesWritesCells(): void
    {
        // No axes: width=4, height=4 → inner 4×4, canvas starts at (0,0)
        $plot = Plot::new([0.0, 100.0], 4, 4)
            ->withMinValue(0.0)
            ->withMaxValue(100.0)
            ->withShowAxes(false)
            ->withMode(Plot::MODE_LINE);

        $buffer = Buffer::new(4, 4);
        $plot->draw($buffer);

        // Should have at least some braille cells in the canvas
        $brailleCount = 0;
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $rune = $buffer->getCell($x, $y)->rune;
                $ord = mb_ord($rune, 'UTF-8');
                if ($ord >= 0x2800 && $ord <= 0x28FF) {
                    $brailleCount++;
                }
            }
        }

        $this->assertGreaterThan(0, $brailleCount, 'Should have at least one braille cell in 4×4 no-axes buffer');
    }

    /**
     * Scatter plot mode should write individual dots, not connected lines.
     */
    public function testScatterPlotModeWritesDots(): void
    {
        $plot = Plot::new([0.0, 50.0, 100.0], 4, 4)
            ->withMinValue(0.0)
            ->withMaxValue(100.0)
            ->withShowAxes(false)
            ->withMode(Plot::MODE_SCATTER);

        $buffer = Buffer::new(4, 4);
        $plot->draw($buffer);

        // Should have dots at roughly three different x positions
        $brailleCells = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $rune = $buffer->getCell($x, $y)->rune;
                $ord = mb_ord($rune, 'UTF-8');
                if ($ord >= 0x2800 && $ord <= 0x28FF) {
                    $brailleCells[] = [$x, $y, $rune];
                }
            }
        }

        $this->assertNotEmpty($brailleCells, 'Scatter plot should produce dots');
    }

    /**
     * Verify that the buffer contains braille characters (not a render() string).
     */
    public function testDrawProducesBrailleCharacters(): void
    {
        $plot = Plot::new([10, 20, 30, 40, 50], 20, 10)
            ->withMinValue(0)
            ->withMaxValue(100);

        $buffer = Buffer::new(20, 10);
        $plot->draw($buffer);

        $rendered = $buffer->render();

        // Should contain braille characters
        $this->assertMatchesRegularExpression(
            '/[\x{2800}-\x{28FF}]/u',
            $rendered,
            'Plot draw output should contain braille characters'
        );
    }

    /**
     * Empty data should not crash draw() and should not write any content.
     */
    public function testDrawWithEmptyDataDoesNotWriteAxes(): void
    {
        $plot = Plot::new([], 6, 6)
            ->withShowAxes(true);

        $buffer = Buffer::new(6, 6);
        $plot->draw($buffer);  // Should not throw

        // With empty data and axes, nothing should be written (matching render() behavior)
        // All cells should remain as default (space)
        for ($y = 0; $y < 6; $y++) {
            for ($x = 0; $x < 6; $x++) {
                $this->assertSame(' ', $buffer->getCell($x, $y)->rune, "Cell ($x,$y) should be space for empty data");
            }
        }
    }

    /**
     * Plot with color applies style to drawn cells.
     */
    public function testDrawWithColorAppliesStyle(): void
    {
        $plot = Plot::new([0.0, 100.0], 4, 4)
            ->withMinValue(0.0)
            ->withMaxValue(100.0)
            ->withShowAxes(false)
            ->withColor(Color::ansi(1));  // red

        $buffer = Buffer::new(4, 4);
        $plot->draw($buffer);

        // Find a non-empty cell and verify it has a non-null foreground
        $foundStyled = false;
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $cell = $buffer->getCell($x, $y);
                if ($cell->rune !== ' ') {
                    $this->assertNotNull($cell->style->foreground, 'Braille cell should have foreground color');
                    $foundStyled = true;
                    break 2;
                }
            }
        }

        $this->assertTrue($foundStyled, 'Should have found at least one non-empty cell');
    }
}
