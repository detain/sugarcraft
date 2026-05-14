<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Braille;

use SugarCraft\Dash\Plot\Braille\BrailleMatrix;
use PHPUnit\Framework\TestCase;

final class BrailleMatrixTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Cell coordinate calculations
    // ═══════════════════════════════════════════════════════════════

    public function testCellXReturnsIntDivBy2(): void
    {
        // Each braille cell is 2 pixels wide
        $this->assertSame(0, BrailleMatrix::cellX(0));
        $this->assertSame(0, BrailleMatrix::cellX(1));
        $this->assertSame(1, BrailleMatrix::cellX(2));
        $this->assertSame(1, BrailleMatrix::cellX(3));
        $this->assertSame(2, BrailleMatrix::cellX(4));
        $this->assertSame(5, BrailleMatrix::cellX(10));
        $this->assertSame(10, BrailleMatrix::cellX(20));
    }

    public function testCellYReturnsIntDivBy4(): void
    {
        // Each braille cell is 4 pixels tall
        $this->assertSame(0, BrailleMatrix::cellY(0));
        $this->assertSame(0, BrailleMatrix::cellY(1));
        $this->assertSame(0, BrailleMatrix::cellY(3));
        $this->assertSame(1, BrailleMatrix::cellY(4));
        $this->assertSame(1, BrailleMatrix::cellY(5));
        $this->assertSame(1, BrailleMatrix::cellY(7));
        $this->assertSame(2, BrailleMatrix::cellY(8));
        $this->assertSame(3, BrailleMatrix::cellY(15));
    }

    // ═══════════════════════════════════════════════════════════════
    // Dot bit calculations
    // ═══════════════════════════════════════════════════════════════

    public function testDotBitReturnsCorrectBits(): void
    {
        // BRAILLE lookup table:
        // [0x01, 0x08]  // rows 0, 1: left column dots
        // [0x02, 0x10]  // rows 2, 3
        // [0x04, 0x20]  // rows 4, 5
        // [0x40, 0x80]  // rows 6, 7

        // Top-left dot (row 0, col 0)
        $this->assertSame(0x01, BrailleMatrix::dotBit(0, 0));
        // Top-right dot (row 0, col 1)
        $this->assertSame(0x08, BrailleMatrix::dotBit(1, 0));
        // Second row left (row 1, col 0)
        $this->assertSame(0x01, BrailleMatrix::dotBit(0, 1));
        // Third row left (row 2, col 0)
        $this->assertSame(0x02, BrailleMatrix::dotBit(0, 2));
        // Fourth row right (row 3, col 1)
        $this->assertSame(0x10, BrailleMatrix::dotBit(1, 3));
        // Fifth row left (row 4, col 0)
        $this->assertSame(0x04, BrailleMatrix::dotBit(0, 4));
        // Bottom-left (row 6, col 0)
        $this->assertSame(0x40, BrailleMatrix::dotBit(0, 6));
        // Bottom-right (row 7, col 1)
        $this->assertSame(0x80, BrailleMatrix::dotBit(1, 7));
    }

    public function testDotBitWrapsWithinCell(): void
    {
        // Pixel coords wrap within cell dimensions
        // row 8 should map to row 0 (8 % 4 = 0)
        $this->assertSame(BrailleMatrix::dotBit(0, 0), BrailleMatrix::dotBit(0, 8));
        // col 2 should map to col 0 (2 % 2 = 0)
        $this->assertSame(BrailleMatrix::dotBit(0, 0), BrailleMatrix::dotBit(2, 0));
    }

    // ═══════════════════════════════════════════════════════════════
    // Rune generation
    // ═══════════════════════════════════════════════════════════════

    public function testRuneGeneratesValidUnicode(): void
    {
        // Braille offset is 0x2800
        // Bits 0x00 should give U+2800 (blank braille)
        $blank = BrailleMatrix::rune(0x00);
        $this->assertSame("\u{2800}", $blank);

        // All dots lit: 0x01|0x02|0x04|0x08|0x10|0x20|0x40|0x80 = 0xFF
        $allDots = BrailleMatrix::rune(0xFF);
        $this->assertSame("\u{28FF}", $allDots);
    }

    public function testRuneIsInBrailleRange(): void
    {
        // All valid braille characters should be in range U+2800 to U+28FF
        $rune = BrailleMatrix::rune(0x55);
        $codepoint = mb_ord($rune, 'UTF-8');

        $this->assertGreaterThanOrEqual(0x2800, $codepoint);
        $this->assertLessThanOrEqual(0x28FF, $codepoint);
    }

    public function testRuneCombinesBits(): void
    {
        // Combining specific dots should produce combined character
        // Top-left (0x01) + bottom-right (0x80)
        $combined = BrailleMatrix::rune(0x01 | 0x80);
        $codepoint = mb_ord($combined, 'UTF-8');

        // Should be offset + combined bits
        $this->assertSame(BrailleMatrix::BRAILLE_OFFSET + 0x81, $codepoint);
    }

    // ═══════════════════════════════════════════════════════════════
    // Cell dimensions
    // ═══════════════════════════════════════════════════════════════

    public function testCellWidthCalculatesCorrectly(): void
    {
        // Formula: intdiv($pixelWidth + 1, 2)
        $this->assertSame(1, BrailleMatrix::cellWidth(0));  // (0+1)/2 = 0.5 -> 0
        $this->assertSame(1, BrailleMatrix::cellWidth(1));  // (1+1)/2 = 1
        $this->assertSame(1, BrailleMatrix::cellWidth(2));  // (2+1)/2 = 1.5 -> 1
        $this->assertSame(2, BrailleMatrix::cellWidth(3));  // (3+1)/2 = 2
        $this->assertSame(2, BrailleMatrix::cellWidth(4));  // (4+1)/2 = 2.5 -> 2
        $this->assertSame(3, BrailleMatrix::cellWidth(5));  // (5+1)/2 = 3
        $this->assertSame(5, BrailleMatrix::cellWidth(9));  // (9+1)/2 = 5
    }

    public function testCellHeightCalculatesCorrectly(): void
    {
        // Formula: intdiv($pixelHeight + 3, 4)
        $this->assertSame(1, BrailleMatrix::cellHeight(0));  // (0+3)/4 = 0.75 -> 0
        $this->assertSame(1, BrailleMatrix::cellHeight(1));  // (1+3)/4 = 1
        $this->assertSame(1, BrailleMatrix::cellHeight(3));  // (3+3)/4 = 1.5 -> 1
        $this->assertSame(1, BrailleMatrix::cellHeight(4));  // (4+3)/4 = 1.75 -> 1
        $this->assertSame(2, BrailleMatrix::cellHeight(5));  // (5+3)/4 = 2
        $this->assertSame(2, BrailleMatrix::cellHeight(7));  // (7+3)/4 = 2.5 -> 2
        $this->assertSame(3, BrailleMatrix::cellHeight(9));  // (9+3)/4 = 3
        $this->assertSame(3, BrailleMatrix::cellHeight(11)); // (11+3)/4 = 3.5 -> 3
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNegativePixelCoordNoCrash(): void
    {
        // Should handle gracefully (PHP intdiv with negatives)
        $this->assertSame(-1, BrailleMatrix::cellX(-2));
        $this->assertSame(-1, BrailleMatrix::cellY(-4));
    }

    public function testLargePixelCoordNoCrash(): void
    {
        // Should handle large values without overflow
        $cellX = BrailleMatrix::cellX(10000);
        $cellY = BrailleMatrix::cellY(10000);

        $this->assertSame(5000, $cellX);
        $this->assertSame(2500, $cellY);
    }

    public function testBrailleOffsetConstant(): void
    {
        $this->assertSame(0x2800, BrailleMatrix::BRAILLE_OFFSET);
    }
}
