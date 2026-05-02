<?php

declare(strict_types=1);

namespace CandyCore\Charts\Canvas;

use CandyCore\Sprinkles\Style;

/**
 * Fixed-size 2D grid of {@see Cell}s. Charts draw onto the canvas and
 * call {@see view()} to produce a ready-to-print frame. Coordinates are
 * 0-based: $x is column, $y is row, with (0, 0) at the top-left.
 *
 * The canvas is mutable in place — cheaper for hot rendering paths than
 * building a new grid per draw call.
 */
final class Canvas
{
    /** @var array<int, array<int, Cell>> [row][col] */
    private array $cells;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('canvas width/height must be >= 0');
        }
        $this->clear();
    }

    public function setCell(int $x, int $y, string $rune, ?Style $style = null): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return;
        }
        $this->cells[$y][$x] = new Cell($rune, $style);
    }

    public function getCell(int $x, int $y): Cell
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return new Cell();
        }
        return $this->cells[$y][$x] ?? new Cell();
    }

    public function clear(): void
    {
        $this->cells = [];
        for ($y = 0; $y < $this->height; $y++) {
            $this->cells[$y] = [];
        }
    }

    /** Render the canvas as a string. Empty cells render as spaces. */
    public function view(): string
    {
        $rows = [];
        for ($y = 0; $y < $this->height; $y++) {
            $row = '';
            for ($x = 0; $x < $this->width; $x++) {
                $cell = $this->cells[$y][$x] ?? null;
                if ($cell === null) {
                    $row .= ' ';
                    continue;
                }
                $rune = $cell->rune === '' ? ' ' : $cell->rune;
                $row .= $cell->style !== null
                    ? $cell->style->render($rune)
                    : $rune;
            }
            $rows[] = rtrim($row);
        }
        return implode("\n", $rows);
    }
}
