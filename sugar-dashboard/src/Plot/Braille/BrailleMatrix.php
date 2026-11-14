<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plot\Braille;

/**
 * Braille dot matrix constants and utilities.
 *
 * Each braille character represents a 2x4 dot matrix (2 columns, 4 rows).
 * This gives 2x horizontal and 4x vertical resolution compared to
 * standard character cells.
 *
 * Mirrors termui/drawille_drawille.go:7-39
 */
final class BrailleMatrix
{
    /**
     * 2x4 lookup table for braille dot positions.
     *
     * Index: [row % 4][column % 2] - a braille CELL is 4 pixel rows tall
     * (finding sugar-dashboard-076: the table used to double every row to
     * fake 8 rows and dotBit indexed it `% 8`, so pixels in the lower half
     * of one cell carried dot bits that belong to a DIFFERENT dot row -
     * cellY() has always mapped rows four at a time).
     *
     * Standard braille dot layout (left column dots 1-2-3-7, right 4-5-6-8):
     *   row 0: left=0x01, right=0x08
     *   row 1: left=0x02, right=0x10
     *   row 2: left=0x04, right=0x20
     *   row 3: left=0x40, right=0x80
     */
    private const BRAILLE = [
        [0x01, 0x08],  // row 0: dots 1 / 4
        [0x02, 0x10],  // row 1: dots 2 / 5
        [0x04, 0x20],  // row 2: dots 3 / 6
        [0x40, 0x80],  // row 3: dots 7 / 8
    ];

    /**
     * Base Unicode code point for braille patterns (U+2800).
     */
    public const BRAILLE_OFFSET = 0x2800;

    /**
     * Get the braille cell column index from a pixel X coordinate.
     *
     * Each braille cell is 2 pixels wide.
     */
    public static function cellX(int $pixelX): int
    {
        return intdiv($pixelX, 2);
    }

    /**
     * Get the braille cell row index from a pixel Y coordinate.
     *
     * Each braille cell is 4 pixels tall.
     */
    public static function cellY(int $pixelY): int
    {
        return intdiv($pixelY, 4);
    }

    /**
     * Get the dot bit for a pixel position within its braille cell.
     *
     * @return int Bitmask for the dot at (localX, localY) within the cell
     */
    public static function dotBit(int $pixelX, int $pixelY): int
    {
        $row = $pixelY % 4;
        $col = $pixelX % 2;
        return self::BRAILLE[$row][$col];
    }

    /**
     * Build a braille rune from accumulated bits.
     *
     * @param int $bits OR'd combination of dot bits
     */
    public static function rune(int $bits): string
    {
        return mb_chr(self::BRAILLE_OFFSET + $bits);
    }

    /**
     * Get the character width in braille cells.
     * A braille cell is 2 pixels wide; at least 1 cell is needed for any pixels.
     */
    public static function cellWidth(int $pixelWidth): int
    {
        return max(1, intdiv($pixelWidth + 1, 2));
    }

    /**
     * Get the character height in braille cells.
     * A braille cell is 4 pixels tall; at least 1 cell is needed for any pixels.
     */
    public static function cellHeight(int $pixelHeight): int
    {
        return max(1, intdiv($pixelHeight + 3, 4));
    }
}
