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
     * 4x2 lookup table for braille dot positions.
     * Index: [row % 4][column % 2]
     * Value: bitmask for that dot position
     */
    private const BRAILLE = [
        [0x01, 0x08],  // rows 0, 1: left column dots
        [0x02, 0x10],  // rows 2, 3
        [0x04, 0x20],  // rows 4, 5
        [0x40, 0x80],  // rows 6, 7
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
     */
    public static function cellWidth(int $pixelWidth): int
    {
        return intdiv($pixelWidth + 1, 2);
    }

    /**
     * Get the character height in braille cells.
     */
    public static function cellHeight(int $pixelHeight): int
    {
        return intdiv($pixelHeight + 3, 4);
    }
}
