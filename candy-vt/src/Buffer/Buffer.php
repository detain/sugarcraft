<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Buffer;

use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Sgr\Sgr;
use SugarCraft\Vt\Hyperlink\Hyperlink;

/**
 * The one 2D cell grid — shared by the emulator ({@see \SugarCraft\Vt\Handler\ScreenHandler})
 * and the vcr renderer path ({@see \SugarCraft\Vt\Terminal}).
 *
 * Rows are stored as vec-optimized arrays. Buffer is MUTABLE: `put()` writes
 * in place; `resize()` and `clear()` return fresh instances and leave this
 * one untouched. The immutable frame view is Snapshot / copy-on-write, not
 * this class.
 *
 * Tracks a minimal bounding box of cells written since the instance was
 * built ({@see dirtyRegion()}). `resize()`/`clear()` mint a new instance, so
 * their dirty box starts empty even though content carried over.
 */
final class Buffer
{
    /** @var array<int, array<int, Cell>> */
    private array $grid;

    // Dirty-region sentinels: PHP_INT_MAX/-1 mean "no dirty cells yet".
    // Once any cell is written, minRow/minCol become the true minima
    // and maxRow/maxCol become the true maxima.
    private int $minRow = PHP_INT_MAX;
    private int $maxRow = -1;
    private int $minCol = PHP_INT_MAX;
    private int $maxCol = -1;

    public function __construct(
        public readonly int $cols,
        public readonly int $rows,
    ) {
        $this->grid = $this->makeGrid($cols, $rows);
    }

    /**
     * Build an empty grid of the given dimensions.
     *
     * Every pristine slot shares the {@see Cell::empty()} singleton: an empty
     * 320x120 grid allocates 38400 array slots but exactly one cell object
     * (~6 MB saved on the hot emulator resize path). Written slots replace
     * the shared reference with their own immutable value.
     */
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

    /** @return array{minRow:int, maxRow:int, minCol:int, maxCol:int} */
    public function dirtyRegion(): array
    {
        return [
            'minRow' => $this->minRow,
            'maxRow' => $this->maxRow,
            'minCol' => $this->minCol,
            'maxCol' => $this->maxCol,
        ];
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

    /** A fresh empty grid of the same dimensions; this instance is untouched. */
    public function clear(): self
    {
        return new self($this->cols, $this->rows);
    }

    public function cell(int $row, int $col): Cell
    {
        if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
            return Cell::empty();
        }
        return $this->grid[$row][$col];
    }

    /**
     * Write a cell at the given position and extend the dirty bounding box.
     * Out-of-bounds coordinates are ignored silently.
     */
    public function put(int $row, int $col, Cell $cell): void
    {
        if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
            return;
        }
        $this->grid[$row][$col] = $cell;
        $this->minRow = min($this->minRow, $row);
        $this->maxRow = max($this->maxRow, $row);
        $this->minCol = min($this->minCol, $col);
        $this->maxCol = max($this->maxCol, $col);
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
     * Cell-for-cell equality over the whole grid (dimensions must match).
     */
    public function equals(self $other): bool
    {
        if ($this->cols !== $other->cols || $this->rows !== $other->rows) {
            return false;
        }
        for ($r = 0; $r < $this->rows; $r++) {
            for ($c = 0; $c < $this->cols; $c++) {
                if (!$this->grid[$r][$c]->equals($other->grid[$r][$c])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Copy the entire grid for snapshotting.
     *
     * @return array<int, array<int, Cell>>
     */
    public function copy(): array
    {
        return array_map(fn (array $row) => array_map(fn (Cell $c) => $c, $row), $this->grid);
    }
}
