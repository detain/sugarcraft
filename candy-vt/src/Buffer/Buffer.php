<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Buffer;

use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Sgr\Sgr;
use SugarCraft\Vt\Hyperlink\Hyperlink;

/**
 * Internal 2D cell grid.
 *
 * Rows are stored as vec-optimized arrays. Buffer is mutable — Screen
 * provides the immutable snapshot via copy-on-write.
 */
final class Buffer
{
    /** @var array<int, array<int, Cell>> */
    private array $grid;

    public function __construct(
        public readonly int $cols,
        public readonly int $rows,
    ) {
        $this->grid = $this->makeGrid($cols, $rows);
    }

    /** Build an empty grid of the given dimensions. */
    private function makeGrid(int $cols, int $rows): array
    {
        $grid = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            for ($c = 0; $c < $cols; $c++) {
                $row[] = Cell::empty();
            }
            $grid[] = $row;
        }
        return $grid;
    }

    /**
     * Resize the grid, preserving existing content.
     * Columns/rows beyond the new bounds are discarded.
     * New cells are filled with empty cells.
     */
    public function resize(int $cols, int $rows): self
    {
        $clone = new self($cols, $rows);

        $maxRows = min($this->rows, $rows);
        $maxCols = min($this->cols, $cols);

        for ($r = 0; $r < $maxRows; $r++) {
            for ($c = 0; $c < $maxCols; $c++) {
                $clone->grid[$r][$c] = $this->grid[$r][$c];
            }
        }

        return $clone;
    }

    public function cell(int $row, int $col): Cell
    {
        if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
            return Cell::empty();
        }
        return $this->grid[$row][$col];
    }

    /**
     * Write a cell at the given position.
     * Out-of-bounds coordinates are clamped silently.
     */
    public function put(int $row, int $col, Cell $cell): void
    {
        if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
            return;
        }
        $this->grid[$row][$col] = $cell;
    }

    /**
     * Iterate all cells in row-major order.
     *
     * @return \Generator<array{row:int, col:int, cell:Cell}>
     */
    public function each(): \Generator
    {
        for ($r = 0; $r < $this->rows; $r++) {
            for ($c = 0; $c < $this->cols; $c++) {
                yield ['row' => $r, 'col' => $c, 'cell' => $this->grid[$r][$c]];
            }
        }
    }

    /**
     * Copy the entire grid for snapshotting.
     *
     * @return array<int, array<int, Cell>>
     */
    public function copy(): array
    {
        return array_map(fn(array $row) => array_map(fn(Cell $c) => $c, $row), $this->grid);
    }
}
