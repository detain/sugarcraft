<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Braille;

use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A canvas that renders to braille characters (2x4 dots per cell).
 *
 * Provides 2x horizontal and 4x vertical resolution compared to
 * standard character cells. Uses Bresenham's line algorithm for
 * anti-aliased line drawing.
 *
 * Mirrors termui/drawille_drawille.go:7-83
 */
final class BrailleCanvas implements Sizer
{
    /** @var list<list<int>> accumulated dot bits per cell */
    private array $cells = [];

    /** @var list<list<\SugarCraft\Core\Util\Color|null>> */
    private array $colors = [];

    private int $pixelWidth;
    private int $pixelHeight;
    private int $cellWidth;
    private int $cellHeight;

    public function __construct(int $pixelWidth, int $pixelHeight)
    {
        $this->pixelWidth = $pixelWidth;
        $this->pixelHeight = $pixelHeight;
        $this->cellWidth = BrailleMatrix::cellWidth($pixelWidth);
        $this->cellHeight = BrailleMatrix::cellHeight($pixelHeight);

        // Initialize grids with 0 bits (empty cells)
        $this->cells = array_fill(0, $this->cellHeight, array_fill(0, $this->cellWidth, 0));
        $this->colors = array_fill(0, $this->cellHeight, array_fill(0, $this->cellWidth, null));
    }

    public static function new(int $pixelWidth, int $pixelHeight): self
    {
        return new self($pixelWidth, $pixelHeight);
    }

    /**
     * Set a single dot at pixel coordinates (x, y).
     *
     * @param int $x Pixel X coordinate
     * @param int $y Pixel Y coordinate
     * @param \SugarCraft\Core\Util\Color|null $color Color for this dot (null = use cell's current color)
     */
    public function setPoint(int $x, int $y, ?\SugarCraft\Core\Util\Color $color = null): self
    {
        if ($x < 0 || $y < 0 || $x >= $this->pixelWidth || $y >= $this->pixelHeight) {
            return $this; // Out of bounds - no-op
        }

        $clone = clone $this;
        $clone->cells = array_map(fn(array $row) => [...$row], $this->cells);
        $clone->colors = array_map(fn(array $row) => [...$row], $this->colors);

        $cellX = BrailleMatrix::cellX($x);
        $cellY = BrailleMatrix::cellY($y);
        $dotBit = BrailleMatrix::dotBit($x, $y);

        $clone->cells[$cellY][$cellX] |= $dotBit;
        if ($color !== null) {
            $clone->colors[$cellY][$cellX] = $color;
        }

        return $clone;
    }

    /**
     * Draw a line from (x1,y1) to (x2,y2) using Bresenham's algorithm.
     *
     * @param int $x1 Start X
     * @param int $y1 Start Y
     * @param int $x2 End X
     * @param int $y2 End Y
     * @param \SugarCraft\Core\Util\Color|null $color Color for the line
     */
    public function setLine(int $x1, int $y1, int $x2, int $y2, ?\SugarCraft\Core\Util\Color $color = null): self
    {
        $clone = $this;

        $dx = abs($x2 - $x1);
        $dy = abs($y2 - $y1);
        $sx = $x1 < $x2 ? 1 : -1;
        $sy = $y1 < $y2 ? 1 : -1;
        $err = $dx - $dy;

        while (true) {
            $clone = $clone->setPoint($x1, $y1, $color);

            if ($x1 === $x2 && $y1 === $y2) {
                break;
            }

            $e2 = 2 * $err;

            if ($e2 > -$dy) {
                $err -= $dy;
                $x1 += $sx;
            }

            if ($e2 < $dx) {
                $err += $dx;
                $y1 += $sy;
            }
        }

        return $clone;
    }

    /**
     * Clear all dots from the canvas.
     */
    public function clear(): self
    {
        $clone = clone $this;
        $clone->cells = array_fill(0, $this->cellHeight, array_fill(0, $this->cellWidth, 0));
        $clone->colors = array_fill(0, $this->cellHeight, array_fill(0, $this->cellWidth, null));
        return $clone;
    }

    /**
     * Get accumulated bits for a cell.
     */
    public function getCell(int $cellX, int $cellY): int
    {
        if ($cellX < 0 || $cellY < 0 || $cellX >= $this->cellWidth || $cellY >= $this->cellHeight) {
            return 0;
        }
        return $this->cells[$cellY][$cellX];
    }

    /**
     * Iterate over all cells that have dots set.
     *
     * Yields arrays of [cellX, cellY, bits, ?Color] for each cell
     * where at least one dot bit is set.
     *
     * @return \Generator<array{0:int, 1:int, 2:int, 3:\SugarCraft\Core\Util\Color|null}>
     */
    public function cells(): \Generator
    {
        for ($cellY = 0; $cellY < $this->cellHeight; $cellY++) {
            for ($cellX = 0; $cellX < $this->cellWidth; $cellX++) {
                $bits = $this->cells[$cellY][$cellX];
                if ($bits !== 0) {
                    yield [$cellX, $cellY, $bits, $this->colors[$cellY][$cellX]];
                }
            }
        }
    }

    /**
     * Render the canvas as a string of braille characters.
     */
    public function render(?ColorProfile $profile = null): string
    {
        $profile ??= ColorProfile::detect();

        $lines = [];

        for ($cellY = 0; $cellY < $this->cellHeight; $cellY++) {
            $line = '';
            for ($cellX = 0; $cellX < $this->cellWidth; $cellX++) {
                $bits = $this->cells[$cellY][$cellX];
                if ($bits === 0) {
                    $line .= ' ';
                } else {
                    $rune = BrailleMatrix::rune($bits);
                    $color = $this->colors[$cellY][$cellX];
                    if ($color !== null) {
                        $line .= $color->toFg($profile) . $rune . "\x1b[0m";
                    } else {
                        $line .= $rune . ' ';
                    }
                }
            }
            // Only rtrim if line has non-space content, to preserve empty canvas rendering
            $trimmed = rtrim($line);
            $lines[] = $trimmed === '' ? $line : $trimmed;
        }

        return implode("\n", $lines);
    }

    public function setSize(int $width, int $height): Sizer
    {
        return new self($width, $height);
    }

    public function getInnerSize(): array
    {
        return [$this->cellWidth, $this->cellHeight];
    }

    public function getPixelSize(): array
    {
        return [$this->pixelWidth, $this->pixelHeight];
    }
}
