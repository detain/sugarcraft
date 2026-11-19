<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * A grid of equally sized tiles packed into one image (a trickplay or
 * storyboard sheet), read tile by tile in row-major order.
 *
 * Each {@see tile()} is a crop of the sheet, and {@see ImageSource::crop()}
 * reads its decode through {@see DecodedImageCache}: a sheet of N tiles costs
 * one decode, not N (rank 71). Tile dimensions are the sheet's dimensions
 * divided by the grid, rounded down; any remainder pixels on the right or
 * bottom edge belong to no tile.
 */
final class SpriteSheet
{
    private function __construct(
        public readonly ImageSource $sheet,
        public readonly int $cols,
        public readonly int $rows,
        public readonly int $tileWidth,
        public readonly int $tileHeight,
    ) {}

    /**
     * @throws \InvalidArgumentException when the grid is not at least 1x1 or
     *                                   the sheet is too small for one pixel per tile
     */
    public static function fromSource(ImageSource $sheet, int $cols, int $rows): self
    {
        if ($cols < 1 || $rows < 1) {
            throw new \InvalidArgumentException("sprite sheet grid must be at least 1x1, got {$cols}x{$rows}");
        }

        $tileWidth  = intdiv($sheet->width, $cols);
        $tileHeight = intdiv($sheet->height, $rows);
        if ($tileWidth < 1 || $tileHeight < 1) {
            throw new \InvalidArgumentException(
                "sprite sheet {$sheet->width}x{$sheet->height} cannot hold a {$cols}x{$rows} grid of tiles"
            );
        }

        return new self($sheet, $cols, $rows, $tileWidth, $tileHeight);
    }

    /** Number of tiles in the grid. */
    public function count(): int
    {
        return $this->cols * $this->rows;
    }

    /**
     * Tile $index, row-major from the top-left: 0 is the first cell of the
     * first row, $cols - 1 the last cell of that row.
     *
     * @throws \OutOfRangeException when $index is outside 0 .. count() - 1
     */
    public function tile(int $index): ImageSource
    {
        if ($index < 0 || $index >= $this->count()) {
            throw new \OutOfRangeException("sprite tile {$index} is outside 0.." . ($this->count() - 1));
        }

        $x = ($index % $this->cols) * $this->tileWidth;
        $y = intdiv($index, $this->cols) * $this->tileHeight;

        return $this->sheet->crop($x, $y, $this->tileWidth, $this->tileHeight);
    }
}
