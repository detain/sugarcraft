<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Braille;

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
     * 2x4 lookup table for braille dot positions (8 rows = 2 braille cells vertically).
     * Index: [row % 8][column % 2]
     * Value: bitmask for that dot position
     *
     * Braille cell dot layout (each cell is 2 cols x 4 rows):
     *   (0,0) (1,0)  <- row 0: left=0x01, right=0x08
     *   (0,1) (1,1)  <- row 1: same as row 0
     *   (0,2) (1,2)  <- row 2: left=0x02, right=0x10
     *   (0,3) (1,3)  <- row 3: same as row 2
     *   (0,4) (1,4)  <- row 4: left=0x04, right=0x20
     *   (0,5) (1,5)  <- row 5: same as row 4
     *   (0,6) (1,6)  <- row 6: left=0x40, right=0x80
     *   (0,7) (1,7)  <- row 7: same as row 6
     */
    private const BRAILLE = [
        [0x01, 0x08],  // row 0: top-left, top-right
        [0x01, 0x08],  // row 1: same as row 0
        [0x02, 0x10],  // row 2
        [0x02, 0x10],  // row 3: same as row 2
        [0x04, 0x20],  // row 4
        [0x04, 0x20],  // row 5: same as row 4
        [0x40, 0x80],  // row 6
        [0x40, 0x80],  // row 7: same as row 6
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
        $row = $pixelY % 8;
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
