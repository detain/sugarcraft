<?php

declare(strict_types=1);

namespace SugarCraft\Vt;

/**
 * 2D cell grid with dirty-region tracking for the vcr renderer path.
 *
 * Tracks a minimal bounding box of modified cells (dirtyRegion) to
 * enable incremental frame rendering without full-grid scans.
 */
final class CellGrid
{
    /** @var array<int, array<int, Cell>> */
    private array $grid;

    private int $minRow = 0;
    private int $maxRow = 0;
    private int $minCol = 0;
    private int $maxCol = 0;

    public function __construct(
        public readonly int $cols,
        public readonly int $rows,
    ) {
        $this->grid = $this->makeGrid($cols, $rows);
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

    /** @return array<int, array<int, Cell>> */
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

    public function get(int $row, int $col): Cell
    {
        if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
            return Cell::empty();
        }
        return $this->grid[$row][$col];
    }

    public function set(int $row, int $col, Cell $cell): self
    {
        if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
            return $this;
        }

        $grid = array_map(fn (array $r) => $r, $this->grid);
        $grid[$row][$col] = $cell;

        $minRow = $this->minRow > 0 ? min($this->minRow, $row) : $row;
        $maxRow = max($this->maxRow, $row);
        $minCol = $this->minCol > 0 ? min($this->minCol, $col) : $col;
        $maxCol = max($this->maxCol, $col);

        $clone = new self($this->cols, $this->rows);
        $clone->grid = $grid;
        $clone->minRow = $minRow;
        $clone->maxRow = $maxRow;
        $clone->minCol = $minCol;
        $clone->maxCol = $maxCol;

        return $clone;
    }

    public function clear(): self
    {
        return new self($this->cols, $this->rows);
    }

    public function resize(int $cols, int $rows): self
    {
        $newGrid = $this->makeGrid($cols, $rows);

        $maxRows = min($this->rows, $rows);
        $maxCols = min($this->cols, $cols);

        for ($r = 0; $r < $maxRows; $r++) {
            for ($c = 0; $c < $maxCols; $c++) {
                $newGrid[$r][$c] = $this->grid[$r][$c];
            }
        }

        $clone = new self($cols, $rows);
        $clone->grid = $newGrid;

        return $clone;
    }
}
